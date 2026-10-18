<?php

use App\Domain\Identity\Role;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Tahap 3B — perangkat terminal: daftar → pairing → guard device → heartbeat → cabut

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a', 'name' => 'SMP Contoh']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function devApi(string $path = '', string $method = 'getJson', array $data = [], ?User $as = null)
{
    $t = test();

    return $t->actingAs($as ?? $t->admin)->{$method}("/api/v1/schools/sekolah-a/devices{$path}", $data);
}

function term(string $path, string $method = 'getJson', array $data = [], ?string $token = null, bool $header = true)
{
    $t = test();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($header) {
        $server['HTTP_X_SENTRA_TERMINAL'] = '1';
    }
    $cookies = $token === null ? [] : [AuthenticateDevice::COOKIE => $token];
    $verb = strtoupper(str_replace('Json', '', $method));

    // Tanpa actingAs/header/cookie "lengket" milik test: setiap panggilan mandiri.
    return $t->call($verb, "/api/v1/terminal{$path}", [], $cookies, [], $server, $verb === 'GET' ? null : json_encode($data));
}

/** @return array{id: string, token: string} */
function pairedDevice(string $name = 'Gerbang Utama'): array
{
    $created = devApi('', 'postJson', ['name' => $name])->assertCreated();
    $pair = term('/pair', 'postJson', ['code' => $created->json('data.pairing_code')])->assertOk();

    return ['id' => $created->json('data.device.id'), 'token' => $pair->getCookie(AuthenticateDevice::COOKIE, false)->getValue()];
}

it('admin mendaftarkan perangkat dan menerima kode pairing sekali, tersimpan sebagai hash', function () {
    $res = devApi('', 'postJson', ['name' => 'Gerbang Utama'])->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.device.status', 'pending_pairing')
        ->assertJsonPath('data.device.online', false);
    $code = $res->json('data.pairing_code');

    expect($code)->toMatch('/^[A-HJKMNP-Z2-9]{8}$/');
    $this->useSchool($this->school);
    expect(json_encode(DB::table('devices')->select('id', 'name', 'status')->get()))->not->toContain($code);

    devApi()->assertOk()->assertJsonCount(1, 'data');
    expect(json_encode(devApi()->json()))->not->toContain($code);
});

it('pairing menerbitkan token di cookie HttpOnly SameSite=Strict; kode sekali pakai', function () {
    $code = devApi('', 'postJson', ['name' => 'Gerbang'])->json('data.pairing_code');

    $pair = term('/pair', 'postJson', ['code' => strtolower(substr($code, 0, 4).'-'.substr($code, 4))])->assertOk()
        ->assertJsonPath('data.school.name', 'SMP Contoh');
    $cookie = $pair->getCookie(AuthenticateDevice::COOKIE, false);
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and(strlen($cookie->getValue()))->toBeGreaterThanOrEqual(43)
        ->and($pair->getContent())->not->toContain($cookie->getValue());

    term('/pair', 'postJson', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'PAIRING_CODE_INVALID');

    $this->useSchool($this->school);
    $row = DB::table('devices')->first();
    expect($row->status)->toBe('active')->and($row->pairing_code_hash)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'school.device.paired')->exists())->toBeTrue();
});

it('kode pairing salah, kedaluwarsa, atau tanpa header terminal ditolak seragam', function () {
    $code = devApi('', 'postJson', ['name' => 'Gerbang'])->json('data.pairing_code');

    term('/pair', 'postJson', ['code' => 'SALAH123'])->assertStatus(422)->assertJsonPath('code', 'PAIRING_CODE_INVALID');
    term('/pair', 'postJson', ['code' => $code], null, false)->assertStatus(400)->assertJsonPath('code', 'TERMINAL_REQUEST_REJECTED');

    $this->useSchool($this->school);
    DB::update("UPDATE devices SET pairing_expires_at = now() - interval '1 minute'");
    term('/pair', 'postJson', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'PAIRING_CODE_INVALID');
});

it('kode pairing yang kedaluwarsa dapat diperbarui; kode lama tidak berlaku lagi', function () {
    $created = devApi('', 'postJson', ['name' => 'Gerbang'])->json('data');
    $old = $created['pairing_code'];

    $new = devApi("/{$created['device']['id']}/pairing-code", 'postJson')->assertOk()->json('data.pairing_code');
    expect($new)->not->toBe($old);
    term('/pair', 'postJson', ['code' => $old])->assertStatus(422);
    term('/pair', 'postJson', ['code' => $new])->assertOk();

    devApi("/{$created['device']['id']}/pairing-code", 'postJson')->assertStatus(409);
});

it('guard device: tanpa header, tanpa cookie, token acak ditolak; token valid memberi konteks sekolah', function () {
    $device = pairedDevice();

    term('/config', 'getJson', [], $device['token'], false)->assertStatus(400);
    term('/config')->assertStatus(401)->assertJsonPath('code', 'DEVICE_UNPAIRED');
    term('/config', 'getJson', [], 'token-acak-yang-tidak-dikenal')->assertStatus(401)->assertJsonPath('code', 'DEVICE_UNPAIRED');

    term('/config', 'getJson', [], $device['token'])->assertOk()
        ->assertJsonPath('data.school.name', 'SMP Contoh')
        ->assertJsonPath('data.device.name', 'Gerbang Utama')
        ->assertJsonPath('data.methods', ['qr']);
    term('/heartbeat', 'postJson', [], $device['token'])->assertOk()->assertJsonStructure(['data' => ['server_time']]);
});

it('token perangkat tidak berlaku untuk API dashboard dan sesi admin tidak berlaku untuk terminal', function () {
    $device = pairedDevice();

    app('auth')->forgetGuards();
    $this->call('GET', '/api/v1/schools/sekolah-a/devices', [], [AuthenticateDevice::COOKIE => $device['token']], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
    term('/config')->assertStatus(401); // sesi admin tidak berlaku untuk terminal
});

it('pencabutan langsung efektif: DEVICE_REVOKED dan kode tidak bisa dipakai', function () {
    $device = pairedDevice();
    term('/config', 'getJson', [], $device['token'])->assertOk();

    devApi("/{$device['id']}/revoke", 'postJson', [])->assertJsonValidationErrors(['reason']);
    devApi("/{$device['id']}/revoke", 'postJson', ['reason' => 'Perangkat hilang'])->assertOk()->assertJsonPath('data.status', 'revoked');

    term('/config', 'getJson', [], $device['token'])->assertStatus(403)->assertJsonPath('code', 'DEVICE_REVOKED');
    term('/heartbeat', 'postJson', [], $device['token'])->assertStatus(403)->assertJsonPath('code', 'DEVICE_REVOKED');
    devApi("/{$device['id']}/revoke", 'postJson', ['reason' => 'Dobel cabut'])->assertStatus(409);
});

it('perangkat belum dipasangkan yang dicabut tidak bisa dipairing', function () {
    $created = devApi('', 'postJson', ['name' => 'Gerbang'])->json('data');
    devApi("/{$created['device']['id']}/revoke", 'postJson', ['reason' => 'Salah daftar'])->assertOk();

    term('/pair', 'postJson', ['code' => $created['pairing_code']])->assertStatus(422);
});

it('batas jumlah perangkat per sekolah (D-30); mencabut membebaskan slot', function () {
    $first = pairedDevice();
    devApi('', 'postJson', ['name' => 'Kedua'])->assertStatus(409)->assertJsonPath('code', 'DEVICE_LIMIT_REACHED');

    devApi("/{$first['id']}/revoke", 'postJson', ['reason' => 'Diganti perangkat baru'])->assertOk();
    devApi('', 'postJson', ['name' => 'Pengganti'])->assertCreated();

    DB::update("UPDATE schools SET device_limit = 3 WHERE code = 'sekolah-a'");
    devApi('', 'postJson', ['name' => 'Ketiga'])->assertCreated();
});

it('sekolah suspended menutup terminal (D-22)', function () {
    $device = pairedDevice();
    DB::update("UPDATE schools SET status = 'suspended' WHERE code = 'sekolah-a'");

    term('/config', 'getJson', [], $device['token'])->assertStatus(403)->assertJsonPath('code', 'SCHOOL_UNAVAILABLE');
});

it('last_seen_at diperbarui dengan pembatasan dan menentukan status online', function () {
    $device = pairedDevice();
    $this->useSchool($this->school);
    DB::update("UPDATE devices SET last_seen_at = now() - interval '10 minutes'");
    devApi()->assertJsonPath('data.0.online', false);

    term('/heartbeat', 'postJson', [], $device['token'])->assertOk();
    devApi()->assertJsonPath('data.0.online', true);

    $this->useSchool($this->school);
    $first = DB::table('devices')->value('last_seen_at');
    term('/heartbeat', 'postJson', [], $device['token'])->assertOk();
    $this->useSchool($this->school);
    expect(DB::table('devices')->value('last_seen_at'))->toBe($first); // dalam 30 detik: tidak ditulis ulang
});

it('perangkat sekolah lain tidak terlihat atau dapat dicabut; non-admin ditolak', function () {
    $device = pairedDevice();
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $adminB = User::factory()->create();
    $this->addMember($other, $adminB, [Role::SchoolAdmin]);

    $this->actingAs($adminB)->postJson("/api/v1/schools/sekolah-b/devices/{$device['id']}/revoke", ['reason' => 'Percobaan lintas sekolah'])->assertNotFound();
    $this->actingAs($adminB)->getJson('/api/v1/schools/sekolah-b/devices')->assertOk()->assertJsonCount(0, 'data');

    foreach ([Role::Principal, Role::HomeroomTeacher, Role::DutyOfficer] as $role) {
        $user = User::factory()->create();
        $this->addMember($this->school, $user, [$role]);
        devApi('', 'getJson', [], $user)->assertForbidden();
        devApi('', 'postJson', ['name' => 'X'], $user)->assertForbidden();
    }
});

it('nama wajib diisi dan perubahan nama diaudit tanpa rahasia', function () {
    devApi('', 'postJson', ['name' => '   '])->assertJsonValidationErrors(['name']);
    $device = pairedDevice();

    devApi("/{$device['id']}", 'patchJson', ['name' => 'Gerbang Belakang'])->assertOk()->assertJsonPath('data.name', 'Gerbang Belakang');
    $this->useSchool($this->school);
    $dump = json_encode(DB::table('audit_logs')->where('entity_type', 'device')->get());
    expect($dump)->not->toContain($device['token'])->not->toContain('token_hash')->not->toContain('pairing_code');
});

it('token device hanya hash di database', function () {
    $device = pairedDevice();
    $this->useSchool($this->school);
    $row = DB::table('devices')->where('id', $device['id'])->first();
    $hash = is_resource($row->token_hash) ? stream_get_contents($row->token_hash) : $row->token_hash;

    expect(bin2hex($hash))->toBe(hash('sha256', $device['token']));
    expect(Device::query()->find($device['id'])->toArray())->not->toHaveKey('token_hash');
});
