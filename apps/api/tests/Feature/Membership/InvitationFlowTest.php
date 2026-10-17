<?php

use App\Domain\Identity\Role;
use App\Mail\InvitationMail;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// I01–I05, T05–T07 (undangan) — TAHAP_1_FONDASI §8

beforeEach(function () {
    Mail::fake();
    $this->school = School::factory()->create(['code' => 'sekolah-a', 'name' => 'SMP Contoh A']);
    $this->admin = User::factory()->create(['email' => 'admin@contoh.test', 'name' => 'Admin A']);
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function invite(string $email = 'guru@contoh.test', array $roles = ['homeroom_teacher'], string $school = 'sekolah-a')
{
    return test()->actingAs(test()->admin)->postJson("/api/v1/schools/{$school}/invitations", ['email' => $email, 'roles' => $roles]);
}

function acceptPayload(array $extra = []): array
{
    return ['name' => 'Guru Baru', 'password' => 'kata-sandi-baru-123', 'password_confirmation' => 'kata-sandi-baru-123', ...$extra];
}

function guestRequest()
{
    return test()->asGuest();
}

function invTable(): Builder
{
    test()->useSchool(test()->school);

    return DB::table('membership_invitations');
}

it('I01: undangan email baru mengirim email dan DB hanya menyimpan hash token', function () {
    invite()->assertCreated()
        ->assertJsonPath('data.email', 'guru@contoh.test')
        ->assertJsonPath('data.roles', ['homeroom_teacher'])
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.delivery_status', 'handed_off')
        ->assertJsonMissingPath('data.token');

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('guru@contoh.test')
        && str_contains($m->url, '/invitations/')
        && ! str_contains($m->url, 'sekolah-a'));

    $token = $this->lastInvitationToken();
    $this->useSchool($this->school);
    $row = DB::selectOne('SELECT encode(token_hash, \'hex\') AS hex, octet_length(token_hash) AS len FROM membership_invitations');

    expect($token)->toHaveLength(43)
        ->and($row->len)->toBe(32)
        ->and($row->hex)->toBe(hash('sha256', $token))
        ->and(json_encode(invTable()->select(MembershipInvitation::COLUMNS)->get()))->not->toContain($token);
});

it('I01: kegagalan transport menandai delivery_status failed, bukan handed_off', function () {
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

    invite()->assertCreated()
        ->assertJsonPath('data.delivery_status', 'failed')
        ->assertJsonPath('data.delivery_error', fn (string $e) => str_contains($e, 'SMTP down'));
});

it('503 EMAIL_NOT_CONFIGURED di production dengan mailer log, tanpa membuat undangan', function () {
    config(['mail.default' => 'log']);
    config(['app.env' => 'production']);

    invite()->assertStatus(503)->assertJsonPath('code', 'EMAIL_NOT_CONFIGURED');
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x@contoh.test'])
        ->assertStatus(503)->assertJsonPath('code', 'EMAIL_NOT_CONFIGURED');

    expect(invTable()->count())->toBe(0);
});

it('I02: terima undangan membuat user + keanggotaan + peran + audit dalam satu transaksi', function () {
    invite('guru@contoh.test', ['homeroom_teacher', 'duty_officer']);
    $token = $this->lastInvitationToken();

    guestRequest()->getJson("/api/v1/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.school.name', 'SMP Contoh A')
        ->assertJsonPath('data.email', 'g***@contoh.test')
        ->assertJsonPath('data.account_exists', false);

    $this->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())
        ->assertCreated()
        ->assertJsonPath('data.account_created', true)
        ->assertJsonPath('data.school.code', 'sekolah-a');

    $user = User::query()->where('email', 'guru@contoh.test')->firstOrFail();
    expect($user->name)->toBe('Guru Baru')->and($user->email_verified_at)->not->toBeNull();

    $this->dbContext()->setSchool($this->school->id);
    $membership = SchoolMembership::query()->where('user_id', $user->id)->with('roles')->firstOrFail();
    expect($membership->status)->toBe('active')
        ->and(array_map(fn ($r) => $r->value, $membership->roleEnums()))->toBe(['duty_officer', 'homeroom_teacher'])
        ->and(invTable()->value('accepted_user_id'))->toBe($user->id);

    $this->useSchool($this->school);
    $actions = DB::table('audit_logs')->where('school_id', $this->school->id)->pluck('action')->all();
    expect($actions)->toContain('school.invitation.created', 'user.account.created', 'school.member.joined', 'school.invitation.accepted');

    // Akun baru dapat langsung login dengan password yang ia tetapkan.
    guestRequest()->postJson('/api/v1/auth/login', ['email' => 'guru@contoh.test', 'password' => 'kata-sandi-baru-123'])->assertOk();
});

it('I02: password lemah/ konfirmasi salah menggagalkan penerimaan tanpa membuat apa pun', function (array $override) {
    invite();
    $token = $this->lastInvitationToken();

    guestRequest()->postJson("/api/v1/invitations/{$token}/accept", acceptPayload($override))->assertUnprocessable();

    expect(User::query()->where('email', 'guru@contoh.test')->exists())->toBeFalse()
        ->and(invTable()->whereNotNull('accepted_at')->count())->toBe(0);
})->with([
    'terlalu pendek' => [['password' => 'pendek', 'password_confirmation' => 'pendek']],
    'konfirmasi beda' => [['password_confirmation' => 'lain-lain-lain-123']],
    'tanpa nama' => [['name' => '']],
]);

it('I03: token tidak dikenal, kedaluwarsa, dicabut, atau sudah dipakai menghasilkan respons yang sama', function () {
    invite('a@contoh.test');
    $expired = $this->lastInvitationToken();
    invite('b@contoh.test');
    $revoked = $this->lastInvitationToken();
    invite('c@contoh.test');
    $used = $this->lastInvitationToken();

    $revokedId = invTable()->where('email', 'b@contoh.test')->value('id');
    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$revokedId}/revoke")->assertOk();

    guestRequest()->postJson("/api/v1/invitations/{$used}/accept", acceptPayload(['name' => 'Cici']))->assertCreated();

    invTable()->where('email', 'a@contoh.test')->update(['expires_at' => now()->subMinute()]);

    $bodies = [];
    foreach ([$expired, $revoked, $used, str_repeat('A', 43), 'bukan-token'] as $token) {
        Cache::flush(); // batas laju publik (10/menit) diuji terpisah di bawah
        foreach (['getJson' => "/api/v1/invitations/{$token}", 'postJson' => "/api/v1/invitations/{$token}/accept"] as $verb => $url) {
            $response = guestRequest()->{$verb}($url, $verb === 'postJson' ? acceptPayload() : [])
                ->assertStatus(410)->assertJsonPath('code', 'INVITATION_UNAVAILABLE');
            $bodies[] = array_diff_key($response->json(), ['request_id' => 1, 'instance' => 1]);
        }
    }

    expect(array_unique(array_map('serialize', $bodies)))->toHaveCount(1);
});

it('I04: email sudah terdaftar wajib login sebagai email itu', function () {
    $existing = User::factory()->create(['email' => 'guru@contoh.test']);
    $other = User::factory()->create(['email' => 'lain@contoh.test']);
    invite('guru@contoh.test');
    $token = $this->lastInvitationToken();

    guestRequest()->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.account_exists', true);

    // Belum login.
    guestRequest()->postJson("/api/v1/invitations/{$token}/accept")
        ->assertUnauthorized()->assertJsonPath('code', 'LOGIN_REQUIRED');

    // Login sebagai user lain.
    guestRequest()->actingAs($other)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertForbidden()->assertJsonPath('code', 'INVITATION_EMAIL_MISMATCH');

    // Login sebagai pemilik email: tanpa nama/password.
    guestRequest()->actingAs($existing)->postJson("/api/v1/invitations/{$token}/accept")
        ->assertCreated()->assertJsonPath('data.account_created', false);

    expect(User::query()->where('email', 'guru@contoh.test')->count())->toBe(1);
    $this->dbContext()->setSchool($this->school->id);
    expect(SchoolMembership::query()->where('user_id', $existing->id)->value('status'))->toBe('active');
});

it('I04: user yang login dapat melihat, menerima, dan menolak undangan lewat /me/invitations', function () {
    $user = User::factory()->create(['email' => 'guru@contoh.test']);
    invite('guru@contoh.test');
    $second = School::factory()->create(['code' => 'sekolah-b', 'name' => 'SMP B']);
    $adminB = User::factory()->create();
    $this->addMember($second, $adminB, [Role::SchoolAdmin]);
    guestRequest()->actingAs($adminB)->postJson('/api/v1/schools/sekolah-b/invitations', ['email' => 'guru@contoh.test', 'roles' => ['principal']])->assertCreated();

    guestRequest()->actingAs($user);
    $list = $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(2, 'data');
    $ids = collect($list->json('data'))->keyBy('school.name');

    $this->postJson("/api/v1/me/invitations/{$ids['SMP Contoh A']['id']}/accept")->assertCreated();
    $this->postJson("/api/v1/me/invitations/{$ids['SMP B']['id']}/decline")->assertNoContent();

    $this->getJson('/api/v1/me/invitations')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/me/schools')->assertJsonCount(1, 'data')->assertJsonPath('data.0.school.code', 'sekolah-a');
    $this->postJson("/api/v1/me/invitations/{$ids['SMP B']['id']}/accept")->assertStatus(410);
});

it('I04: user tidak dapat melihat atau menerima undangan milik email lain', function () {
    invite('guru@contoh.test');
    $stranger = User::factory()->create(['email' => 'orang@contoh.test']);
    $id = invTable()->value('id');

    $this->actingAs($stranger)->getJson('/api/v1/me/invitations')->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/me/invitations/{$id}/accept")->assertNotFound();
    $this->postJson("/api/v1/me/invitations/{$id}/decline")->assertNotFound();
});

it('I05: satu undangan tertunda per email per sekolah; undangan kedaluwarsa digantikan otomatis', function () {
    invite('guru@contoh.test')->assertCreated();
    invite('GURU@contoh.test')->assertConflict()->assertJsonPath('code', 'INVITATION_ALREADY_PENDING');

    $this->travel(8)->days();
    $this->flushSession(); // sesi lama sudah melewati batas 12 jam
    invite('guru@contoh.test')->assertCreated();

    expect(invTable()->count())->toBe(2)
        ->and(invTable()->whereNull('revoked_at')->whereNull('accepted_at')->count())->toBe(1);
});

it('I05: setelah dicabut, email yang sama dapat diundang lagi', function () {
    invite()->assertCreated();
    $id = invTable()->value('id');
    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$id}/revoke")->assertConflict();

    invite()->assertCreated();
});

it('menolak mengundang email yang sudah menjadi anggota aktif', function () {
    invite('ADMIN@contoh.test')->assertConflict()->assertJsonPath('code', 'ALREADY_MEMBER');
});

it('mengundang kembali anggota yang sudah berakhir mengaktifkan lagi keanggotaan dengan peran baru', function () {
    $user = User::factory()->create(['email' => 'mantan@contoh.test']);
    $this->addMember($this->school, $user, [Role::Principal], SchoolMembership::STATUS_ENDED);

    invite('mantan@contoh.test', ['duty_officer'])->assertCreated();
    $token = $this->lastInvitationToken();
    guestRequest()->actingAs($user)->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();

    $this->dbContext()->setSchool($this->school->id);
    $membership = SchoolMembership::query()->where('user_id', $user->id)->with('roles')->firstOrFail();
    expect($membership->status)->toBe('active')->and($membership->ended_at)->toBeNull()
        ->and(array_map(fn ($r) => $r->value, $membership->roleEnums()))->toBe(['duty_officer']);
});

it('kirim ulang menerbitkan token baru dan mematikan token lama', function () {
    invite()->assertCreated();
    $old = $this->lastInvitationToken();
    $id = invTable()->value('id');

    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$id}/resend")
        ->assertOk()->assertJsonPath('data.delivery_status', 'handed_off');
    $new = $this->lastInvitationToken();

    expect($new)->not->toBe($old);
    Mail::assertSent(InvitationMail::class, 2);
    guestRequest()->getJson("/api/v1/invitations/{$old}")->assertStatus(410);
    guestRequest()->getJson("/api/v1/invitations/{$new}")->assertOk();
});

it('kirim ulang/cabut undangan yang sudah diterima → 409', function () {
    invite()->assertCreated();
    $token = $this->lastInvitationToken();
    $id = invTable()->value('id');
    guestRequest()->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertCreated();

    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/invitations/{$id}/resend")->assertConflict();
    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$id}/revoke")->assertConflict();
});

it('T05: ID undangan milik sekolah lain pada path sekolah A → 404', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b']);
    $adminB = User::factory()->create();
    $this->addMember($schoolB, $adminB, [Role::SchoolAdmin]);
    $this->actingAs($adminB)->postJson('/api/v1/schools/sekolah-b/invitations', ['email' => 'x@contoh.test', 'roles' => ['principal']])->assertCreated();
    $foreignId = $this->inSchool($schoolB, fn () => DB::table('membership_invitations')->where('email', 'x@contoh.test')->value('id'));

    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/invitations/{$foreignId}/revoke")->assertNotFound();
    $this->postJson("/api/v1/schools/sekolah-a/invitations/{$foreignId}/resend")->assertNotFound();

    expect($this->inSchool($schoolB, fn () => DB::table('membership_invitations')->where('id', $foreignId)->value('revoked_at')))->toBeNull();
});

it('T06: daftar undangan hanya memuat sekolah sendiri', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b']);
    $adminB = User::factory()->create();
    $this->addMember($schoolB, $adminB, [Role::SchoolAdmin]);
    invite('a@contoh.test');
    $this->actingAs($adminB)->postJson('/api/v1/schools/sekolah-b/invitations', ['email' => 'b@contoh.test', 'roles' => ['principal']])->assertCreated();

    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-a/invitations')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'a@contoh.test');
});

it('T07: school_id di body diabaikan', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b']);

    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/invitations', [
        'email' => 'guru@contoh.test', 'roles' => ['principal'], 'school_id' => $schoolB->id,
    ])->assertCreated();

    expect(invTable()->value('school_id'))->toBe($this->school->id);
});

it('menolak peran yang tidak dikenal dan format email salah', function (array $payload, string $field) {
    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/invitations', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field, 'errors');
})->with([
    'peran tidak dikenal' => [['email' => 'a@contoh.test', 'roles' => ['super_admin']], 'roles.0'],
    'peran kosong' => [['email' => 'a@contoh.test', 'roles' => []], 'roles'],
    'email salah' => [['email' => 'bukan-email', 'roles' => ['principal']], 'email'],
]);

it('endpoint undangan publik dibatasi 10 permintaan per menit per IP', function () {
    foreach (range(1, 10) as $_) {
        guestRequest()->getJson('/api/v1/invitations/'.str_repeat('A', 43))->assertStatus(410);
    }

    guestRequest()->getJson('/api/v1/invitations/'.str_repeat('A', 43))->assertTooManyRequests();
});

it('penerimaan paralel: undangan yang sama hanya bisa dipakai sekali', function () {
    invite()->assertCreated();
    $token = $this->lastInvitationToken();

    guestRequest()->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertCreated();
    guestRequest()->postJson("/api/v1/invitations/{$token}/accept", acceptPayload(['name' => 'Penyusup']))->assertStatus(410);

    expect(User::query()->where('email', 'guru@contoh.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'guru@contoh.test')->value('name'))->toBe('Guru Baru');
});

it('U03: audit undangan tidak memuat token, hash token, maupun password', function () {
    invite();
    $token = $this->lastInvitationToken();
    guestRequest()->postJson("/api/v1/invitations/{$token}/accept", acceptPayload())->assertCreated();

    $this->useSchool($this->school);
    $dump = json_encode(DB::table('audit_logs')->where('school_id', $this->school->id)->get());

    expect($dump)->not->toContain($token)
        ->and($dump)->not->toContain(hash('sha256', $token))
        ->and($dump)->not->toContain('kata-sandi-baru')
        ->and($dump)->not->toContain('token_hash')
        ->and($dump)->not->toContain('password');
});
