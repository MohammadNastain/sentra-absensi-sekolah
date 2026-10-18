<?php

use App\Domain\Identity\Role;
use App\Models\Holiday;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 3E — ringkasan harian untuk dashboard

beforeEach(fn () => scanSetUp()->call($this));

afterEach(fn () => Carbon::setTestNow());

function dashToday(?User $as = null)
{
    $t = test();

    return $t->actingAs($as ?? $t->admin)->getJson('/api/v1/schools/sekolah-a/attendance/today');
}

it('sebelum ada scan: semua siswa belum tercatat', function () {
    dashToday()->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.date', '2026-10-19')
        ->assertJsonPath('data.day.kind', 'school_day')
        ->assertJsonPath('data.totals', ['students' => 1, 'present' => 0, 'on_time' => 0, 'late' => 0, 'not_recorded' => 1, 'checked_out' => 0])
        ->assertJsonPath('data.recent', []);
});

it('scan tepat waktu dan terlambat tampil di angka dan transaksi terbaru', function () {
    scan($this->token);
    $second = scanStudent($this, 'Budi Santoso', '1002');
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:20:00', 'Asia/Jakarta'));
    scan(scanToken($this, $second));

    $res = dashToday()->assertOk()
        ->assertJsonPath('data.totals', ['students' => 2, 'present' => 2, 'on_time' => 1, 'late' => 1, 'not_recorded' => 0, 'checked_out' => 0]);
    expect(array_column($res->json('data.recent'), 'student_name'))->toBe(['Budi Santoso', 'Ahmad Rizky Pratama'])
        ->and($res->json('data.recent.0.is_late'))->toBeTrue()
        ->and($res->json('data.recent.0.recorded_at_local'))->toBe('07.20');
});

it('hari libur dan hari tanpa jadwal dilaporkan', function () {
    $this->inSchool($this->school, fn () => Holiday::query()->create(['school_id' => $this->school->id, 'name' => 'Libur Uji', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']));
    dashToday()->assertJsonPath('data.day.kind', 'holiday')->assertJsonPath('data.day.holiday_name', 'Libur Uji');

    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00', 'Asia/Jakarta'));
    dashToday()->assertJsonPath('data.day.kind', 'no_schedule');
});

it('peringatan perangkat: aktif tetapi tidak terlihat dalam dua menit dihitung offline', function () {
    Carbon::setTestNow(); // last_seen_at memakai jam database; bandingkan dengan jam nyata
    $this->useSchool($this->school);
    DB::update("UPDATE devices SET last_seen_at = now() - interval '10 minutes'");
    dashToday()->assertJsonPath('data.devices', ['active' => 1, 'offline' => 1]);

    scanCall('POST', '/heartbeat', [], $this->deviceToken)->assertOk();
    dashToday()->assertJsonPath('data.devices', ['active' => 1, 'offline' => 0]);
});

it('wali kelas hanya melihat kelas yang ia ampu', function () {
    $homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);
    $this->inSchool($this->school, function () use ($membership) {
        $teacher = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active']);
        SchoolClass::query()->where('id', $this->classId)->update(['homeroom_teacher_id' => $teacher->id]);
    });
    $otherClass = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => SchoolClass::query()->value('academic_year_id'), 'name' => '7B', 'grade_level' => 7,
    ])->id);
    $this->classId = $otherClass;
    $other = scanStudent($this, 'Siswa Kelas Lain', '3003');
    scan($this->token);
    scan(scanToken($this, $other));

    dashToday($homeroom)->assertOk()->assertJsonPath('data.totals.students', 1)->assertJsonPath('data.totals.present', 1)
        ->assertJsonCount(1, 'data.recent')->assertJsonPath('data.recent.0.student_name', 'Ahmad Rizky Pratama');
    dashToday()->assertJsonPath('data.totals.students', 2)->assertJsonCount(2, 'data.recent');
});

it('wali kelas tanpa kelas tidak melihat siswa', function () {
    $homeroom = User::factory()->create();
    $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);

    dashToday($homeroom)->assertOk()->assertJsonPath('data.totals.students', 0)->assertJsonPath('data.recent', []);
});

it('izin: petugas piket dan kepala sekolah boleh; operator terminal ditolak', function () {
    foreach ([Role::DutyOfficer, Role::Principal] as $role) {
        $user = User::factory()->create();
        $this->addMember($this->school, $user, [$role]);
        dashToday($user)->assertOk();
    }
    $operator = User::factory()->create();
    $this->addMember($this->school, $operator, [Role::TerminalOperator]);
    dashToday($operator)->assertForbidden();
});

it('sekolah lain tidak terlihat dalam ringkasan', function () {
    scan($this->token);
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $adminB = User::factory()->create();
    $this->addMember($other, $adminB, [Role::SchoolAdmin]);

    $this->actingAs($adminB)->getJson('/api/v1/schools/sekolah-b/attendance/today')->assertOk()
        ->assertJsonPath('data.totals.students', 0)->assertJsonPath('data.recent', []);
    expect(DB::table('attendance_events')->count())->toBe(0); // tanpa konteks sekolah tidak ada baris terlihat
});
