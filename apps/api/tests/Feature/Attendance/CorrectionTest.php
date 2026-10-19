<?php

use App\Domain\Attendance\CloseAttendanceDay;
use App\Domain\Identity\Role;
use App\Models\ClassEnrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 5C — koreksi absensi: langsung, pengajuan wali kelas, keputusan, riwayat, otorisasi

beforeEach(function () {
    scanSetUp()->call($this);
    $this->officer = User::factory()->create();
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
    $this->homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $this->homeroom, [Role::HomeroomTeacher]);
    $this->inSchool($this->school, function () use ($membership) {
        $teacher = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active']);
        SchoolClass::query()->where('id', $this->classId)->update(['homeroom_teacher_id' => $teacher->id]);
    });
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
});

afterEach(fn () => Carbon::setTestNow());

function corrUrl(string $suffix = ''): string
{
    return '/api/v1/schools/sekolah-a/attendance'.$suffix;
}

function corrBody(array $o = []): array
{
    return $o + ['student_id' => test()->student, 'date' => '2026-10-19', 'type' => 'status_change', 'value' => 'hadir', 'reason' => 'Siswa hadir, lupa scan pagi'];
}

function corrDaily(?string $studentId = null, string $date = '2026-10-19'): ?object
{
    $t = test();
    $t->useSchool($t->school);

    return DB::table('daily_attendance')->where('student_id', $studentId ?? $t->student)->where('attendance_date', $date)->first();
}

it('koreksi langsung admin mengubah status, mencatat adjustment + audit, dan hari tanpa baris dimaterialisasi dulu', function () {
    expect(corrDaily())->toBeNull();

    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody())->assertCreated()
        ->assertJsonPath('data.from.attendance_status', 'belum_tercatat')->assertJsonPath('data.to.attendance_status', 'hadir');

    $row = corrDaily();
    expect($row->attendance_status)->toBe('hadir')->and($row->source)->toBe('manual_adjustment')->and($row->class_id)->toBe($this->classId);
    $adj = DB::table('attendance_adjustments')->first();
    expect($adj->adjustment_type)->toBe('status_change')->and($adj->status)->toBe('approved')->and($adj->decided_by_user_id)->toBe($this->admin->id)
        ->and($adj->reason)->toBe('Siswa hadir, lupa scan pagi');
    expect(DB::table('audit_logs')->where('action', 'school.correction.applied')->count())->toBe(1);
});

it('alpa manual terverifikasi penerap dan tidak ditimpa izin otomatis saat penutupan hari', function () {
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['value' => 'alpa', 'reason' => 'Terbukti membolos hari ini']))->assertCreated();
    $row = corrDaily();
    expect($row->attendance_status)->toBe('alpa')->and($row->verified_by_user_id)->toBe($this->admin->id)->and($row->absence_basis)->toBe('manual');

    // Izin disetujui sesudahnya tidak menimpa alpa manual (dasar manual dilindungi).
    $leave = $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/leave-requests', [
        'student_id' => $this->student, 'leave_type' => 'sakit', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'reason' => 'Demam tinggi',
    ])->json('data.id');
    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/leave-requests/{$leave}/decision", ['decision' => 'approve'])->assertOk();
    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'));
    expect(corrDaily()->attendance_status)->toBe('alpa');

    // Dikoreksi menjadi hadir: bersihkan verifikasi dan dasar.
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['value' => 'terlambat']))->assertCreated();
    $row = corrDaily();
    expect($row->attendance_status)->toBe('terlambat')->and($row->verified_by_user_id)->toBeNull()->and($row->absence_basis)->toBeNull()->and($row->late_minutes)->toBe(0);
});

it('validasi koreksi: status sama 409, nilai tidak sesuai jenis 422, alasan pendek 422, hari mendatang 409, tanpa hari sekolah 409', function () {
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['value' => 'belum_tercatat']))->assertUnprocessable();
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['value' => 'approved']))->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['reason' => 'pendek']))->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['date' => '2026-10-20']))->assertStatus(409);
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['date' => '2026-10-17']))->assertStatus(409); // Sabtu: tanpa jadwal
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['student_id' => (string) Str::uuid()]))->assertNotFound();

    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody())->assertCreated();
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody())->assertStatus(409);
});

it('keputusan pulang awal: hanya bila tercatat pulang awal dan masih menunggu', function () {
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['type' => 'early_leave_decision', 'value' => 'approved']))->assertStatus(409);

    Carbon::setTestNow(Carbon::parse('2026-10-19 06:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.code', 'CHECK_IN_OK');
    Carbon::setTestNow(Carbon::parse('2026-10-19 13:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.code', 'CHECK_OUT_EARLY');
    expect(corrDaily()->early_leave_status)->toBe('pending');

    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['type' => 'early_leave_decision', 'value' => 'approved', 'reason' => 'Dijemput orang tua dengan izin']))->assertCreated();
    expect(corrDaily()->early_leave_status)->toBe('approved');
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['type' => 'early_leave_decision', 'value' => 'approved', 'reason' => 'Dijemput orang tua dengan izin']))->assertStatus(409);
});

it('hanya admin yang boleh koreksi langsung; petugas piket dan wali kelas ditolak', function () {
    foreach ([$this->officer, $this->homeroom] as $user) {
        $this->actingAs($user)->postJson(corrUrl('/corrections'), corrBody())->assertForbidden();
    }
});

it('wali kelas mengajukan untuk kelasnya, admin menyetujui: status berubah, adjustment atas nama pengaju', function () {
    $id = $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody())->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_value', 'belum_tercatat')->assertJsonPath('data.requested_value', 'hadir')->json('data.id');

    // Pengajuan belum mengubah apa pun, dan pengajuan ganda yang sama 409.
    expect(corrDaily()->attendance_status)->toBe('belum_tercatat');
    $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody())->assertStatus(409);
    $this->actingAs($this->homeroom)->getJson(corrUrl('/correction-requests?status=pending'))->assertJsonCount(1, 'data');

    $this->actingAs($this->homeroom)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

    expect(corrDaily()->attendance_status)->toBe('hadir');
    $adj = DB::table('attendance_adjustments')->first();
    expect($adj->requested_by_user_id)->toBe($this->homeroom->id)->and($adj->decided_by_user_id)->toBe($this->admin->id);
    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'approve'])->assertStatus(409);
});

it('penolakan wajib beralasan dan tidak mengubah absensi; pengaju dapat membatalkan yang menunggu', function () {
    $id = $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody())->json('data.id');
    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'reject', 'note' => 'Tidak ada bukti kehadiran'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(corrDaily()->attendance_status)->toBe('belum_tercatat');

    $again = $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody())->assertCreated()->json('data.id');
    $this->actingAs($this->homeroom)->postJson(corrUrl("/correction-requests/{$again}/cancel"), ['reason' => 'pendek'])->assertUnprocessable();
    $this->actingAs($this->homeroom)->postJson(corrUrl("/correction-requests/{$again}/cancel"), ['reason' => 'Salah memilih siswa'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$again}/decision"), ['decision' => 'approve'])->assertStatus(409);
});

it('persetujuan gagal 409 bila kondisi berubah sejak pengajuan, dan tidak meninggalkan setengah perubahan', function () {
    $id = $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody())->json('data.id');
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['reason' => 'Admin mengoreksi lebih dulu']))->assertCreated();

    $this->actingAs($this->admin)->postJson(corrUrl("/correction-requests/{$id}/decision"), ['decision' => 'approve'])->assertStatus(409);
    $this->useSchool($this->school);
    expect(DB::table('correction_requests')->where('id', $id)->value('status'))->toBe('pending')->and(DB::table('attendance_adjustments')->count())->toBe(1);
});

it('wali kelas tidak dapat mengajukan atau melihat pengajuan untuk siswa kelas lain', function () {
    $otherClass = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => SchoolClass::query()->find($this->classId)->academic_year_id, 'name' => '8B', 'grade_level' => 8,
    ])->id);
    $stranger = $this->inSchool($this->school, function () use ($otherClass) {
        $s = Student::query()->create(['school_id' => $this->school->id, 'full_name' => 'Kelas Lain', 'student_number' => '3001', 'status' => 'active']);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $s->id, 'class_id' => $otherClass, 'start_date' => '2026-07-13']);

        return $s->id;
    });

    $this->actingAs($this->homeroom)->postJson(corrUrl('/correction-requests'), corrBody(['student_id' => $stranger]))->assertNotFound();
    $this->actingAs($this->admin)->postJson(corrUrl('/correction-requests'), corrBody())->assertCreated();
    $this->actingAs($this->admin)->postJson(corrUrl('/correction-requests'), corrBody(['student_id' => $stranger]))->assertCreated();

    $this->actingAs($this->homeroom)->getJson(corrUrl('/correction-requests'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.class_name', '7A');
    $this->actingAs($this->officer)->getJson(corrUrl('/correction-requests'))->assertForbidden();
});

it('riwayat koreksi: tersaring tanggal/siswa dan hanya untuk peninjau', function () {
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody())->assertCreated();
    $this->actingAs($this->admin)->postJson(corrUrl('/corrections'), corrBody(['value' => 'terlambat', 'reason' => 'Ternyata datang terlambat']))->assertCreated();

    $this->actingAs($this->officer)->getJson(corrUrl('/corrections/history?from=2026-10-01&to=2026-10-31'))->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.to.attendance_status', 'terlambat')->assertJsonPath('data.0.decided_by', $this->admin->name);
    $this->actingAs($this->officer)->getJson(corrUrl('/corrections/history?from=2026-11-01&to=2026-11-30'))->assertJsonCount(0, 'data');
    $this->actingAs($this->officer)->getJson(corrUrl('/corrections/history?from=2026-10-01&to=2026-10-31&student_id='.Str::uuid()))->assertJsonCount(0, 'data');
    $this->actingAs($this->homeroom)->getJson(corrUrl('/corrections/history?from=2026-10-01&to=2026-10-31'))->assertForbidden();
    $this->actingAs($this->officer)->getJson(corrUrl('/corrections/history?from=2026-10-31&to=2026-10-01'))->assertUnprocessable();
});
