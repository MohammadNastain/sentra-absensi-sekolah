<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Super Admin pertama di server produksi dibuat lewat artisan, bukan seeder.

it('membuat Super Admin dari sandi di variabel lingkungan', function () {
    putenv('SENTRA_TEST_PASSWORD=Kata-Sandi-Kuat-2026!');

    $this->artisan('platform:create-admin', ['email' => 'Owner@Contoh.test', '--name' => 'Pemilik', '--password-env' => 'SENTRA_TEST_PASSWORD'])
        ->expectsOutputToContain('owner@contoh.test dibuat')->assertSuccessful();
    putenv('SENTRA_TEST_PASSWORD');

    $user = User::query()->where('email', 'owner@contoh.test')->firstOrFail();
    expect($user->name)->toBe('Pemilik')->and(DB::table('platform_admins')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($user->two_factor_confirmed_at)->toBeNull();
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@contoh.test', 'password' => 'Kata-Sandi-Kuat-2026!'])->assertOk();
});

it('menolak sandi lemah, surel tidak valid, dan akun yang sudah ada', function () {
    putenv('SENTRA_TEST_PASSWORD=pendek');
    $this->artisan('platform:create-admin', ['email' => 'owner@contoh.test', '--password-env' => 'SENTRA_TEST_PASSWORD'])->assertFailed();
    putenv('SENTRA_TEST_PASSWORD=Kata-Sandi-Kuat-2026!');
    $this->artisan('platform:create-admin', ['email' => 'bukan-surel', '--password-env' => 'SENTRA_TEST_PASSWORD'])->assertFailed();

    User::factory()->create(['email' => 'ada@contoh.test']);
    $this->artisan('platform:create-admin', ['email' => 'ada@contoh.test', '--password-env' => 'SENTRA_TEST_PASSWORD'])->assertFailed();
    putenv('SENTRA_TEST_PASSWORD');

    expect(DB::table('platform_admins')->count())->toBe(0);
});

it('menolak bila variabel sandi kosong', function () {
    $this->artisan('platform:create-admin', ['email' => 'owner@contoh.test', '--password-env' => 'SENTRA_TIDAK_ADA'])->assertFailed();
});
