<?php

use App\Domain\Attendance\AssistSessions;
use App\Domain\Identity\Role;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\RequireAssistSession;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 4B — mode bantuan petugas: sesi di terminal, pencarian minimal, pencatatan bantuan, pulang awal

beforeEach(function () {
    scanSetUp()->call($this);
    $this->officer = User::factory()->create(['email' => 'piket@contoh.test']);
    $this->addMember($this->school, $this->officer, [Role::DutyOfficer]);
});

afterEach(fn () => Carbon::setTestNow());

function assistCall(string $method, string $path, array $data = [], ?string $session = null)
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SENTRA_TERMINAL' => '1'];
    if ($session !== null) {
        $server['HTTP_X_ASSIST_SESSION'] = $session;
    }

    return test()->call($method, "/api/v1/terminal/assist{$path}", $method === 'GET' ? $data : [], [AuthenticateDevice::COOKIE => test()->deviceToken], [], $server, $method === 'GET' ? null : json_encode($data));
}

function loginOfficer(?string $email = 'piket@contoh.test', ?string $password = null, ?string $code = null)
{
    return assistCall('POST', '/session', array_filter(['email' => $email, 'password' => $password ?? UserFactory::PASSWORD, 'code' => $code]));
}

function assistRecord(string $session, array $overrides = [])
{
    return assistCall('POST', '/attendance', $overrides + [
        'student_id' => test()->student, 'reason_code' => 'card_forgotten', 'verified' => true, 'idempotency_key' => (string) Str::uuid(),
    ], $session);
}

it('petugas berwenang membuka sesi; token hanya hash di database dan login diaudit', function () {
    $res = loginOfficer()->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.officer_name', $this->officer->name)->assertJsonPath('data.idle_seconds', 600);
    $token = $res->json('data.token');

    $this->useSchool($this->school);
    $row = DB::table('assist_sessions')->first();
    expect(bin2hex(is_resource($row->token_hash) ? stream_get_contents($row->token_hash) : $row->token_hash))->toBe(hash('sha256', $token))
        ->and(DB::table('audit_logs')->where('action', 'school.assist_session.started')->count())->toBe(1)
        ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain($token)->not->toContain(UserFactory::PASSWORD);
});

it('semua kegagalan login petugas memberi jawaban yang sama', function () {
    $nonStaff = User::factory()->create(['email' => 'kepala@contoh.test']);
    $this->addMember($this->school, $nonStaff, [Role::Principal]); // tanpa izin membantu
    $outsider = User::factory()->create(['email' => 'luar@contoh.test']);

    foreach ([
        loginOfficer('piket@contoh.test', 'salah-salah'),
        loginOfficer('tidak-ada@contoh.test'),
        loginOfficer('kepala@contoh.test'),
        loginOfficer('luar@contoh.test'),
    ] as $res) {
        $res->assertStatus(401)->assertJsonPath('code', 'ASSIST_LOGIN_FAILED');
    }
    expect($outsider->exists)->toBeTrue();
});

it('akun dengan 2FA wajib menyertakan kode yang benar', function () {
    $user = User::factory()->withConfirmedTwoFactor()->create(['email' => 'dua@contoh.test']);
    $this->addMember($this->school, $user, [Role::DutyOfficer]);

    loginOfficer('dua@contoh.test')->assertStatus(401);
    loginOfficer('dua@contoh.test', null, '000000')->assertStatus(401);
    $code = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
    loginOfficer('dua@contoh.test', null, $code)->assertOk();
});

it('login petugas dibatasi 5 percobaan per menit per perangkat', function () {
    RateLimiter::clear('terminal-assist:'.sha1($this->deviceToken));
    foreach (range(1, 5) as $i) {
        loginOfficer('piket@contoh.test', 'salah')->assertStatus(401);
    }
    loginOfficer('piket@contoh.test', 'salah')->assertStatus(429);
});

it('tanpa sesi, dengan token acak, atau setelah logout semua endpoint bantuan ditolak', function () {
    assistCall('GET', '/students', ['q' => 'Ahmad'])->assertStatus(401)->assertJsonPath('code', 'ASSIST_SESSION_INVALID');
    assistCall('GET', '/students', ['q' => 'Ahmad'], 'token-acak')->assertStatus(401);
    assistRecord('token-acak')->assertStatus(401);

    $token = loginOfficer()->json('data.token');
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertOk();
    assistCall('DELETE', '/session', [], $token)->assertOk();
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertStatus(401);
});

it('sesi berakhir setelah idle 10 menit dan setelah batas 30 menit; aktivitas memperpanjang idle', function () {
    $token = loginOfficer()->json('data.token');

    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertOk(); // memperpanjang idle
    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertOk();
    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertOk();
    Carbon::setTestNow(Carbon::now()->addMinutes(4)); // total 31 menit > batas maksimum
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertStatus(401);

    $token2 = loginOfficer()->json('data.token');
    Carbon::setTestNow(Carbon::now()->addMinutes(11));
    assistCall('GET', '/students', ['q' => 'Ahmad'], $token2)->assertStatus(401);
    $this->useSchool($this->school);
    expect(DB::table('assist_sessions')->pluck('ended_reason')->sort()->values()->all())->toBe(['expired', 'idle']);
});

it('sesi baru di perangkat yang sama mengakhiri sesi lama', function () {
    $first = loginOfficer()->json('data.token');
    $second = loginOfficer()->json('data.token');

    assistCall('GET', '/students', ['q' => 'Ahmad'], $first)->assertStatus(401);
    assistCall('GET', '/students', ['q' => 'Ahmad'], $second)->assertOk();
});

it('pencarian minimal: hanya field yang perlu, minimal 2 karakter, siswa aktif berkelas', function () {
    $token = loginOfficer()->json('data.token');
    scanStudent($this, 'Tanpa Kelas Siswa', '9009', false);

    $res = assistCall('GET', '/students', ['q' => 'ahmad'], $token)->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and(array_keys($res->json('data.0')))->toBe(['id', 'full_name', 'student_number', 'class_name'])
        ->and($res->json('data.0.class_name'))->toBe('7A');
    assistCall('GET', '/students', ['q' => '1001'], $token)->assertJsonCount(1, 'data');
    assistCall('GET', '/students', ['q' => 'a'], $token)->assertOk()->assertJsonCount(0, 'data');
    assistCall('GET', '/students', ['q' => 'Tanpa Kelas'], $token)->assertJsonCount(0, 'data');
});

it('kartu tertinggal: tercatat masuk dengan metode assisted_manual, petugas, perangkat, dan audit', function () {
    $token = loginOfficer()->json('data.token');

    assistRecord($token)->assertOk()->assertJsonPath('data.result', 'accepted')->assertJsonPath('data.code', 'CHECK_IN_OK');

    $this->useSchool($this->school);
    $event = DB::table('attendance_events')->where('result', 'accepted')->first();
    expect($event->method)->toBe('assisted_manual')->and($event->created_by_user_id)->toBe($this->officer->id)
        ->and($event->assist_reason_code)->toBe('card_forgotten')->and($event->device_id)->not->toBeNull();
    $audit = DB::table('audit_logs')->where('action', 'school.attendance.assisted')->first();
    expect($audit)->not->toBeNull()->and($audit->actor_user_id)->toBe($this->officer->id);
    expect(DB::table('daily_attendance')->value('attendance_status'))->toBe('hadir');
});

it('verifikasi visual wajib, alasan dari enum, catatan wajib untuk other', function () {
    $token = loginOfficer()->json('data.token');

    assistRecord($token, ['verified' => false])->assertStatus(422)->assertJsonValidationErrors(['verified']);
    assistRecord($token, ['reason_code' => 'iseng'])->assertStatus(422)->assertJsonValidationErrors(['reason_code']);
    assistRecord($token, ['reason_code' => 'other'])->assertStatus(422)->assertJsonValidationErrors(['note']);
    assistRecord($token, ['reason_code' => 'other', 'note' => 'Kartu dipinjam teman'])->assertOk();
});

it('pulang awal oleh petugas: boleh sebelum jendela pulang, ditandai pending; tanpa catatan masuk ditolak', function () {
    $token = loginOfficer()->json('data.token');

    assistRecord($token, ['reason_code' => 'early_leave'])->assertOk()->assertJsonPath('data.code', 'CHECKIN_REQUIRED')->assertJsonPath('data.result', 'rejected');

    scan($this->token)->assertJsonPath('data.code', 'CHECK_IN_OK');
    Carbon::setTestNow(Carbon::parse('2026-10-19 07:08:00', 'Asia/Jakarta'));
    assistRecord($token, ['reason_code' => 'early_leave', 'note' => 'Sakit'])->assertOk()->assertJsonPath('data.code', 'CHECK_OUT_EARLY')->assertJsonPath('data.event_type', 'check_out');

    $this->useSchool($this->school);
    $daily = DB::table('daily_attendance')->first();
    expect($daily->early_leave)->toBeTrue()->and($daily->early_leave_status)->toBe('pending')->and($daily->check_out_event_id)->not->toBeNull();

    assistRecord($token, ['reason_code' => 'early_leave'])->assertJsonPath('data.code', 'ALREADY_CHECKED_OUT');
});

it('kunci idempotensi yang sama tidak menggandakan pencatatan bantuan', function () {
    $token = loginOfficer()->json('data.token');
    $key = (string) Str::uuid();

    $first = assistRecord($token, ['idempotency_key' => $key])->json('data');
    $second = assistRecord($token, ['idempotency_key' => $key])->json('data');
    expect($second)->toEqual($first);
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->count())->toBe(1);
});

it('dashboard: petugas piket dan admin boleh; kepala sekolah dan wali kelas ditolak; tanpa perangkat', function () {
    $body = ['student_id' => $this->student, 'reason_code' => 'card_damaged', 'verified' => true, 'idempotency_key' => (string) Str::uuid()];

    $this->actingAs($this->officer)->getJson('/api/v1/schools/sekolah-a/attendance/assist/students?q=ahmad')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->officer)->postJson('/api/v1/schools/sekolah-a/attendance/assisted', $body)->assertOk()->assertJsonPath('data.code', 'CHECK_IN_OK');
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->value('device_id'))->toBeNull();

    foreach ([Role::Principal, Role::HomeroomTeacher] as $role) {
        $user = User::factory()->create();
        $this->addMember($this->school, $user, [$role]);
        $this->actingAs($user)->postJson('/api/v1/schools/sekolah-a/attendance/assisted', $body)->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/schools/sekolah-a/attendance/assist/students?q=ahmad')->assertForbidden();
    }
});

it('siswa sekolah lain tidak dapat dicatat lewat bantuan', function () {
    $token = loginOfficer()->json('data.token');
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = $this->inSchool($other, fn () => Student::query()->create(['school_id' => $other->id, 'full_name' => 'Asing', 'student_number' => '7', 'status' => 'active'])->id);

    assistRecord($token, ['student_id' => $foreign])->assertNotFound();
});

it('perangkat yang dicabut menutup mode bantuan juga', function () {
    $token = loginOfficer()->json('data.token');
    $this->useSchool($this->school);
    DB::update("UPDATE devices SET status = 'revoked', revoked_at = now(), revoked_reason = 'uji', token_hash = token_hash, pairing_code_hash = NULL");

    assistCall('GET', '/students', ['q' => 'Ahmad'], $token)->assertStatus(403)->assertJsonPath('code', 'DEVICE_REVOKED');
});

it('konstanta sesi sesuai arsitektur', function () {
    expect(AssistSessions::IDLE_SECONDS)->toBe(600)->and(AssistSessions::MAX_SECONDS)->toBe(1800)
        ->and(RequireAssistSession::HEADER)->toBe('X-Assist-Session');
});
