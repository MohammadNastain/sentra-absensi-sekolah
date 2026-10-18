<?php

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 4C — antrean needs_review, resolusi (attendance_adjustments), laporan anomali bantuan

beforeEach(function () {
    scanSetUp()->call($this);
    $this->officer = User::factory()->create();
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
    Carbon::setTestNow(Carbon::parse('2026-10-19 14:10:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.result', 'needs_review');
});

afterEach(fn () => Carbon::setTestNow());

function queueUrl(string $suffix = ''): string
{
    return '/api/v1/schools/sekolah-a/attendance/review-queue'.$suffix;
}

it('antrean memuat event needs_review dan hanya untuk yang berizin tinjau', function () {
    $this->actingAs($this->officer)->getJson(queueUrl())->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason_code', 'CHECKOUT_WITHOUT_CHECKIN')->assertJsonPath('data.0.class_name', '7A');

    $teacher = User::factory()->create();
    $this->addMember($this->school, $teacher, [Role::HomeroomTeacher]);
    $this->actingAs($teacher)->getJson(queueUrl())->assertForbidden();
});

it('accept: mencatat pulang di harian, menulis adjustment append-only dan audit; event tidak berubah', function () {
    $eventId = $this->actingAs($this->officer)->getJson(queueUrl())->json('data.0.event_id');

    $this->actingAs($this->officer)->postJson(queueUrl("/{$eventId}/resolve"), ['decision' => 'accept', 'reason' => 'Siswa hadir, lupa scan masuk'])
        ->assertOk()->assertJsonPath('data.decision', 'accept');

    $this->useSchool($this->school);
    $daily = DB::table('daily_attendance')->first();
    expect($daily->attendance_status)->toBe('hadir')->and($daily->source)->toBe('manual_adjustment')->and($daily->check_out_event_id)->toBe($eventId)
        ->and(DB::table('attendance_events')->where('id', $eventId)->value('result'))->toBe('needs_review');
    $adj = DB::table('attendance_adjustments')->first();
    expect($adj->status)->toBe('approved')->and($adj->adjustment_type)->toBe('review_resolution')->and($adj->decided_by_user_id)->toBe($this->officer->id);
    expect(DB::table('audit_logs')->where('action', 'school.attendance.review_resolved')->count())->toBe(1);

    expect(fn () => DB::transaction(fn () => DB::update("UPDATE attendance_adjustments SET reason = 'diubah paksa ya'")))->toThrow(QueryException::class);
    $this->actingAs($this->officer)->getJson(queueUrl())->assertJsonCount(0, 'data');
});

it('reject: tidak mengubah harian tetapi tercatat; tidak dapat diselesaikan dua kali', function () {
    $eventId = $this->actingAs($this->officer)->getJson(queueUrl())->json('data.0.event_id');

    $this->actingAs($this->officer)->postJson(queueUrl("/{$eventId}/resolve"), ['decision' => 'reject', 'reason' => 'Scan tidak valid, diabaikan'])->assertOk();
    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->value('attendance_status'))->toBe('belum_tercatat')
        ->and(DB::table('attendance_adjustments')->value('status'))->toBe('rejected');

    $this->actingAs($this->officer)->postJson(queueUrl("/{$eventId}/resolve"), ['decision' => 'accept', 'reason' => 'Coba lagi dua kali'])
        ->assertStatus(409);
});

it('alasan minimal 10 karakter, keputusan valid, event lain/tidak ada 404', function () {
    $eventId = $this->actingAs($this->officer)->getJson(queueUrl())->json('data.0.event_id');

    $this->actingAs($this->officer)->postJson(queueUrl("/{$eventId}/resolve"), ['decision' => 'accept', 'reason' => 'singkat'])->assertStatus(422);
    $this->actingAs($this->officer)->postJson(queueUrl("/{$eventId}/resolve"), ['decision' => 'mungkin', 'reason' => 'Alasan yang cukup panjang'])->assertStatus(422);
    $this->actingAs($this->officer)->postJson(queueUrl('/'.Str::uuid().'/resolve'), ['decision' => 'accept', 'reason' => 'Alasan yang cukup panjang'])->assertNotFound();
});

it('laporan bantuan: ringkasan per petugas, siswa, dan alasan pada rentang tanggal', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:00:00', 'Asia/Jakarta'));
    $body = fn (string $reason) => ['student_id' => $this->student, 'reason_code' => $reason, 'verified' => true, 'idempotency_key' => (string) Str::uuid()];
    $this->actingAs($this->officer)->postJson('/api/v1/schools/sekolah-a/attendance/assisted', $body('card_forgotten'))->assertOk();
    $this->actingAs($this->officer)->postJson('/api/v1/schools/sekolah-a/attendance/assisted', $body('early_leave'))->assertOk();

    $res = $this->actingAs($this->officer)->getJson('/api/v1/schools/sekolah-a/attendance/assist-report?from=2026-10-19&to=2026-10-19')->assertOk();
    expect($res->json('data.total'))->toBe(2)
        ->and($res->json('data.by_officer.0'))->toEqual(['officer_name' => $this->officer->name, 'total' => 2, 'check_outs' => 1])
        ->and($res->json('data.by_student.0.total'))->toBe(2)
        ->and(collect($res->json('data.by_reason'))->pluck('total', 'reason_code')->all())->toEqual(['card_forgotten' => 1, 'early_leave' => 1]);

    $this->actingAs($this->officer)->getJson('/api/v1/schools/sekolah-a/attendance/assist-report?from=2026-10-20&to=2026-10-25')->assertOk()->assertJsonPath('data.total', 0);
    $this->actingAs($this->officer)->getJson('/api/v1/schools/sekolah-a/attendance/assist-report?from=2026-10-25&to=2026-10-20')->assertStatus(422);
});
