<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Akses dukungan Super Admin (break-glass, D-21)

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);
});

function schoolAudits(string $schoolId): Collection
{
    return test()->dbContext()->runInSchool(
        $schoolId,
        fn () => DB::table('audit_logs')->where('school_id', $schoolId)->orderBy('created_at')->orderBy('id')->get(),
    );
}

it('menolak membuka sesi dukungan tanpa alasan yang memadai', function () {
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', [])->assertUnprocessable();
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'pendek'])->assertUnprocessable();
});

it('tanpa sesi dukungan Super Admin tetap 404 pada rute sekolah', function () {
    $this->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});

it('membuka sesi dukungan: Super Admin bertindak sebagai Admin Sekolah dan tercatat di audit sekolah', function () {
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Perbaiki jadwal yang salah diterbitkan'])
        ->assertCreated()->assertJsonPath('data.active', true);

    $this->getJson('/api/v1/platform/schools/sekolah-a/support-session')->assertOk()->assertJsonPath('data.active', true);

    $this->getJson('/api/v1/schools/sekolah-a')
        ->assertOk()
        ->assertJsonPath('data.code', 'sekolah-a')
        ->assertJsonPath('data.my_roles', ['school_admin'])
        ->assertJsonPath('data.support_expires_at', fn ($v) => $v !== null);

    $actions = schoolAudits($this->school->id)->pluck('action')->all();
    expect($actions)->toContain('platform.support.started')->toContain('platform.support.request');

    $started = schoolAudits($this->school->id)->firstWhere('action', 'platform.support.started');
    expect($started->reason)->toBe('Perbaiki jadwal yang salah diterbitkan')->and($started->actor_user_id)->toBe($this->admin->id);
});

it('mencatat setiap request tulis dan menyaring GET berulang dalam 60 detik', function () {
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Perbaiki profil sekolah yang keliru'])->assertCreated();

    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();
    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();
    $this->patchJson('/api/v1/schools/sekolah-a', ['address' => 'Jl. Baru 1'])->assertOk();
    $this->patchJson('/api/v1/schools/sekolah-a', ['address' => 'Jl. Baru 2'])->assertOk();

    $requests = schoolAudits($this->school->id)->where('action', 'platform.support.request');
    expect($requests->where('after_value', '!=', null)->count())->toBe(3); // 1 GET + 2 PATCH
});

it('izin dukungan hanya berlaku untuk sekolah yang dibuka, bukan sekolah lain', function () {
    School::factory()->create(['code' => 'sekolah-b']);
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Perbaiki data sekolah A saja'])->assertCreated();

    $this->getJson('/api/v1/schools/sekolah-b')->assertNotFound();
});

it('mengakhiri sesi dukungan: akses ditutup lagi dan pengakhiran tercatat', function () {
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Perbaiki kelas yang salah nama'])->assertCreated();
    $this->deleteJson('/api/v1/platform/schools/sekolah-a/support-session')->assertNoContent();

    $this->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
    $this->getJson('/api/v1/platform/schools/sekolah-a/support-session')->assertJsonPath('data.active', false);
    expect(schoolAudits($this->school->id)->pluck('action')->all())->toContain('platform.support.ended');
});

it('sesi dukungan kedaluwarsa setelah batas waktu', function () {
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Perbaiki libur nasional yang hilang'])->assertCreated();

    Carbon::setTestNow(Carbon::now()->addMinutes(61));
    try {
        $this->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
    } finally {
        Carbon::setTestNow();
    }
});

it('sekolah yang ditangguhkan tetap dapat dibuka lewat akses dukungan', function () {
    $this->school->forceFill(['status' => School::STATUS_SUSPENDED])->save();
    $this->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Periksa data sebelum diaktifkan lagi'])->assertCreated();

    $this->getJson('/api/v1/schools/sekolah-a')->assertOk();
});

it('user biasa tidak dapat membuka sesi dukungan', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/platform/schools/sekolah-a/support-session', ['reason' => 'Mencoba masuk tanpa hak'])->assertForbidden();
});
