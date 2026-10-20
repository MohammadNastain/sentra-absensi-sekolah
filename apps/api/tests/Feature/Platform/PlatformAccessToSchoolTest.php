<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Super Admin mengakses sekolah mana pun sebagai Admin Sekolah virtual (D-21)

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());
});

function schoolAccessAudits(string $schoolId): Collection
{
    return test()->dbContext()->runInSchool(
        $schoolId,
        fn () => DB::table('audit_logs')->where('school_id', $schoolId)->where('action', 'platform.access.request')->get(),
    );
}

it('Super Admin lulus 2FA membuka sekolah mana pun sebagai Admin Sekolah', function () {
    School::factory()->create(['code' => 'sekolah-b']);
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);

    $this->getJson('/api/v1/schools/sekolah-a')->assertOk()
        ->assertJsonPath('data.my_roles', ['school_admin'])
        ->assertJsonPath('data.acting_as_platform_admin', true);
    $this->getJson('/api/v1/schools/sekolah-b')->assertOk();
});

it('Super Admin dapat mengubah data sekolah dan perubahannya tercatat di audit sekolah', function () {
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);

    $this->patchJson('/api/v1/schools/sekolah-a', ['address' => 'Jl. Perbaikan 1'])->assertOk();

    $audits = schoolAccessAudits($this->school->id);
    expect($audits)->not->toBeEmpty()->and($audits->first()->actor_user_id)->toBe($this->admin->id);
});

it('GET berulang dalam 60 detik dicatat sekali, request tulis selalu dicatat', function () {
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);

    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();
    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();
    $this->patchJson('/api/v1/schools/sekolah-a', ['address' => 'A'])->assertOk();
    $this->patchJson('/api/v1/schools/sekolah-a', ['address' => 'B'])->assertOk();

    expect(schoolAccessAudits($this->school->id))->toHaveCount(3);
});

it('Super Admin tanpa lulus 2FA tetap 404', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});

it('sekolah tertangguhkan tetap dapat dibuka Super Admin, yang terminated tidak', function () {
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);

    $this->school->forceFill(['status' => School::STATUS_SUSPENDED])->save();
    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();

    $this->school->forceFill(['status' => School::STATUS_TERMINATED])->save();
    $this->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});

it('user biasa tetap 404 pada sekolah yang bukan miliknya', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});
