<?php

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// ADR 0009 §4, §6, §7 — API Super Admin: matriks, ganti paket, override, pratinjau dampak, audit.

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());
    $this->schoolAdmin = User::factory()->create();
    $this->addMember($this->school, $this->schoolAdmin, [Role::SchoolAdmin]);
    $this->actingAsPlatformAdminWithTwoFactor($this->admin);
});

function entitlementAudits(): Collection
{
    return test()->dbContext()->runAsPlatform(
        fn () => DB::table('audit_logs')->whereNull('school_id')->where('action', 'like', 'platform.%')->whereIn('entity_type', ['school_subscription', 'school_feature_override'])
            ->orderBy('created_at')->orderBy('id')->get(),
    );
}

function currentSubscriptions(string $schoolId): Collection
{
    return test()->dbContext()->runAsPlatform(
        fn () => DB::table('school_subscriptions')->where('school_id', $schoolId)->orderBy('created_at')->orderBy('id')->get(),
    );
}

function featureRow(array $features, string $code): array
{
    return collect($features)->firstWhere('code', $code);
}

it('registri dan daftar paket tersedia untuk Super Admin', function () {
    $features = $this->getJson('/api/v1/platform/features')->assertOk()->json('data');
    $plans = $this->getJson('/api/v1/platform/plans')->assertOk()->json('data');

    expect(featureRow($features, 'lms.grading'))->toMatchArray(['parent' => 'lms', 'requires' => ['lms.submissions'], 'released' => false])
        ->and(collect($plans)->pluck('code')->all())->toBe(['legacy', 'basic', 'standard', 'premium'])
        ->and(collect($plans)->firstWhere('code', 'basic')['features'])->toContain('attendance.scan_qr')->not->toContain('teachers');
});

it('matriks sekolah menunjukkan sumber akses tiap fitur', function () {
    setSchoolPlan($this->school->id, 'basic');
    setFeatureOverride($this->school->id, 'teachers', 'grant');
    setFeatureOverride($this->school->id, 'attendance.reports', 'revoke');

    $data = $this->getJson('/api/v1/platform/schools/sekolah-a/entitlements')->assertOk()->json('data');

    expect($data['subscription'])->toMatchArray(['plan_code' => 'basic', 'plan_name' => 'Basic', 'status' => 'active']);
    expect(featureRow($data['features'], 'attendance'))->toMatchArray(['enabled' => true, 'source' => 'plan', 'in_plan' => true, 'override' => null])
        ->and(featureRow($data['features'], 'teachers'))->toMatchArray(['enabled' => true, 'source' => 'override_grant', 'in_plan' => false, 'override' => 'grant'])
        ->and(featureRow($data['features'], 'attendance.reports'))->toMatchArray(['enabled' => false, 'source' => 'override_revoke', 'in_plan' => true])
        ->and(featureRow($data['features'], 'attendance.leave'))->toMatchArray(['enabled' => false, 'source' => 'not_in_plan'])
        ->and(featureRow($data['features'], 'lms'))->toMatchArray(['enabled' => false, 'source' => 'unreleased', 'released' => false])
        ->and(featureRow($data['features'], 'academic.years'))->toMatchArray(['enabled' => false, 'source' => 'not_in_plan']);
});

// --- Ganti paket ---

it('naik paket: tanpa konfirmasi, fitur baru tercatat di dampak, langganan lama menjadi riwayat, audit lengkap', function () {
    setSchoolPlan($this->school->id, 'basic');

    $res = $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'standard', 'reason' => 'Upgrade Oktober'])->assertOk();
    $res->assertJsonPath('data.applied', true)->assertJsonPath('data.impact.lost', [])
        ->assertJsonPath('data.entitlements.subscription.plan_code', 'standard');
    expect($res->json('data.impact.gained'))->toContain('teachers', 'academic.years');

    $rows = currentSubscriptions($this->school->id);
    expect($rows->pluck('plan_code')->all())->toBe(['legacy', 'basic', 'standard'])
        ->and($rows->pluck('status')->all())->toBe(['ended', 'ended', 'active'])
        ->and($rows->where('status', '<>', 'ended'))->toHaveCount(1);

    $audit = entitlementAudits()->firstWhere('action', 'platform.subscription.changed');
    $after = json_decode($audit->after_value, true);
    expect($audit->reason)->toBe('Upgrade Oktober')->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and(json_decode($audit->before_value, true))->toMatchArray(['school_id' => $this->school->id, 'plan_code' => 'basic', 'status' => 'active'])
        ->and($after['plan_code'])->toBe('standard')->and($after['school_id'])->toBe($this->school->id);
});

it('turun paket yang mematikan fitur: pratinjau, wajib konfirmasi, lalu akses benar-benar tertutup', function () {
    $preview = $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'basic', 'dry_run' => true])->assertOk();
    $preview->assertJsonPath('data.applied', false)->assertJsonPath('data.requires_confirmation', true);
    expect($preview->json('data.impact.lost'))->toContain('teachers', 'attendance.leave', 'academic.teaching_schedule');
    expect(currentSubscriptions($this->school->id)->pluck('plan_code')->all())->toBe(['legacy']); // pratinjau tidak mengubah apa pun

    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'basic'])
        ->assertConflict()->assertJsonPath('code', 'IMPACT_CONFIRMATION_REQUIRED')->assertJsonPath('impact.lost.0', 'attendance.leave');
    expect(currentSubscriptions($this->school->id)->pluck('plan_code')->all())->toBe(['legacy']);

    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'basic', 'confirm_impact' => true])->assertOk()->assertJsonPath('data.applied', true);

    // Sekolah benar-benar kehilangan akses (penegakan E2), data tetap ada.
    $this->actingAs($this->schoolAdmin)->getJson('/api/v1/schools/sekolah-a/teachers')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
});

it('override tidak dihapus saat paket berganti; yang jadi redundan ditandai', function () {
    setSchoolPlan($this->school->id, 'basic');
    setFeatureOverride($this->school->id, 'teachers', 'grant');
    setFeatureOverride($this->school->id, 'lms', 'revoke');

    $res = $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'standard'])->assertOk();

    // grant teachers kini sama dengan paket Standard; revoke lms sama dengan "tidak ada di Standard".
    expect($res->json('data.impact.redundant_overrides'))->toBe(['lms', 'teachers']);
    expect(featureRow($res->json('data.entitlements.features'), 'teachers')['override'])->toBe('grant');
    expect($this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->where('school_id', $this->school->id)->count()))->toBe(2);
});

it('ganti paket menolak paket tak dikenal, paket yang sama, dan paket yang tidak ditawarkan', function () {
    setSchoolPlan($this->school->id, 'basic');

    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'platinum'])->assertUnprocessable()->assertJsonPath('errors.plan_code.0', 'Paket tidak dikenal atau tidak ditawarkan.');
    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'basic'])->assertUnprocessable()->assertJsonPath('errors.plan_code.0', 'Sekolah sudah memakai paket ini.');
    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'legacy'])->assertUnprocessable(); // tidak aktif ditawarkan
    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', [])->assertUnprocessable();
});

it('sekolah tanpa langganan dapat diberi paket pertama', function () {
    $bare = School::factory()->create(['code' => 'sekolah-b']);
    $this->dbContext()->runAsPlatform(fn () => DB::table('school_subscriptions')->where('school_id', $bare->id)->delete());

    $this->putJson('/api/v1/platform/schools/sekolah-b/subscription', ['plan_code' => 'standard'])->assertOk()->assertJsonPath('data.entitlements.subscription.plan_code', 'standard');
});

// --- Override ---

it('grant fitur belum rilis ditolak: flag database saja tidak cukup mengaktifkan', function () {
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/lms', ['effect' => 'grant'])
        ->assertUnprocessable()->assertJsonPath('code', 'FEATURE_UNRELEASED')->assertJsonPath('feature', 'lms');

    expect($this->dbContext()->runAsPlatform(fn () => DB::table('school_feature_overrides')->count()))->toBe(0);
});

it('grant tanpa prasyarat ditolak dengan daftar yang kurang', function () {
    setSchoolPlan($this->school->id, 'basic');

    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/academic.years', ['effect' => 'grant'])
        ->assertUnprocessable()->assertJsonPath('code', 'FEATURE_DEPENDENCY_MISSING')->assertJsonPath('missing', ['academic']);
    // Setelah induknya di-grant, anaknya boleh.
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/academic', ['effect' => 'grant'])->assertOk();
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/academic.years', ['effect' => 'grant'])->assertOk();
});

it('grant menambah fitur dan tercatat di audit dengan sebelum/sesudah dan alasan', function () {
    setSchoolPlan($this->school->id, 'basic');

    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'grant', 'reason' => 'Pilot guru'])
        ->assertOk()->assertJsonPath('data.applied', true)->assertJsonPath('data.impact.gained', ['teachers']);
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'revoke'])->assertOk();

    $audits = entitlementAudits()->filter(fn ($a) => $a->action === 'platform.entitlement.override_set')->values();
    expect($audits)->toHaveCount(2);
    $first = $audits->first();
    expect($first->reason)->toBe('Pilot guru')->and($first->before_value)->toBeNull()
        ->and(json_decode($first->after_value, true))->toMatchArray(['school_id' => $this->school->id, 'feature_code' => 'teachers', 'effect' => 'grant']);
    expect(json_decode($audits->last()->before_value, true)['effect'])->toBe('grant');
});

it('revoke fitur daun tidak perlu konfirmasi; revoke fitur yang punya turunan wajib', function () {
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/attendance.leave', ['effect' => 'revoke'])->assertOk()->assertJsonPath('data.impact.lost', ['attendance.leave']);

    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/students', ['effect' => 'revoke'])
        ->assertConflict()->assertJsonPath('code', 'IMPACT_CONFIRMATION_REQUIRED');
    $dry = $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/students', ['effect' => 'revoke', 'dry_run' => true])->assertOk();
    expect($dry->json('data.requires_confirmation'))->toBeTrue()
        ->and($dry->json('data.impact.lost'))->toContain('students', 'attendance', 'attendance.corrections', 'academic', 'academic.teaching_schedule');

    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/students', ['effect' => 'revoke', 'confirm_impact' => true])->assertOk()->assertJsonPath('data.applied', true);
    $this->actingAs($this->schoolAdmin)->getJson('/api/v1/schools/sekolah-a/attendance/today')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
});

it('efek yang sama dengan override sekarang tidak mengubah apa pun dan tidak diaudit', function () {
    setSchoolPlan($this->school->id, 'basic');
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'grant'])->assertOk();

    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'grant'])
        ->assertOk()->assertJsonPath('data.applied', false)->assertJsonPath('data.unchanged', true);

    expect(entitlementAudits())->toHaveCount(1);
});

it('menghapus override mengembalikan ke paket; dampak turunan butuh konfirmasi; yang tidak ada → 404', function () {
    setSchoolPlan($this->school->id, 'basic');
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/academic', ['effect' => 'grant'])->assertOk();
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/academic.years', ['effect' => 'grant'])->assertOk();

    // Menghapus grant induk mematikan anak yang di-grant.
    $this->deleteJson('/api/v1/platform/schools/sekolah-a/entitlements/academic')
        ->assertConflict()->assertJsonPath('impact.lost', ['academic', 'academic.years']);
    $this->deleteJson('/api/v1/platform/schools/sekolah-a/entitlements/academic', ['confirm_impact' => true, 'reason' => 'Uji coba selesai'])->assertOk();
    $this->deleteJson('/api/v1/platform/schools/sekolah-a/entitlements/academic.years')->assertOk();

    $this->deleteJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers')->assertNotFound();
    $removed = entitlementAudits()->where('action', 'platform.entitlement.override_removed');
    expect($removed)->toHaveCount(2)->and($removed->first()->reason)->toBe('Uji coba selesai')->and($removed->first()->after_value)->toBeNull();
});

it('kode fitur tak dikenal → 404, efek tak valid → 422', function () {
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/sihir', ['effect' => 'grant'])->assertNotFound();
    $this->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'mungkin'])->assertUnprocessable();
    $this->putJson('/api/v1/platform/schools/sekolah-zzz/entitlements/teachers', ['effect' => 'grant'])->assertNotFound();
});

// --- Sisi sekolah ---

it('anggota sekolah membaca fitur efektif sekolahnya sendiri, juga saat modul lain dicabut', function () {
    setSchoolPlan($this->school->id, 'basic');
    setFeatureOverride($this->school->id, 'attendance.reports', 'revoke');
    $member = $this->schoolAdmin;

    $data = $this->actingAs($member)->getJson('/api/v1/schools/sekolah-a/entitlements')->assertOk()->json('data');

    expect($data['plan'])->toBe(['code' => 'basic', 'name' => 'Basic'])
        ->and($data['features'])->toContain('attendance', 'students')->not->toContain('attendance.reports', 'teachers');
});

it('sekolah tidak bisa mengubah paket atau override sendiri (hanya Super Admin)', function () {
    $member = $this->schoolAdmin;

    $this->actingAs($member)->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'premium'])
        ->assertForbidden()->assertJsonPath('code', 'PLATFORM_ADMIN_REQUIRED');
    $this->actingAs($member)->putJson('/api/v1/platform/schools/sekolah-a/entitlements/teachers', ['effect' => 'grant'])->assertForbidden();
    expect(currentSubscriptions($this->school->id)->pluck('plan_code')->all())->toBe(['legacy']);
});

it('audit platform lintas sekolah memuat perubahan paket dan dapat disaring per sekolah', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'standard'])->assertOk();
    $this->putJson('/api/v1/platform/schools/sekolah-b/subscription', ['plan_code' => 'premium'])->assertOk();

    $all = $this->getJson('/api/v1/platform/audit-logs?action=platform.subscription')->assertOk()->json('data');
    expect($all)->toHaveCount(2);

    $onlyA = $this->getJson('/api/v1/platform/audit-logs?school=sekolah-a')->assertOk()->json('data');
    expect($onlyA)->toHaveCount(1)->and($onlyA[0]['after']['school_id'])->toBe($this->school->id);

    $this->getJson('/api/v1/platform/audit-logs?school=tidak-ada')->assertOk()->assertJsonPath('meta.total', 0);
    expect($other->id)->not->toBe($this->school->id);
});

it('audit platform tidak terbaca oleh admin sekolah', function () {
    $this->putJson('/api/v1/platform/schools/sekolah-a/subscription', ['plan_code' => 'standard'])->assertOk();

    $this->actingAs($this->schoolAdmin);
    $this->getJson('/api/v1/platform/audit-logs')->assertForbidden();
});
