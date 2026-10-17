<?php

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\User;
use Database\Factories\UserFactory;
use PragmaRX\Google2FA\Google2FA;

// A08 (TAHAP_1_FONDASI §8, ADR 0007 D-25)

it('menolak user biasa dengan 403 PLATFORM_ADMIN_REQUIRED', function () {
    $user = User::factory()->withConfirmedTwoFactor()->create();
    $this->addMember(School::factory()->create(), $user, [Role::SchoolAdmin]);

    $this->actingAs($user)->getJson('/api/v1/platform/schools')
        ->assertForbidden()->assertJsonPath('code', 'PLATFORM_ADMIN_REQUIRED');
});

it('A08: Super Admin tanpa 2FA terkonfirmasi tidak bisa mengakses rute platform', function () {
    $admin = $this->makePlatformAdmin(User::factory()->create());

    $this->actingAs($admin)->getJson('/api/v1/platform/schools')
        ->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
});

it('A08: Super Admin dengan 2FA tetapi belum lulus tantangan di sesi ini ditolak', function () {
    $admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());

    $this->actingAs($admin)->getJson('/api/v1/platform/schools')
        ->assertForbidden()->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
});

it('A08: alur penuh login → tantangan 2FA → akses platform', function () {
    $admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create(['email' => 'super@example.test']));
    School::factory()->create(['name' => 'SMP Contoh']);

    $this->postJson('/api/v1/auth/login', ['email' => 'super@example.test', 'password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJson(['two_factor' => true]);
    $this->assertGuest();

    $code = (new Google2FA)->getCurrentOtp(decrypt($admin->two_factor_secret));
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertNoContent();

    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.two_factor_passed', true);
    $this->getJson('/api/v1/platform/schools')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'SMP Contoh');
});

it('A08: kode 2FA salah tidak memberi akses', function () {
    $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create(['email' => 'super@example.test']));

    $this->postJson('/api/v1/auth/login', ['email' => 'super@example.test', 'password' => UserFactory::PASSWORD])
        ->assertJson(['two_factor' => true]);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();

    $this->assertGuest();
    $this->getJson('/api/v1/platform/schools')->assertUnauthorized();
});

it('tanpa login → 401 pada rute platform', function () {
    $this->getJson('/api/v1/platform/schools')->assertUnauthorized();
});
