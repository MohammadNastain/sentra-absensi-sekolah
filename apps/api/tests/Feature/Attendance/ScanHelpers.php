<?php

use App\Domain\Credentials\Actions\ManageCredentials;
use App\Domain\Identity\Role;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\Schedule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Fixture dan pemanggil bersama untuk tes scan (ScanTest, ScanConcurrencyTest).

/** Dijalankan terikat ke instance tes (`scanSetUp()->call($this)`) agar method protected seperti addMember() terjangkau. */
function scanSetUp(): Closure
{
    return function (): void {
        $t = $this;
        Carbon::setTestNow(Carbon::parse('2026-10-19 07:00:00', 'Asia/Jakarta')); // Senin
        $t->school = School::factory()->create(['code' => 'sekolah-a', 'timezone' => 'Asia/Jakarta']);
        $t->admin = User::factory()->create();
        $t->addMember($t->school, $t->admin, [Role::SchoolAdmin]);
        $year = $t->inSchool($t->school, fn () => AcademicYear::query()->create([
            'school_id' => $t->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
        ]));
        $t->classId = $t->inSchool($t->school, fn () => SchoolClass::query()->create([
            'school_id' => $t->school->id, 'academic_year_id' => $year->id, 'name' => '7A', 'grade_level' => 7,
        ])->id);
        $t->inSchool($t->school, function () use ($t) {
            foreach ([1, 2, 3, 4, 5] as $day) {
                Schedule::query()->create([
                    'school_id' => $t->school->id, 'scope_type' => 'school', 'day_of_week' => $day, 'check_in_start' => '06:00',
                    'check_in_deadline' => '07:00', 'check_out_start' => '13:00', 'scheduled_check_out' => '14:00', 'effective_from' => '2026-07-13',
                ]);
            }
        });
        $t->student = scanStudent($t, 'Ahmad Rizky Pratama', '1001');
        $t->token = scanToken($t, $t->student);

        $created = $t->actingAs($t->admin)->postJson('/api/v1/schools/sekolah-a/devices', ['name' => 'Gerbang'])->json('data');
        $pair = scanCall('POST', '/pair', ['code' => $created['pairing_code']]);
        $t->deviceToken = $pair->getCookie(AuthenticateDevice::COOKIE, false)->getValue();
        app('auth')->forgetGuards();
    };
}

function scanStudent($t, string $name, string $number, bool $enroll = true): string
{
    return $t->inSchool($t->school, function () use ($t, $name, $number, $enroll) {
        $s = Student::query()->create(['school_id' => $t->school->id, 'full_name' => $name, 'student_number' => $number, 'status' => 'active']);
        if ($enroll) {
            ClassEnrollment::query()->create(['school_id' => $t->school->id, 'student_id' => $s->id, 'class_id' => $t->classId, 'start_date' => '2026-07-13']);
        }

        return $s->id;
    });
}

function scanToken($t, string $studentId): string
{
    return app(ManageCredentials::class)->issueQr($t->school, $t->admin, $studentId)['token'];
}

function scanCall(string $method, string $path, array $data = [], ?string $device = null)
{
    $cookies = $device === null ? [] : [AuthenticateDevice::COOKIE => $device];

    return test()->call($method, "/api/v1/terminal{$path}", [], $cookies, [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SENTRA_TERMINAL' => '1',
    ], $method === 'GET' ? null : json_encode($data));
}

function scan(string $value, ?string $key = null)
{
    return scanCall('POST', '/scans', ['value' => $value, 'idempotency_key' => $key ?? (string) Str::uuid()], test()->deviceToken);
}
