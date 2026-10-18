<?php

use App\Domain\Attendance\CloseAttendanceDay;
use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 5A — API alpa sementara: pengaturan, daftar, verifikasi, penahanan; otorisasi dan ClassScope

beforeEach(function () {
    scanSetUp()->call($this);
    $this->absent = scanStudent($this, 'Budi Santoso', '1002');
    $this->officer = User::factory()->create();
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
    DB::table('schools')->where('id', $this->school->id)->update(['absence_rule_enabled' => true, 'absence_cutoff_time' => '09:00']);

    Carbon::setTestNow(Carbon::parse('2026-10-19 06:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.result', 'accepted');
    Carbon::setTestNow(Carbon::parse('2026-10-19 09:30:00', 'Asia/Jakarta'));
    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'));
});

afterEach(fn () => Carbon::setTestNow());

function absUrl(string $suffix = ''): string
{
    return '/api/v1/schools/sekolah-a/attendance'.$suffix;
}

it('daftar alpa sementara memuat siswa tanpa catatan dan kesehatan hari', function () {
    $this->actingAs($this->officer)->getJson(absUrl('/absences?date=2026-10-19'))->assertOk()
        ->assertJsonPath('data.health.status', 'ok')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.student_name', 'Budi Santoso')
        ->assertJsonPath('data.items.0.class_name', '7A')
        ->assertJsonPath('data.items.0.absence_basis', 'rule');
});

it('verifikasi mengubah menjadi alpa final dengan verifikator, adjustment, dan audit', function () {
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Sudah dicek ke orang tua'])
        ->assertOk()->assertJsonPath('data.verified', 1);

    $this->useSchool($this->school);
    $row = DB::table('daily_attendance')->where('student_id', $this->absent)->first();
    expect($row->attendance_status)->toBe('alpa')->and($row->verified_by_user_id)->toBe($this->officer->id)->and($row->verified_at)->not->toBeNull()
        ->and($row->source)->toBe('manual_adjustment');
    $adj = DB::table('attendance_adjustments')->where('student_id', $this->absent)->first();
    expect($adj->adjustment_type)->toBe('status_change')->and($adj->status)->toBe('approved')->and($adj->decided_by_user_id)->toBe($this->officer->id);
    expect(DB::table('audit_logs')->where('action', 'school.attendance.absence_verified')->count())->toBe(1);

    // Tidak lagi di daftar sementara; verifikasi ulang ditolak.
    $this->actingAs($this->officer)->getJson(absUrl('/absences?date=2026-10-19'))->assertJsonCount(0, 'data.items');
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Sudah dicek ke orang tua'])->assertStatus(409);

    // Penutupan ulang tidak mengubah alpa final.
    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'));
    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->where('student_id', $this->absent)->value('attendance_status'))->toBe('alpa');
});

it('verifikasi massal semua-atau-tidak: satu siswa yang tidak valid membatalkan semuanya', function () {
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), [
        'date' => '2026-10-19', 'student_ids' => [$this->absent, $this->student], 'reason' => 'Verifikasi massal kelas 7A',
    ])->assertStatus(409);

    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->where('student_id', $this->absent)->value('attendance_status'))->toBe('alpa_belum_diverifikasi');
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [(string) Str::uuid()], 'reason' => 'Verifikasi massal kelas 7A'])->assertNotFound();
});

it('validasi: alasan minimal 10 karakter, daftar siswa wajib, tanggal valid', function () {
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'pendek'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [], 'reason' => 'Alasan yang cukup panjang'])
        ->assertUnprocessable()->assertJsonValidationErrors('student_ids');
    $this->actingAs($this->officer)->getJson(absUrl('/absences?date=besok'))->assertUnprocessable();
});

it('wali kelas tanpa kelas ampuan melihat daftar kosong dan tidak dapat memverifikasi (404)', function () {
    $homeroom = User::factory()->create();
    $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);

    $this->actingAs($homeroom)->getJson(absUrl('/absences?date=2026-10-19'))->assertOk()->assertJsonCount(0, 'data.items');
    $this->actingAs($homeroom)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Bukan kelas saya'])->assertNotFound();
});

it('wali kelas memverifikasi kelasnya sendiri, tetapi tidak boleh menahan hari atau mengubah pengaturan', function () {
    $homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);
    $this->inSchool($this->school, function () use ($membership) {
        $teacher = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active']);
        SchoolClass::query()->where('id', $this->classId)->update(['homeroom_teacher_id' => $teacher->id]);
    });

    $this->actingAs($homeroom)->getJson(absUrl('/absences?date=2026-10-19'))->assertJsonCount(1, 'data.items');
    $this->actingAs($homeroom)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Orang tua mengonfirmasi'])->assertOk();

    $this->actingAs($homeroom)->postJson(absUrl('/days/2026-10-19/disruption'), ['reason' => 'Terminal mati sebagian'])->assertForbidden();
    $this->actingAs($homeroom)->putJson(absUrl('/absence-settings'), ['enabled' => false])->assertForbidden();
});

it('penahanan lewat API menarik kembali alpa sementara; pelepasan menjalankannya lagi; 409 bila tidak ditahan', function () {
    $this->actingAs($this->officer)->postJson(absUrl('/days/2026-10-19/release'), ['reason' => 'Tidak sedang ditahan'])->assertStatus(409);

    $this->actingAs($this->officer)->postJson(absUrl('/days/2026-10-19/disruption'), ['reason' => 'pendek'])->assertUnprocessable();
    $this->actingAs($this->officer)->postJson(absUrl('/days/2026-10-19/disruption'), ['reason' => 'Terminal gerbang mati sebagian'])
        ->assertOk()->assertJsonPath('data.status', 'perlu_pemeriksaan');
    $this->actingAs($this->officer)->getJson(absUrl('/absences?date=2026-10-19'))
        ->assertJsonPath('data.health.status', 'perlu_pemeriksaan')->assertJsonPath('data.health.reasons', ['MANUAL'])->assertJsonCount(0, 'data.items');

    $this->actingAs($this->officer)->postJson(absUrl('/days/2026-10-19/release'), ['reason' => 'Sudah diperiksa, terminal normal'])
        ->assertOk()->assertJsonPath('data.marked_absent', 1)->assertJsonPath('data.health', 'dilepas');
    $this->actingAs($this->officer)->getJson(absUrl('/absences?date=2026-10-19'))->assertJsonCount(1, 'data.items');
    $this->actingAs($this->officer)->postJson(absUrl('/days/bukan-tanggal/release'), ['reason' => 'Tanggal tidak valid'])->assertNotFound();
});

it('pengaturan: hanya yang berhak mengubah; cutoff harus setelah batas masuk + toleransi; teraudit', function () {
    $this->actingAs($this->officer)->getJson(absUrl('/absence-settings'))->assertOk()->assertJsonPath('data', ['enabled' => true, 'cutoff_time' => '09:00']);
    $this->actingAs($this->officer)->putJson(absUrl('/absence-settings'), ['enabled' => false])->assertForbidden();

    $this->actingAs($this->admin)->putJson(absUrl('/absence-settings'), ['enabled' => true, 'cutoff_time' => '06:30'])
        ->assertUnprocessable()->assertJsonValidationErrors('cutoff_time');
    $this->actingAs($this->admin)->putJson(absUrl('/absence-settings'), ['enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('cutoff_time');

    $this->actingAs($this->admin)->putJson(absUrl('/absence-settings'), ['enabled' => true, 'cutoff_time' => '10:15'])
        ->assertOk()->assertJsonPath('data', ['enabled' => true, 'cutoff_time' => '10:15']);
    $this->actingAs($this->admin)->putJson(absUrl('/absence-settings'), ['enabled' => false])
        ->assertOk()->assertJsonPath('data', ['enabled' => false, 'cutoff_time' => null]);

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.attendance.absence_settings_updated')->count())->toBe(2);
});

it('tenant lain tidak dapat memverifikasi siswa sekolah ini', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $outsider = User::factory()->create();
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);

    $this->actingAs($outsider)->postJson('/api/v1/schools/sekolah-b/attendance/absences/verify', [
        'date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Lintas sekolah tidak boleh',
    ])->assertNotFound();
    $this->actingAs($outsider)->getJson(absUrl('/absences?date=2026-10-19'))->assertNotFound();
});

it('scan masuk setelah alpa final masuk antrean tinjauan tanpa mengubah status; terima mengubahnya menjadi terlambat', function () {
    $this->actingAs($this->officer)->postJson(absUrl('/absences/verify'), ['date' => '2026-10-19', 'student_ids' => [$this->absent], 'reason' => 'Sudah dicek ke orang tua'])->assertOk();

    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
    scan(scanToken($this, $this->absent))->assertJsonPath('data.result', 'needs_review')->assertJsonPath('data.code', 'ABSENCE_FINAL_CONFLICT');
    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->where('student_id', $this->absent)->value('attendance_status'))->toBe('alpa');

    $queue = $this->actingAs($this->officer)->getJson(absUrl('/review-queue'))->assertJsonCount(1, 'data')->json('data.0');
    expect($queue['event_type'])->toBe('check_in')->and($queue['reason_code'])->toBe('ABSENCE_FINAL_CONFLICT');
    $this->actingAs($this->officer)->postJson(absUrl("/review-queue/{$queue['event_id']}/resolve"), ['decision' => 'accept', 'reason' => 'Siswa terbukti hadir, terlambat'])->assertOk();

    $this->useSchool($this->school);
    $row = DB::table('daily_attendance')->where('student_id', $this->absent)->first();
    expect($row->attendance_status)->toBe('terlambat')->and($row->check_in_event_id)->toBe($queue['event_id'])->and($row->absence_basis)->toBeNull();
});

it('scan masuk setelah alpa sementara menggantikannya otomatis menjadi terlambat', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
    scan(scanToken($this, $this->absent))->assertJsonPath('data.result', 'accepted')->assertJsonPath('data.code', 'CHECK_IN_LATE');
    $this->useSchool($this->school);
    $row = DB::table('daily_attendance')->where('student_id', $this->absent)->first();
    expect($row->attendance_status)->toBe('terlambat')->and($row->absence_basis)->toBeNull();
});
