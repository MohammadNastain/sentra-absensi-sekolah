<?php

namespace Database\Seeders;

use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Data demo untuk pengembangan lokal. Menolak berjalan di production (M04):
 * akun demo memakai password yang diketahui publik.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo-password-123';

    /** Kunci TOTP tetap untuk Super Admin demo: daftarkan ke aplikasi authenticator (input manual). */
    public const SUPER_ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public const SUPER_ADMIN_RECOVERY_CODE = 'demo-recovery-code';

    public function run(DatabaseContext $context): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder tidak boleh dijalankan di production.');
        }

        $this->seedSuperAdmin();

        $context->runAsPlatform(function () use ($context) {
            $school = School::query()->firstOrCreate(
                ['code' => 'demo-smpn-1'],
                ['name' => 'SMP Negeri 1 Demo', 'npsn' => '99999991', 'education_levels' => ['SMP'], 'timezone' => 'Asia/Jakarta', 'status' => 'active'],
            );

            $user = User::query()->firstOrCreate(
                ['email' => 'admin@demo.test'],
                ['name' => 'Admin Demo', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()],
            );

            $context->runInSchool($school->id, function () use ($school, $user) {
                $membership = SchoolMembership::query()->firstOrCreate(
                    ['school_id' => $school->id, 'user_id' => $user->id],
                    ['status' => SchoolMembership::STATUS_ACTIVE],
                );
                DB::table('membership_roles')->insertOrIgnore([
                    'school_id' => $school->id,
                    'membership_id' => $membership->id,
                    'role_code' => Role::SchoolAdmin->value,
                ]);

                $this->seedAcademic($school);
                $this->seedHomeroom($school);
            });
        });
    }

    /** Data akademik contoh (idempoten): satu tahun ajaran aktif, dua guru, tiga kelas. */
    private function seedAcademic(School $school): void
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['school_id' => $school->id, 'name' => '2026/2027'],
            ['start_date' => '2026-07-13', 'end_date' => '2027-06-26', 'is_active' => true],
        );

        $siti = Teacher::query()->firstOrCreate(
            ['school_id' => $school->id, 'employee_number' => '198001012005012001'],
            ['full_name' => 'Siti Rahma', 'status' => Teacher::STATUS_ACTIVE],
        );
        Teacher::query()->firstOrCreate(
            ['school_id' => $school->id, 'employee_number' => '198505052010011002'],
            ['full_name' => 'Budi Santoso', 'status' => Teacher::STATUS_ACTIVE],
        );

        $classes = [];
        foreach ([['7A', 7, $siti->id], ['7B', 7, null], ['8A', 8, null]] as [$name, $grade, $homeroom]) {
            $classes[$name] = SchoolClass::query()->firstOrCreate(
                ['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => $name],
                ['grade_level' => $grade, 'homeroom_teacher_id' => $homeroom, 'status' => SchoolClass::STATUS_ACTIVE],
            );
        }

        $this->seedStudents($school, $classes);
        $this->seedCalendar($school);
    }

    /** Akun wali kelas demo (wali@demo.test) yang terhubung ke guru Siti Rahma, wali kelas 7A. */
    private function seedHomeroom(School $school): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'wali@demo.test'],
            ['name' => 'Siti Rahma', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()],
        );
        $membership = SchoolMembership::query()->firstOrCreate(
            ['school_id' => $school->id, 'user_id' => $user->id],
            ['status' => SchoolMembership::STATUS_ACTIVE],
        );
        DB::table('membership_roles')->insertOrIgnore([
            'school_id' => $school->id,
            'membership_id' => $membership->id,
            'role_code' => Role::HomeroomTeacher->value,
        ]);
        Teacher::query()->where('employee_number', '198001012005012001')->whereNull('membership_id')->update(['membership_id' => $membership->id]);
    }

    /** Jadwal sekolah Senin–Jumat dan satu hari libur contoh (idempoten). */
    private function seedCalendar(School $school): void
    {
        if (! Schedule::query()->where('school_id', $school->id)->exists()) {
            foreach ([1, 2, 3, 4, 5] as $day) {
                Schedule::query()->create([
                    'school_id' => $school->id, 'scope_type' => Schedule::SCOPE_SCHOOL, 'day_of_week' => $day,
                    'check_in_start' => '06:00', 'check_in_deadline' => '07:00', 'late_grace_minutes' => 10,
                    'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => '2026-07-13',
                ]);
            }
        }

        Holiday::query()->firstOrCreate(
            ['school_id' => $school->id, 'name' => 'Hari Pahlawan', 'start_date' => '2026-11-10'],
            ['end_date' => '2026-11-10'],
        );
    }

    /**
     * Siswa contoh dengan penempatan kelas (idempoten).
     *
     * @param  array<string, SchoolClass>  $classes
     */
    private function seedStudents(School $school, array $classes): void
    {
        $roster = [
            ['1001', 'Ahmad Rizky', '7A'], ['1002', 'Bunga Lestari', '7A'], ['1003', 'Candra Wijaya', '7A'],
            ['1004', 'Dewi Anggraini', '7B'], ['1005', 'Eko Prasetyo', '7B'], ['1006', 'Fitri Handayani', '8A'],
        ];

        foreach ($roster as [$number, $name, $class]) {
            $student = Student::query()->firstOrCreate(
                ['school_id' => $school->id, 'student_number' => $number],
                ['full_name' => $name, 'status' => Student::STATUS_ACTIVE],
            );
            ClassEnrollment::query()->firstOrCreate(
                ['school_id' => $school->id, 'student_id' => $student->id],
                ['class_id' => $classes[$class]->id, 'start_date' => '2026-07-13'],
            );
        }
    }

    /** Super Admin demo (superadmin@demo.test) dengan 2FA aktif; dibutuhkan untuk menguji area /platform. */
    private function seedSuperAdmin(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'superadmin@demo.test'],
            ['name' => 'Super Admin Demo', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()],
        );

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => encrypt(self::SUPER_ADMIN_TOTP_SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode([self::SUPER_ADMIN_RECOVERY_CODE])),
            'two_factor_confirmed_at' => now(),
        ]);
        DB::table('platform_admins')->insertOrIgnore(['user_id' => $user->id]);
    }
}
