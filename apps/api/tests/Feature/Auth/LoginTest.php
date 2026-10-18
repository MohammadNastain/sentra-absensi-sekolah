<?php

use App\Domain\Identity\SessionPolicy;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// A01–A06 (TAHAP_1_FONDASI §8)

function login(string $email, string $password = UserFactory::PASSWORD): array
{
    return ['email' => $email, 'password' => $password];
}

it('A01: login valid membuat sesi; logout membuat sesi tidak berlaku', function () {
    $user = User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', login('guru@example.test'))
        ->assertOk()
        ->assertJson(['two_factor' => false]);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'guru@example.test')
        ->assertJsonPath('data.is_platform_admin', false);

    expect($user->fresh()->last_login_at)->not->toBeNull();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('A01: login menerima email tanpa memandang huruf besar/kecil', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', login('GURU@Example.test'))->assertOk();
});

it('A02: password salah dan email tidak terdaftar menghasilkan respons identik', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', login('guru@example.test', 'salah-sekali-123'));
    $unknownEmail = $this->postJson('/api/v1/auth/login', login('tidak-ada@example.test'));

    $wrongPassword->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    $unknownEmail->assertUnprocessable();

    $strip = fn (array $body) => array_diff_key($body, ['request_id' => true]);
    expect($strip($wrongPassword->json()))->toBe($strip($unknownEmail->json()));
});

it('A03: user disabled tidak bisa login dengan respons yang sama seperti kredensial salah', function () {
    User::factory()->disabled()->create(['email' => 'nonaktif@example.test']);
    User::factory()->create(['email' => 'aktif@example.test']);

    $disabled = $this->postJson('/api/v1/auth/login', login('nonaktif@example.test'));
    $wrong = $this->postJson('/api/v1/auth/login', login('aktif@example.test', 'salah-sekali-123'));

    $disabled->assertUnprocessable();
    expect($disabled->json('errors'))->toBe($wrong->json('errors'));
    $this->assertGuest();
});

it('A03: user yang dinonaktifkan saat sesi aktif ditolak pada request berikutnya', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

    DB::table('users')->where('id', $user->id)->update(['status' => User::STATUS_DISABLED]);
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->assertGuest();
});

it('A04: rate limit login terpicu pada percobaan ke-6 untuk email+IP yang sama', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', login('guru@example.test', 'salah-sekali-123'))->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', login('guru@example.test'))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});

it('A04: rate limit per IP terpicu setelah 20 percobaan dengan email berbeda', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/auth/login', login("orang{$i}@example.test", 'salah-sekali-123'))->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', login('orang-lain@example.test'))->assertTooManyRequests();
});

it('A05: ID sesi berganti saat login (anti session fixation)', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    $this->startSession();
    $before = session()->getId();

    $this->postJson('/api/v1/auth/login', login('guru@example.test'))->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('A06: request tulis tanpa token CSRF ditolak 419', function () {
    // Laravel melewati verifikasi CSRF saat unit test; test ini menyalakannya kembali.
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', login('guru@example.test'))
        ->assertStatus(419)
        ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    $this->assertGuest();
});

it('menolak opsi "ingat saya" agar batas sesi 12 jam tidak terlewati', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', [...login('guru@example.test'), 'remember' => true])
        ->assertOk()
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());
});

it('memutus sesi yang melewati batas absolut 12 jam', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.login_at' => now()->subSeconds(SessionPolicy::MAX_AGE_SECONDS + 1)->getTimestamp()])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('mencatat audit login berhasil & gagal tanpa menyimpan password', function () {
    $user = User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', login('guru@example.test', 'salah-sekali-123'));
    $this->postJson('/api/v1/auth/login', login('guru@example.test'));

    $rows = $this->dbContext()->runAsPlatform(fn () => DB::table('audit_logs')->orderBy('created_at')->orderBy('id')->get());

    expect($rows->pluck('action')->all())->toBe(['auth.login.failed', 'auth.login.succeeded'])
        ->and($rows[1]->actor_user_id)->toBe($user->id)
        ->and($rows->pluck('after_value')->implode(' '))->not->toContain('salah-sekali')
        ->and($rows->pluck('request_id')->filter()->count())->toBe(2);
});
