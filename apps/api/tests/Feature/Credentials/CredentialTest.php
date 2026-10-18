<?php

use App\Domain\Credentials\CredentialHasher;
use App\Domain\Credentials\QrToken;
use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Tahap 3A — kredensial QR: terbit, terbit ulang, blokir, cabut; token hanya terlihat sekali

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $this->class = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => '7A', 'grade_level' => 7,
    ])->id);
    $this->student = credStudent($this, 'Ahmad Rizky', '1001', true);
});

function credStudent($t, string $name, string $number, bool $enrolled, string $status = 'active'): string
{
    return $t->inSchool($t->school, function () use ($t, $name, $number, $enrolled, $status) {
        $s = Student::query()->create(['school_id' => $t->school->id, 'full_name' => $name, 'student_number' => $number, 'status' => $status]);
        if ($enrolled) {
            ClassEnrollment::query()->create(['school_id' => $t->school->id, 'student_id' => $s->id, 'class_id' => $t->class, 'start_date' => '2026-07-13']);
        }

        return $s->id;
    });
}

function credApi(string $path, string $method = 'getJson', array $data = [], ?User $as = null)
{
    $t = test();

    return $t->actingAs($as ?? $t->admin)->{$method}("/api/v1/schools/sekolah-a{$path}", $data);
}

it('token QR berformat SA1 dan hash tersimpan, bukan nilai mentah', function () {
    $res = credApi("/students/{$this->student}/credentials/qr", 'postJson')->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.credential.status', 'active');
    $token = $res->json('data.token');

    expect(QrToken::looksLikeToken($token))->toBeTrue();
    $this->useSchool($this->school);
    $row = DB::table('student_credentials')->where('student_id', $this->student)->first();
    expect(bin2hex(is_resource($row->value_hash) ? stream_get_contents($row->value_hash) : $row->value_hash))->toBe(app(CredentialHasher::class)->hashHex($token))
        ->and(json_encode(DB::table('student_credentials')->where('student_id', $this->student)->select('id', 'status', 'value_hint', 'status_reason')->first()))->not->toContain($token);
});

it('token acak, tidak berulang, dan tidak memuat data pribadi', function () {
    $tokens = array_map(fn () => QrToken::generate(), range(1, 200));

    expect(count(array_unique($tokens)))->toBe(200);
    foreach ($tokens as $t) {
        expect($t)->toMatch('/^SA1\.[A-Z2-7]{26}$/');
    }
    expect(QrToken::looksLikeToken('  '.strtolower($tokens[0])."\n"))->toBeTrue()
        ->and(QrToken::looksLikeToken('SA1.SHORT'))->toBeFalse()
        ->and(QrToken::looksLikeToken('0012345678'))->toBeFalse();
});

it('terbit ulang mencabut kartu lama dan membuat rantai penggantian', function () {
    $first = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data');
    $second = credApi("/students/{$this->student}/credentials/qr", 'postJson')->assertCreated()->json('data');

    expect($second['token'])->not->toBe($first['token'])
        ->and($second['credential']['replaces_credential_id'])->toBe($first['credential']['id']);

    $list = credApi("/students/{$this->student}/credentials")->assertOk()->assertJsonCount(2, 'data')->json('data');
    $byId = collect($list)->keyBy('id');
    expect($byId[$first['credential']['id']]['status'])->toBe('revoked')
        ->and($byId[$second['credential']['id']]['status'])->toBe('active');
    expect(json_encode($list))->not->toContain($first['token'])->not->toContain($second['token']);
});

it('terbit hanya untuk siswa aktif; siswa tidak dikenal 404', function () {
    $inactive = credStudent($this, 'Sudah Lulus', '2000', false, 'graduated');

    credApi("/students/{$inactive}/credentials/qr", 'postJson')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    credApi('/students/'.fake()->uuid().'/credentials/qr', 'postJson')->assertNotFound();
});

it('terbit massal per kelas: hanya yang belum punya QR, atau semuanya dengan mode all', function () {
    credStudent($this, 'Bunga Lestari', '1002', true);
    credStudent($this, 'Tanpa Kelas', '1003', false);
    credApi("/students/{$this->student}/credentials/qr", 'postJson')->assertCreated();

    $res = credApi("/classes/{$this->class}/credentials/qr", 'postJson')->assertCreated()->assertJsonPath('meta.issued', 1);
    expect($res->json('data.0.student.full_name'))->toBe('Bunga Lestari');

    $again = credApi("/classes/{$this->class}/credentials/qr", 'postJson', ['mode' => 'all'])->assertCreated()->assertJsonPath('meta.issued', 2);
    expect(collect($again->json('data'))->pluck('student.full_name')->sort()->values()->all())->toBe(['Ahmad Rizky', 'Bunga Lestari']);

    credApi("/classes/{$this->class}/credentials/qr", 'postJson', ['mode' => 'hapus'])->assertJsonValidationErrors(['mode']);
    credApi('/classes/'.fake()->uuid().'/credentials/qr', 'postJson')->assertNotFound();
});

it('blokir, buka blokir, dan cabut: status hanya maju dan alasan wajib', function () {
    $id = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data.credential.id');

    credApi("/credentials/{$id}/block", 'postJson', [])->assertJsonValidationErrors(['reason']);
    credApi("/credentials/{$id}/block", 'postJson', ['reason' => 'Kartu hilang di sekolah'])->assertOk()->assertJsonPath('data.status', 'blocked');
    credApi("/credentials/{$id}/block", 'postJson', ['reason' => 'Dobel blokir'])->assertStatus(409);
    credApi("/credentials/{$id}/unblock", 'postJson')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.blocked_at', null);
    credApi("/credentials/{$id}/revoke", 'postJson', ['reason' => 'Kartu rusak parah'])->assertOk()->assertJsonPath('data.status', 'revoked');
    credApi("/credentials/{$id}/unblock", 'postJson')->assertStatus(409);
    credApi("/credentials/{$id}/revoke", 'postJson', ['reason' => 'Dobel cabut'])->assertStatus(409);
});

it('tidak bisa membuka blokir bila siswa sudah punya QR aktif lain', function () {
    $first = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data.credential.id');
    credApi("/credentials/{$first}/block", 'postJson', ['reason' => 'Diblokir dulu'])->assertOk();
    // Terbit ulang mencabut yang diblokir juga, sehingga unblock lama ditolak karena sudah revoked.
    credApi("/students/{$this->student}/credentials/qr", 'postJson')->assertCreated();

    credApi("/credentials/{$first}/unblock", 'postJson')->assertStatus(409);
});

it('audit mencatat terbit, blokir, dan cabut tanpa token maupun hash', function () {
    $res = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data');
    credApi("/credentials/{$res['credential']['id']}/block", 'postJson', ['reason' => 'Kartu hilang di sekolah'])->assertOk();

    $this->useSchool($this->school);
    $logs = DB::table('audit_logs')->whereIn('action', ['school.credential.issued', 'school.credential.blocked'])->get();
    expect($logs)->toHaveCount(2);
    $dump = json_encode($logs);
    expect($dump)->not->toContain($res['token'])->not->toContain('value_hash')->not->toContain(app(CredentialHasher::class)->hashHex($res['token']));
});

it('hanya admin yang mengelola kredensial; kepala sekolah dan wali kelas ditolak', function () {
    foreach ([Role::Principal, Role::HomeroomTeacher, Role::DutyOfficer] as $role) {
        $user = User::factory()->create();
        $this->addMember($this->school, $user, [$role]);

        credApi("/students/{$this->student}/credentials", 'getJson', [], $user)->assertForbidden();
        credApi("/students/{$this->student}/credentials/qr", 'postJson', [], $user)->assertForbidden();
    }
});

it('kredensial sekolah lain tidak terlihat maupun dapat diubah', function () {
    $id = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data.credential.id');
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $admin = User::factory()->create();
    $this->addMember($other, $admin, [Role::SchoolAdmin]);

    $this->actingAs($admin)->postJson("/api/v1/schools/sekolah-b/credentials/{$id}/revoke", ['reason' => 'Percobaan lintas sekolah'])->assertNotFound();
    $this->actingAs($admin)->getJson("/api/v1/schools/sekolah-b/students/{$this->student}/credentials")->assertNotFound();
});

it('kunci HMAC berversi: hash berbeda per versi dan per kunci', function () {
    $hasher = app(CredentialHasher::class);
    expect($hasher->hashHex('SA1.X', 1))->not->toBe($hasher->hashHex('SA1.X', 2))
        ->and($hasher->hashHex('SA1.X', 1))->toBe($hasher->hashHex('SA1.X', 1))
        ->and(strlen($hasher->hashHex('SA1.X')))->toBe(64);
});

it('token mentah tidak masuk log aplikasi', function () {
    Log::spy();
    $token = credApi("/students/{$this->student}/credentials/qr", 'postJson')->json('data.token');

    Log::shouldNotHaveReceived('error');
    expect(file_exists(storage_path('logs/laravel.log')) ? str_contains((string) file_get_contents(storage_path('logs/laravel.log')), $token) : false)->toBeFalse();
});
