<?php

use App\Domain\Attendance\CloseAttendanceDay;
use App\Domain\Identity\Role;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 5B — izin/sakit/dispensasi: pengajuan, keputusan, proyeksi ke harian, lampiran privat, otorisasi

beforeEach(function () {
    scanSetUp()->call($this);
    Storage::fake('local');
    $this->officer = User::factory()->create();
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
    DB::table('schools')->where('id', $this->school->id)->update(['absence_rule_enabled' => true, 'absence_cutoff_time' => '09:00']);
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta')); // Senin, setelah cutoff
});

afterEach(fn () => Carbon::setTestNow());

function leaveUrl(string $suffix = ''): string
{
    return '/api/v1/schools/sekolah-a/leave-requests'.$suffix;
}

function submitLeave(array $override = []): string
{
    $t = test();

    return $t->actingAs($t->admin)->postJson(leaveUrl(), $override + [
        'student_id' => $t->student, 'leave_type' => 'sakit', 'start_date' => '2026-10-19', 'end_date' => '2026-10-20', 'reason' => 'Demam tinggi',
    ])->assertCreated()->json('data.id');
}

function dailyOf(string $studentId, string $date = '2026-10-19'): ?object
{
    $t = test();
    $t->useSchool($t->school);

    return DB::table('daily_attendance')->where('student_id', $studentId)->where('attendance_date', $date)->first();
}

it('mengajukan izin menyimpan status menunggu dan mengaudit; belum mengubah absensi harian', function () {
    $id = submitLeave();

    $this->actingAs($this->officer)->getJson(leaveUrl('?status=pending'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.class_name', '7A')
        ->assertJsonPath('data.0.has_attachment', false);
    expect(dailyOf($this->student))->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'school.leave.requested')->count())->toBe(1);
});

it('validasi pengajuan: tipe, rentang (maks 31 hari), alasan, siswa valid; tumpang tindih ditolak 409', function () {
    $post = fn (array $o) => $this->actingAs($this->admin)->postJson(leaveUrl(), $o + [
        'student_id' => $this->student, 'leave_type' => 'sakit', 'start_date' => '2026-10-19', 'end_date' => '2026-10-20', 'reason' => 'Demam tinggi',
    ]);

    $post(['leave_type' => 'cuti'])->assertUnprocessable()->assertJsonValidationErrors('leave_type');
    $post(['end_date' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $post(['end_date' => '2026-12-31'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $post(['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $post(['student_id' => (string) Str::uuid()])->assertNotFound();

    submitLeave();
    $post(['start_date' => '2026-10-20', 'end_date' => '2026-10-22'])->assertStatus(409);
    $post(['start_date' => '2026-10-21', 'end_date' => '2026-10-22'])->assertCreated();
});

it('persetujuan: hanya yang berhak; tanggal berjalan menjadi sakit, tanggal mendatang menunggu penutupan hari', function () {
    $id = submitLeave();

    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.projected_days', 1);

    $row = dailyOf($this->student);
    expect($row->attendance_status)->toBe('sakit')->and($row->leave_request_id)->toBe($id)->and($row->class_id)->toBe($this->classId);
    expect(dailyOf($this->student, '2026-10-20'))->toBeNull();

    // Besoknya penutupan hari menerapkan izin pada baris yang baru dibuat, sehingga tidak pernah menjadi alpa.
    Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Jakarta'));
    $result = $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-20'));
    expect($result['marked_absent'])->toBe(0);
    expect(dailyOf($this->student, '2026-10-20')->attendance_status)->toBe('sakit');
});

it('izin menggantikan alpa sementara dan alpa final (dengan adjustment), tetapi tidak menimpa siswa yang sudah hadir', function () {
    $present = scanStudent($this, 'Hadir Pagi', '2001');
    $other = scanStudent($this, 'Alpa Final', '2002');
    Carbon::setTestNow(Carbon::parse('2026-10-19 06:30:00', 'Asia/Jakarta'));
    scan(scanToken($this, $present))->assertJsonPath('data.result', 'accepted');
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'));
    expect(dailyOf($this->student)->attendance_status)->toBe('alpa_belum_diverifikasi');

    $this->actingAs($this->officer)->postJson('/api/v1/schools/sekolah-a/attendance/absences/verify', ['date' => '2026-10-19', 'student_ids' => [$other], 'reason' => 'Sudah dicek ke orang tua'])->assertOk();
    expect(dailyOf($other)->attendance_status)->toBe('alpa');

    foreach ([$present, $other, $this->student] as $studentId) {
        $id = submitLeave(['student_id' => $studentId, 'leave_type' => 'izin', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
        $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    }

    expect(dailyOf($present)->attendance_status)->toBe('hadir')->and(dailyOf($present)->leave_request_id)->toBeNull();
    expect(dailyOf($other)->attendance_status)->toBe('izin')->and(dailyOf($this->student)->attendance_status)->toBe('izin');
    $adj = DB::table('attendance_adjustments')->where('student_id', $other)->where('reason', 'Izin yang disetujui menggantikan alpa final')->first();
    expect($adj)->not->toBeNull()->and($adj->status)->toBe('approved');

    // Penutupan ulang idempoten dan tidak menyentuh siswa berizin.
    expect($this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'))['marked_absent'])->toBe(0);
});

it('penolakan wajib beralasan, tidak mengubah absensi; keputusan kedua 409', function () {
    $id = submitLeave();

    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'reject', 'note' => 'Tidak ada surat dokter'])->assertOk()->assertJsonPath('data.status', 'rejected');
    expect(dailyOf($this->student))->toBeNull();
    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertStatus(409);

    // Setelah ditolak, rentang yang sama dapat diajukan kembali.
    submitLeave();
});

it('pembatalan izin disetujui mengembalikan hari ke belum_tercatat; izin menunggu boleh dibatalkan pengaju', function () {
    $id = submitLeave();
    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    expect(dailyOf($this->student)->attendance_status)->toBe('sakit');

    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/cancel"), ['reason' => 'pendek'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/cancel"), ['reason' => 'Siswa ternyata masuk sekolah'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.reverted_days', 1);
    $row = dailyOf($this->student);
    expect($row->attendance_status)->toBe('belum_tercatat')->and($row->leave_request_id)->toBeNull();

    $pending = submitLeave(['start_date' => '2026-11-02', 'end_date' => '2026-11-02']);
    $this->actingAs($this->officer)->postJson(leaveUrl("/{$pending}/cancel"), ['reason' => 'Diajukan keliru oleh petugas'])->assertOk();
});

it('wali kelas: mengajukan hanya untuk kelasnya, tidak boleh memutuskan atau membatalkan izin disetujui', function () {
    $homeroom = User::factory()->create();
    $membership = $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);
    $this->inSchool($this->school, function () use ($membership) {
        $teacher = Teacher::query()->create(['school_id' => $this->school->id, 'full_name' => 'Wali', 'membership_id' => $membership->id, 'status' => 'active']);
        SchoolClass::query()->where('id', $this->classId)->update(['homeroom_teacher_id' => $teacher->id]);
    });
    $otherClass = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => SchoolClass::query()->find($this->classId)->academic_year_id, 'name' => '8B', 'grade_level' => 8,
    ])->id);
    $stranger = $this->inSchool($this->school, function () use ($otherClass) {
        $s = Student::query()->create(['school_id' => $this->school->id, 'full_name' => 'Kelas Lain', 'student_number' => '3001', 'status' => 'active']);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $s->id, 'class_id' => $otherClass, 'start_date' => '2026-07-13']);

        return $s->id;
    });

    $body = ['leave_type' => 'sakit', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'reason' => 'Demam tinggi'];
    $this->actingAs($homeroom)->postJson(leaveUrl(), $body + ['student_id' => $stranger])->assertNotFound();
    $id = $this->actingAs($homeroom)->postJson(leaveUrl(), $body + ['student_id' => $this->student])->assertCreated()->json('data.id');

    $this->actingAs($homeroom)->getJson(leaveUrl())->assertJsonCount(1, 'data');
    $this->actingAs($homeroom)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertForbidden();

    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    $this->actingAs($homeroom)->postJson(leaveUrl("/{$id}/cancel"), ['reason' => 'Ingin membatalkan izin ini'])->assertForbidden();
});

it('lampiran: PDF/JPG/PNG maks 5 MB, disimpan privat, diunduh hanya oleh yang berhak, tipe lain ditolak', function () {
    $id = submitLeave();

    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/attachment"), ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/attachment"), ['file' => UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->actingAs($this->admin)->get(leaveUrl("/{$id}/attachment"))->assertNotFound();

    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/attachment"), ['file' => UploadedFile::fake()->create('surat-dokter.pdf', 100, 'application/pdf')])
        ->assertOk()->assertJsonPath('data.has_attachment', true)->assertJsonPath('data.attachment_name', 'surat-dokter.pdf');
    Storage::disk('local')->assertExists("leave/{$this->school->id}/{$id}.pdf");

    $this->actingAs($this->officer)->get(leaveUrl("/{$id}/attachment"))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

    $outsider = User::factory()->create();
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);
    $this->actingAs($outsider)->get('/api/v1/schools/sekolah-b/leave-requests/'.$id.'/attachment')->assertNotFound();
    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'reject', 'note' => 'Dokumen tidak sesuai'])->assertOk();
    $this->actingAs($this->admin)->postJson(leaveUrl("/{$id}/attachment"), ['file' => UploadedFile::fake()->create('lagi.pdf', 10, 'application/pdf')])->assertStatus(409);
});

it('izin tidak dapat dilihat atau diputuskan dari sekolah lain (RLS)', function () {
    $id = submitLeave();
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $outsider = User::factory()->create();
    $this->addMember($other, $outsider, [Role::SchoolAdmin]);

    $this->actingAs($outsider)->getJson('/api/v1/schools/sekolah-b/leave-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($outsider)->postJson("/api/v1/schools/sekolah-b/leave-requests/{$id}/decision", ['decision' => 'approve'])->assertNotFound();
});

it('pencarian siswa untuk pengajuan dibatasi kelas wali kelas', function () {
    $homeroom = User::factory()->create();
    $this->addMember($this->school, $homeroom, [Role::HomeroomTeacher]);

    $this->actingAs($this->officer)->getJson(leaveUrl('/students?q=Ahmad'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.class_name', '7A');
    $this->actingAs($homeroom)->getJson(leaveUrl('/students?q=Ahmad'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->officer)->getJson(leaveUrl('/students'))->assertUnprocessable();
});

it('scan menang atas izin yang sudah berlaku (D-13): status jadi terlambat dan konflik tercatat di audit', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
    $id = submitLeave(['student_id' => $this->student, 'leave_type' => 'izin', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
    $this->actingAs($this->officer)->postJson(leaveUrl("/{$id}/decision"), ['decision' => 'approve'])->assertOk();
    $this->inSchool($this->school, fn () => app(CloseAttendanceDay::class)->run($this->school, '2026-10-19'));
    expect(dailyOf($this->student)->attendance_status)->toBe('izin');

    Carbon::setTestNow(Carbon::parse('2026-10-19 10:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.result', 'accepted');

    expect(dailyOf($this->student)->attendance_status)->toBe('terlambat');
    $audit = DB::table('audit_logs')->where('action', 'school.attendance.leave_overridden')->first();
    expect($audit)->not->toBeNull()->and($audit->actor_type)->toBe('system')->and(json_decode($audit->before_value, true)['attendance_status'])->toBe('izin');
});
