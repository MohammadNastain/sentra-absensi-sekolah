<?php

use App\Domain\Attendance\RecordScanAction;
use App\Domain\Credentials\Actions\ManageCredentials;
use App\Models\Device;
use App\Models\Holiday;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 3C — pipeline scan: QR → catatan masuk/pulang, idempotensi, penolakan, append-only

beforeEach(fn () => scanSetUp()->call($this));

afterEach(fn () => Carbon::setTestNow());

it('check-in tepat waktu tercatat hadir dengan nama tampilan ringkas', function () {
    $res = scan($this->token)->assertOk()
        ->assertJsonPath('data.result', 'accepted')
        ->assertJsonPath('data.code', 'CHECK_IN_OK')
        ->assertJsonPath('data.student.display_name', 'Ahmad P.')
        ->assertJsonPath('data.student.class_name', '7A');
    expect($res->json('data.message'))->toContain('07.00');

    $this->useSchool($this->school);
    $daily = DB::table('daily_attendance')->first();
    expect($daily->attendance_status)->toBe('hadir')->and($daily->check_in_event_id)->not->toBeNull();
});

it('check-in setelah toleransi tercatat terlambat dengan menit', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:20:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.code', 'CHECK_IN_LATE');

    $this->useSchool($this->school);
    $daily = DB::table('daily_attendance')->first();
    expect($daily->attendance_status)->toBe('terlambat')->and($daily->late_minutes)->toBe(20);
});

it('scan kedua adalah duplikat dengan waktu catatan pertama, bukan check-out', function () {
    scan($this->token)->assertJsonPath('data.code', 'CHECK_IN_OK');
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:03:00', 'Asia/Jakarta'));

    scan($this->token)->assertOk()->assertJsonPath('data.result', 'duplicate')->assertJsonPath('data.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('data.recorded_at_local', '07.03');
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->count())->toBe(1)
        ->and(DB::table('attendance_events')->count())->toBe(2);
});

it('check-out pulang awal dan normal; setelahnya duplikat', function () {
    scan($this->token);
    Carbon::setTestNow(Carbon::parse('2026-10-19 13:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.code', 'CHECK_OUT_EARLY');
    $this->useSchool($this->school);
    $daily = DB::table('daily_attendance')->first();
    expect($daily->early_leave)->toBeTrue()->and($daily->early_leave_status)->toBe('pending')->and($daily->check_out_event_id)->not->toBeNull();

    scan($this->token)->assertJsonPath('data.code', 'ALREADY_CHECKED_OUT');
});

it('terlalu awal ditolak dan tidak dihitung hadir', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 05:30:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.result', 'rejected')->assertJsonPath('data.code', 'TOO_EARLY');
    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->where('attendance_status', '<>', 'belum_tercatat')->count())->toBe(0)->and(DB::table('attendance_events')->where('result', 'accepted')->count())->toBe(0);
});

it('pulang tanpa check-in masuk antrean tinjauan, bukan dihitung hadir', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 14:10:00', 'Asia/Jakarta'));
    scan($this->token)->assertJsonPath('data.result', 'needs_review')->assertJsonPath('data.code', 'CHECKOUT_WITHOUT_CHECKIN');
    $this->useSchool($this->school);
    expect(DB::table('daily_attendance')->where('attendance_status', 'hadir')->count())->toBe(0);
});

it('kunci idempotensi yang sama mengembalikan hasil yang sama tanpa catatan baru', function () {
    $key = (string) Str::uuid();
    $first = scan($this->token, $key)->json('data');
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:30:00', 'Asia/Jakarta'));
    $second = scan($this->token, $key)->assertOk()->json('data');

    expect($second)->toEqual($first);
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->count())->toBe(1);
});

it('kartu tidak dikenal, diblokir, siswa nonaktif, libur, dan tanpa jadwal ditolak', function () {
    scan('SA1.'.str_repeat('A', 26))->assertJsonPath('data.code', 'UNKNOWN_CREDENTIAL')->assertJsonPath('data.student', null);
    scan('bukan-kartu')->assertJsonPath('data.code', 'UNKNOWN_CREDENTIAL');

    $this->useSchool($this->school);
    $cred = (string) DB::table('student_credentials')->where('student_id', $this->student)->value('id');
    app(ManageCredentials::class)->block($this->school, $this->admin, $cred, 'Kartu hilang');
    scan($this->token)->assertJsonPath('data.code', 'CREDENTIAL_BLOCKED')->assertJsonPath('data.student', null);
    app(ManageCredentials::class)->unblock($this->school, $this->admin, $cred, null);

    $this->inSchool($this->school, fn () => Student::query()->where('id', $this->student)->update(['status' => 'inactive']));
    scan($this->token)->assertJsonPath('data.code', 'STUDENT_INACTIVE');
    $this->inSchool($this->school, fn () => Student::query()->where('id', $this->student)->update(['status' => 'active']));

    $this->inSchool($this->school, fn () => Holiday::query()->create(['school_id' => $this->school->id, 'name' => 'Libur Uji', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']));
    scan($this->token)->assertJsonPath('data.code', 'HOLIDAY');

    Carbon::setTestNow(Carbon::parse('2026-10-18 07:00:00', 'Asia/Jakarta')); // Minggu
    scan($this->token)->assertJsonPath('data.code', 'NO_SCHEDULE');
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->count())->toBe(0);
});

it('siswa tanpa kelas aktif ditolak', function () {
    $loose = scanStudent($this, 'Tanpa Kelas', '2002', false);
    scan(scanToken($this, $loose))->assertJsonPath('data.code', 'STUDENT_INACTIVE');
});

it('tanpa token perangkat atau tanpa kunci idempotensi, scan ditolak', function () {
    scanCall('POST', '/scans', ['value' => $this->token, 'idempotency_key' => (string) Str::uuid()])->assertStatus(401);
    scanCall('POST', '/scans', ['value' => $this->token], $this->deviceToken)->assertStatus(422);
});

it('event bersifat append-only dan hanya satu check-in accepted per siswa per hari', function () {
    scan($this->token);
    $this->useSchool($this->school);

    expect(fn () => DB::transaction(fn () => DB::update("UPDATE attendance_events SET result = 'rejected'")))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::delete('DELETE FROM attendance_events')))->toThrow(QueryException::class);

    $e = DB::table('attendance_events')->first();
    expect(fn () => DB::transaction(fn () => DB::insert(
        "INSERT INTO attendance_events (school_id, student_id, attendance_session_id, attendance_date, event_type, method, result, occurred_at_server, idempotency_key, response_snapshot)
         VALUES (?, ?, ?, ?, 'check_in', 'qr', 'accepted', now(), ?, '{}')",
        [$this->school->id, $this->student, $e->attendance_session_id, $e->attendance_date, (string) Str::uuid()],
    )))->toThrow(QueryException::class, 'attendance_events_one_accepted');
});

it('scan beruntun dari kartu yang sama hanya menghasilkan satu catatan masuk', function () {
    $action = app(RecordScanAction::class);
    $device = $this->inSchool($this->school, fn () => Device::query()->first());
    $results = [];
    for ($i = 0; $i < 6; $i++) {
        $results[] = $action->record($this->school, $device, $this->token, (string) Str::uuid(), null)['result'];
    }

    expect(array_count_values($results))->toBe(['accepted' => 1, 'duplicate' => 5]);
});

it('nilai token mentah tidak muncul di event tersimpan', function () {
    scan($this->token);
    scan('SA1.'.str_repeat('B', 26));

    $this->useSchool($this->school);
    $dump = json_encode(DB::table('attendance_events')->select('reason_code', 'response_snapshot', 'metadata')->get());
    expect($dump)->not->toContain($this->token)->not->toContain(str_repeat('B', 26));
});

it('daftar transaksi terakhir hanya memuat nama tampilan', function () {
    scan($this->token);
    $res = scanCall('GET', '/recent', [], $this->deviceToken)->assertOk();

    expect($res->json('data.0.display_name'))->toBe('Ahmad P.')->and($res->json('data.0.code'))->toBe('CHECK_IN_OK');
    expect($res->getContent())->not->toContain($this->token)->not->toContain('Pratama');
});

it('jadwal yang sudah dipakai sesi tidak bisa diubah jamnya, tetapi bisa dinonaktifkan', function () {
    scan($this->token);
    $this->useSchool($this->school);
    $id = DB::table('schedules')->where('day_of_week', 1)->value('id');

    expect(fn () => DB::transaction(fn () => DB::update("UPDATE schedules SET check_in_deadline = '08:00' WHERE id = ?", [$id])))->toThrow(QueryException::class);
    expect(DB::update('UPDATE schedules SET is_active = false WHERE id = ?', [$id]))->toBe(1);
});
