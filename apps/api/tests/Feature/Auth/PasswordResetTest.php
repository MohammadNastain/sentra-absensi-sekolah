<?php

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

// A02 (forgot-password), A07 (token sekali pakai & kedaluwarsa), aturan password

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'guru@contoh.test']);
});

function resetPayload(string $token, array $extra = []): array
{
    return ['token' => $token, 'email' => 'guru@contoh.test', 'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123', ...$extra];
}

it('forgot-password selalu 202 dan hanya user aktif terdaftar yang menerima email', function () {
    Notification::fake();
    $disabled = User::factory()->disabled()->create(['email' => 'off@contoh.test']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'GURU@contoh.test'])->assertStatus(202);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'tidak-ada@contoh.test'])->assertStatus(202);
    $off = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'off@contoh.test'])->assertStatus(202);

    expect($unknown->json())->toBe($known->json())->and($off->json())->toBe($known->json());
    Notification::assertSentTo($this->user, ResetPassword::class);
    Notification::assertNotSentTo($disabled, ResetPassword::class);
    Notification::assertCount(1);
});

it('tautan reset mengarah ke dashboard dan memuat token + email', function () {
    Notification::fake();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'guru@contoh.test']);

    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $n) {
        $url = (string) $n->toMail($this->user)->actionUrl;

        return str_starts_with($url, config('app.frontend_url').'/reset-password?token=') && str_contains($url, 'email=guru%40contoh.test');
    });
});

it('forgot-password dibatasi 3 permintaan per 15 menit per email', function () {
    Notification::fake();
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'guru@contoh.test'])->assertStatus(202);
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'guru@contoh.test'])->assertTooManyRequests();
});

it('A07: token reset sekali pakai', function () {
    $token = Password::createToken($this->user);

    $this->postJson('/api/v1/auth/reset-password', resetPayload($token))->assertOk();
    $this->postJson('/api/v1/auth/reset-password', resetPayload($token, ['password' => 'password-lain-456', 'password_confirmation' => 'password-lain-456']))
        ->assertUnprocessable();

    $this->postJson('/api/v1/auth/login', ['email' => 'guru@contoh.test', 'password' => 'password-baru-123'])->assertOk();
});

it('A07: token reset kedaluwarsa setelah 60 menit', function () {
    $token = Password::createToken($this->user);

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/reset-password', resetPayload($token))->assertUnprocessable();
    expect(password_verify(UserFactory::PASSWORD, $this->user->fresh()->password))->toBeTrue();
});

it('A07: token yang salah ditolak', function () {
    Password::createToken($this->user);

    $this->postJson('/api/v1/auth/reset-password', resetPayload('token-palsu'))->assertUnprocessable();
});

it('reset password menolak password lemah dan konfirmasi berbeda', function (array $override) {
    $token = Password::createToken($this->user);

    $this->postJson('/api/v1/auth/reset-password', resetPayload($token, $override))->assertUnprocessable();
})->with([
    'terlalu pendek' => [['password' => 'pendek1', 'password_confirmation' => 'pendek1']],
    'konfirmasi beda' => [['password_confirmation' => 'beda-sekali-123']], // gitleaks:allow (password fiktif untuk test)
]);

it('ganti password: password lama harus benar dan password baru memenuhi aturan', function () {
    $this->actingAs($this->user);

    $this->putJson('/api/v1/auth/password', ['current_password' => 'salah', 'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password', 'errors');
    $this->putJson('/api/v1/auth/password', ['current_password' => UserFactory::PASSWORD, 'password' => 'pendek', 'password_confirmation' => 'pendek'])
        ->assertUnprocessable();
    $this->putJson('/api/v1/auth/password', ['current_password' => UserFactory::PASSWORD, 'password' => 'password-baru-123', 'password_confirmation' => 'password-baru-123'])
        ->assertOk();

    expect(password_verify('password-baru-123', $this->user->fresh()->password))->toBeTrue();
});

it('cek password bocor dipakai bila diaktifkan, dan hanya mengirim awalan 5 karakter SHA-1', function () {
    config(['auth.password_check_uncompromised' => true]);
    $password = 'password-baru-123';
    $sha1 = strtoupper(sha1($password));
    $requested = [];

    Http::fake(function (Request $request) use (&$requested, $sha1) {
        $requested[] = $request->url();

        return Http::response(substr($sha1, 5).':9999\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1', 200);
    });
    $token = Password::createToken($this->user);

    $this->postJson('/api/v1/auth/reset-password', resetPayload($token))
        ->assertUnprocessable()->assertJsonValidationErrors('password', 'errors');

    expect($requested)->toHaveCount(1)
        ->and($requested[0])->toEndWith('/range/'.substr($sha1, 0, 5))
        ->and($requested[0])->not->toContain($password)->not->toContain($sha1);
});
