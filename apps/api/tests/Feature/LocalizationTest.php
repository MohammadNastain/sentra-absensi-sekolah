<?php

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\User;

// Pesan validasi dan autentikasi untuk user berbahasa Indonesia (APP_LOCALE=id).

it('pesan validasi API berbahasa Indonesia dengan nama field yang ramah', function () {
    $school = School::factory()->create(['code' => 'sekolah-a']);
    $admin = User::factory()->create();
    $this->addMember($school, $admin, [Role::SchoolAdmin]);

    $response = $this->actingAs($admin)->postJson('/api/v1/schools/sekolah-a/teachers', [])->assertUnprocessable();

    expect($response->json('errors.full_name.0'))->toBe('Nama lengkap wajib diisi.');

    $this->actingAs($admin)->postJson('/api/v1/schools/sekolah-a/teachers', ['full_name' => str_repeat('a', 201)])
        ->assertJsonPath('errors.full_name.0', 'Nama lengkap tidak boleh lebih dari 200 karakter.');
});

it('login gagal memakai pesan Indonesia', function () {
    User::factory()->create(['email' => 'guru@example.test']);

    $this->postJson('/api/v1/auth/login', ['email' => 'guru@example.test', 'password' => 'salah-salah-salah'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Email atau password salah.');
});

it('berkas bahasa id memuat semua kunci validasi bawaan Laravel', function () {
    $framework = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    if (! file_exists($framework)) {
        $this->markTestSkipped('Berkas bahasa bawaan framework tidak ditemukan.');
    }

    $expected = array_keys(require $framework);
    $actual = array_keys(require lang_path('id/validation.php'));

    expect(array_values(array_diff($expected, $actual)))->toBe([]);
});
