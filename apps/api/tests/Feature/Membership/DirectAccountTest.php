<?php

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Admin Sekolah membuat akun anggota langsung (tanpa undangan surel)

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function accountPayload(array $overrides = []): array
{
    return [
        'name' => 'Siti Rahma',
        'email' => 'Siti.Rahma@Sekolah.Test',
        'password' => 'wali-kelas-2026',
        'roles' => ['homeroom_teacher'],
        ...$overrides,
    ];
}

it('membuat akun wali kelas langsung, membuat data guru yang tertaut, dan akun itu bisa login', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload())
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'siti.rahma@sekolah.test')
        ->assertJsonPath('data.roles', ['homeroom_teacher'])
        ->assertJsonPath('data.status', 'active');

    $user = User::query()->where('email', 'siti.rahma@sekolah.test')->first();
    expect($user)->not->toBeNull()->and(Hash::check('wali-kelas-2026', $user->password))->toBeTrue();

    $teacher = test()->dbContext()->runInSchool($this->school->id, fn () => Teacher::query()->where('full_name', 'Siti Rahma')->first());
    expect($teacher)->not->toBeNull()->and($teacher->membership_id)->not->toBeNull();

    // Akun yang baru dibuat dapat login dengan email dan password yang diserahkan admin.
    auth()->guard('web')->logout();
    $this->postJson('/api/v1/auth/login', ['email' => 'siti.rahma@sekolah.test', 'password' => 'wali-kelas-2026'])->assertOk();
});

it('peran selain wali kelas tidak membuat data guru', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload(['email' => 'piket@sekolah.test', 'name' => 'Petugas Piket', 'roles' => ['duty_officer']]))
        ->assertCreated();

    expect(test()->dbContext()->runInSchool($this->school->id, fn () => Teacher::query()->count()))->toBe(0);
});

it('menolak email yang sudah terdaftar, password lemah, dan peran terminal', function () {
    User::factory()->create(['email' => 'ada@sekolah.test']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload(['email' => 'ada@sekolah.test']))->assertStatus(409);
    $this->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload(['password' => 'pendek']))->assertUnprocessable();
    $this->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload(['roles' => ['terminal_operator']]))->assertUnprocessable();
});

it('hanya yang berizin kelola anggota yang boleh membuat akun', function () {
    $teacher = User::factory()->create();
    $this->addMember($this->school, $teacher, [Role::HomeroomTeacher]);

    $this->actingAs($teacher)->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload())->assertForbidden();
});

it('mencatat pembuatan akun di audit tanpa menyimpan password', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload())->assertCreated();

    $rows = test()->dbContext()->runInSchool($this->school->id, fn () => DB::table('audit_logs')->where('school_id', $this->school->id)->whereIn('action', ['user.account.created', 'school.member.joined'])->get());
    expect($rows)->toHaveCount(2)->and(json_encode($rows))->not->toContain('wali-kelas-2026');
});

it('admin menetapkan password baru anggota tanpa surel', function () {
    $created = $this->actingAs($this->admin)->postJson('/api/v1/schools/sekolah-a/members/accounts', accountPayload())->json('data.id');

    $this->putJson("/api/v1/schools/sekolah-a/members/{$created}/password", ['password' => 'password-baru-2026'])->assertOk();
    $this->putJson("/api/v1/schools/sekolah-a/members/{$created}/password", ['password' => 'pendek'])->assertUnprocessable();

    $user = User::query()->where('email', 'siti.rahma@sekolah.test')->first();
    expect(Hash::check('password-baru-2026', $user->password))->toBeTrue();
});
