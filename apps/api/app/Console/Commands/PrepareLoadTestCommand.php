<?php

namespace App\Console\Commands;

use App\Domain\Credentials\Actions\ManageCredentials;
use App\Domain\Devices\Actions\ManageDevices;
use App\Domain\Devices\PairDevice;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Menyiapkan data uji beban jam masuk (k6, infra/k6/): sekolah `lt-NN`, siswa berkartu RFID, dan perangkat
 * terpasang. Hasilnya berkas JSON berisi token perangkat dan UID kartu yang dibaca skrip k6. Idempoten per kode
 * sekolah: sekolah `lt-NN` yang sudah ada dilewati. Event bersifat
 * append-only, jadi jalankan hanya pada database khusus uji beban (bangun ulang dengan `migrate:fresh`).
 */
final class PrepareLoadTestCommand extends Command
{
    protected $signature = 'loadtest:prepare
        {--schools=3 : Jumlah sekolah serentak (S)}
        {--students=500 : Siswa per sekolah}
        {--devices=2 : Terminal per sekolah}
        {--out=storage/app/loadtest.json : Berkas keluaran (relatif ke base path)}';

    protected $description = 'Siapkan data uji beban k6 (TIDAK untuk production)';

    public function handle(DatabaseContext $context, ManageCredentials $credentials, ManageDevices $devices, PairDevice $pairing): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('loadtest:prepare tidak boleh dijalankan di production.');
        }

        $out = [];
        foreach (range(1, (int) $this->option('schools')) as $n) {
            $code = sprintf('lt-%02d', $n);
            $this->info("Menyiapkan {$code}…");
            $out[] = $this->prepareSchool($context, $credentials, $devices, $pairing, $code, $n);
        }

        $path = base_path((string) $this->option('out'));
        file_put_contents($path, json_encode(['schools' => array_values(array_filter($out))], JSON_PRETTY_PRINT));
        $this->info("Selesai: {$path}");

        return self::SUCCESS;
    }

    /** @return array{code: string, admin: array{email: string, password: string}, devices: list<string>, cards: list<string>}|null */
    private function prepareSchool(DatabaseContext $context, ManageCredentials $credentials, ManageDevices $devices, PairDevice $pairing, string $code, int $n): ?array
    {
        $students = (int) $this->option('students');
        $deviceCount = (int) $this->option('devices');

        $school = $context->runAsPlatform(fn () => School::query()->where('code', $code)->first());
        if ($school !== null) {
            $this->warn("{$code} sudah ada; dilewati (pakai database baru untuk membuat ulang).");

            return null;
        }

        $school = $context->runAsPlatform(fn () => School::query()->forceCreate([
            'code' => $code, 'name' => "Sekolah Uji Beban {$n}", 'npsn' => sprintf('9990%04d', $n), 'education_levels' => ['SMP'],
            'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ]));
        DB::table('schools')->where('id', $school->id)->update(['device_limit' => max(1, min(50, $deviceCount))]);
        $context->runAsPlatform(fn () => DB::table('school_subscriptions')->insert(['school_id' => $school->id, 'plan_code' => 'legacy', 'status' => 'active']));

        $password = bin2hex(random_bytes(12));
        $admin = User::query()->where('email', "{$code}@loadtest.test")->first()
            ?? User::query()->forceCreate(['email' => "{$code}@loadtest.test", 'name' => "Admin {$code}", 'password' => Hash::make($password), 'email_verified_at' => now()]);

        $context->runInSchool($school->id, function () use ($school, $admin, $students) {
            $membership = SchoolMembership::query()->forceCreate(['school_id' => $school->id, 'user_id' => $admin->id, 'status' => SchoolMembership::STATUS_ACTIVE]);
            DB::table('membership_roles')->insert(['school_id' => $school->id, 'membership_id' => $membership->id, 'role_code' => Role::SchoolAdmin->value]);

            $year = AcademicYear::query()->forceCreate(['school_id' => $school->id, 'name' => 'Uji Beban', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31']);
            $class = SchoolClass::query()->forceCreate(['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => '7A', 'grade_level' => 7]);
            // Jendela masuk seharian agar uji bisa dijalankan kapan saja; jendela pulang dibuka paling akhir.
            foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
                Schedule::query()->forceCreate([
                    'school_id' => $school->id, 'scope_type' => 'school', 'day_of_week' => $day, 'check_in_start' => '00:00',
                    'check_in_deadline' => '23:00', 'check_out_start' => '23:30', 'scheduled_check_out' => '23:45', 'effective_from' => '2020-01-01',
                ]);
            }
            for ($i = 1; $i <= $students; $i++) {
                $student = Student::query()->forceCreate(['school_id' => $school->id, 'full_name' => "Siswa Uji {$i}", 'student_number' => (string) (100000 + $i), 'status' => 'active']);
                ClassEnrollment::query()->forceCreate(['school_id' => $school->id, 'student_id' => $student->id, 'class_id' => $class->id, 'start_date' => '2020-01-01']);
            }
        });

        $cards = [];
        $ids = $context->runInSchool($school->id, fn () => Student::query()->where('school_id', $school->id)->orderBy('student_number')->pluck('id')->all());
        foreach ($ids as $i => $studentId) {
            $uid = strtoupper(sprintf('%02X%06X', $n, $i + 1)).'AA';
            $credentials->registerRfid($school, $admin, $studentId, $uid);
            $cards[] = $uid;
        }

        $tokens = [];
        for ($d = 1; $d <= $deviceCount; $d++) {
            $created = $devices->register($school, $admin, "Terminal uji {$d}");
            $tokens[] = $pairing->claim($created['pairing_code'])['token'];
        }

        return ['code' => $code, 'admin' => ['email' => $admin->email, 'password' => $password], 'devices' => $tokens, 'cards' => $cards];
    }
}
