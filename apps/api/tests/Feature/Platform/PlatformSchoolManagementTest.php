<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);
});

function newSchoolPayload(array $overrides = []): array
{
    return [
        'code' => 'smpn-1-contoh',
        'name' => 'SMP Negeri 1 Contoh',
        'npsn' => '20100001',
        'education_levels' => ['SMP'],
        'timezone' => 'Asia/Makassar',
        ...$overrides,
    ];
}

function platformAudits(): Illuminate\Support\Collection
{
    return test()->dbContext()->runAsPlatform(
        fn () => DB::table('audit_logs')->whereNull('school_id')->orderBy('created_at')->orderBy('id')->get(),
    );
}

it('membuat sekolah dan mencatat audit platform dengan after tanpa field di luar allowlist', function () {
    $this->postJson('/api/v1/platform/schools', newSchoolPayload())
        ->assertCreated()
        ->assertJsonPath('data.code', 'smpn-1-contoh')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.education_levels', ['SMP']);

    $audit = platformAudits()->firstWhere('action', 'platform.school.created');

    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->school_id)->toBeNull()
        ->and(json_decode($audit->after_value, true)['code'])->toBe('smpn-1-contoh');
});

it('menolak kode, NPSN, jenjang, dan zona waktu yang tidak valid', function (array $override, string $field) {
    $this->postJson('/api/v1/platform/schools', newSchoolPayload($override))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonValidationErrors($field, 'errors');
})->with([
    'kode huruf besar' => [['code' => 'SMPN-1'], 'code'],
    'kode diawali tanda hubung' => [['code' => '-smpn'], 'code'],
    'npsn bukan 8 digit' => [['npsn' => '123'], 'npsn'],
    'jenjang tidak dikenal' => [['education_levels' => ['Universitas']], 'education_levels.0'],
    'jenjang kosong' => [['education_levels' => []], 'education_levels'],
    'zona waktu di luar WIB/WITA/WIT' => [['timezone' => 'Asia/Singapore'], 'timezone'],
    'status terminated' => [['status' => 'terminated'], 'status'],
]);

it('menolak kode dan NPSN ganda tanpa memandang huruf besar/kecil', function () {
    School::factory()->create(['code' => 'smpn-1-contoh', 'npsn' => '20100001']);

    $this->postJson('/api/v1/platform/schools', newSchoolPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'npsn'], 'errors');
});

it('mengubah profil sekolah tetapi tidak kode maupun status', function () {
    $school = School::factory()->create(['code' => 'sekolah-a', 'name' => 'Lama']);

    $this->patchJson('/api/v1/platform/schools/sekolah-a', ['name' => 'Baru', 'timezone' => 'Asia/Jayapura'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Baru')
        ->assertJsonPath('data.timezone', 'Asia/Jayapura');

    $this->patchJson('/api/v1/platform/schools/sekolah-a', ['code' => 'lain'])->assertUnprocessable();
    $this->patchJson('/api/v1/platform/schools/sekolah-a', ['status' => 'terminated'])->assertUnprocessable();

    $audit = platformAudits()->firstWhere('action', 'platform.school.updated');
    expect(json_decode($audit->before_value, true)['name'])->toBe('Lama')
        ->and(json_decode($audit->after_value, true)['name'])->toBe('Baru')
        ->and($audit->entity_id)->toBe($school->id);
});

it('menangguhkan (alasan wajib) dan mengaktifkan kembali sekolah', function () {
    School::factory()->create(['code' => 'sekolah-a']);

    $this->postJson('/api/v1/platform/schools/sekolah-a/suspend', [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason', 'errors');

    $this->postJson('/api/v1/platform/schools/sekolah-a/suspend', ['reason' => 'Tunggakan administrasi'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    $this->postJson('/api/v1/platform/schools/sekolah-a/suspend', ['reason' => 'Dobel tangguh'])
        ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    $this->postJson('/api/v1/platform/schools/sekolah-a/activate')
        ->assertOk()->assertJsonPath('data.status', 'active');

    $this->postJson('/api/v1/platform/schools/sekolah-a/activate')
        ->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    $suspended = platformAudits()->firstWhere('action', 'platform.school.suspended');
    expect($suspended->reason)->toBe('Tunggakan administrasi')
        ->and(json_decode($suspended->after_value, true)['status'])->toBe('suspended');
});

it('sekolah yang ditangguhkan langsung menolak seluruh anggotanya', function () {
    $school = School::factory()->create(['code' => 'sekolah-a']);
    $member = User::factory()->create();
    $this->addMember($school, $member, [App\Domain\Identity\Role::SchoolAdmin]);

    $this->postJson('/api/v1/platform/schools/sekolah-a/suspend', ['reason' => 'Pelanggaran kebijakan'])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->actingAs($member)->getJson('/api/v1/schools/sekolah-a')
        ->assertForbidden()->assertJsonPath('code', 'SCHOOL_UNAVAILABLE');
});

it('mengundang Admin Sekolah pertama dengan peran school_admin yang tidak bisa diubah dari body', function () {
    Mail::fake();
    $school = School::factory()->create(['code' => 'sekolah-a']);

    $this->postJson('/api/v1/platform/schools/sekolah-a/invitations', ['email' => 'Kepala@Contoh.test', 'roles' => ['principal']])
        ->assertCreated()
        ->assertJsonPath('data.email', 'kepala@contoh.test')
        ->assertJsonPath('data.roles', ['school_admin'])
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    Mail::assertSent(App\Mail\InvitationMail::class, fn ($mail) => $mail->hasTo('kepala@contoh.test'));
    expect($school->fresh())->not->toBeNull();
});

it('daftar sekolah platform terpaginasi dan terurut nama', function () {
    School::factory()->create(['name' => 'B Sekolah']);
    School::factory()->create(['name' => 'A Sekolah']);

    $this->getJson('/api/v1/platform/schools')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'A Sekolah')
        ->assertJsonPath('meta.total', 2);
});

it('sekolah yang tidak ada → 404', function () {
    $this->getJson('/api/v1/platform/schools/tidak-ada')->assertNotFound();
});
