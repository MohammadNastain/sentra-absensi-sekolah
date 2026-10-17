<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// U04 (API) — audit sekolah A tidak terlihat dari sekolah B; baris platform tidak terlihat oleh sekolah.

beforeEach(function () {
    $this->schoolA = School::factory()->create(['code' => 'sekolah-a']);
    $this->schoolB = School::factory()->create(['code' => 'sekolah-b']);
    $this->adminA = User::factory()->create(['name' => 'Admin A']);
    $this->adminB = User::factory()->create(['name' => 'Admin B']);
    $this->addMember($this->schoolA, $this->adminA, [Role::SchoolAdmin]);
    $this->addMember($this->schoolB, $this->adminB, [Role::SchoolAdmin]);

    $audit = app(AuditLogger::class);
    $this->inSchool($this->schoolA, fn () => $audit->record('school.profile.updated', $this->schoolA->id, 'school', $this->schoolA->id, ['name' => 'Lama'], ['name' => 'Baru', 'password' => 'x'], 'Koreksi nama', $this->adminA));
    $this->inSchool($this->schoolB, fn () => $audit->record('school.profile.updated', $this->schoolB->id, 'school', $this->schoolB->id, ['name' => 'B lama'], ['name' => 'B baru'], null, $this->adminB));
    $this->dbContext()->runAsPlatform(fn () => $audit->record('platform.school.created', null, 'school', $this->schoolA->id, null, ['code' => 'sekolah-a'], null, $this->adminA));
});

it('U04: sekolah A hanya melihat audit-nya sendiri; baris sekolah B dan platform tidak muncul', function () {
    $response = $this->actingAs($this->adminA)->getJson('/api/v1/schools/sekolah-a/audit-logs')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.action'))->toBe('school.profile.updated')
        ->and($response->json('data.0.entity_id'))->toBe($this->schoolA->id)
        ->and($response->json('data.0.actor.name'))->toBe('Admin A')
        ->and($response->json('data.0.reason'))->toBe('Koreksi nama')
        ->and($response->json('data.0.before'))->toBe(['name' => 'Lama'])
        ->and($response->json('data.0.after'))->toBe(['name' => 'Baru']); // 'password' dibuang oleh allowlist
    expect($response->getContent())->not->toContain('B baru')->not->toContain('platform.school.created');
});

it('U04: admin sekolah B tidak bisa membaca audit sekolah A lewat path sekolah A', function () {
    $this->actingAs($this->adminB)->getJson('/api/v1/schools/sekolah-a/audit-logs')->assertNotFound();
});

it('filter audit berdasarkan action, entity_type, dan entity_id', function () {
    $audit = app(AuditLogger::class);
    $this->inSchool($this->schoolA, fn () => $audit->record('school.member.suspended', $this->schoolA->id, 'school_membership', null, null, ['status' => 'suspended'], 'Cuti', $this->adminA));

    $this->actingAs($this->adminA);
    $this->getJson('/api/v1/schools/sekolah-a/audit-logs')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/schools/sekolah-a/audit-logs?action=school.member')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/schools/sekolah-a/audit-logs?entity_type=school')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/schools/sekolah-a/audit-logs?entity_id={$this->schoolA->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/schools/sekolah-a/audit-logs?entity_id=bukan-uuid')->assertUnprocessable();
});

it('urutan audit: terbaru lebih dulu', function () {
    $audit = app(AuditLogger::class);
    $this->inSchool($this->schoolA, fn () => $audit->record('school.member.ended', $this->schoolA->id, 'school_membership', null, null, ['status' => 'ended'], 'Pindah', $this->adminA));

    $this->actingAs($this->adminA)->getJson('/api/v1/schools/sekolah-a/audit-logs')
        ->assertJsonPath('data.0.action', 'school.member.ended');
});

it('audit log tidak punya endpoint tulis (append-only dari sisi API)', function () {
    $this->actingAs($this->adminA);
    foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
        $this->{$verb}('/api/v1/schools/sekolah-a/audit-logs', [])->assertStatus(405);
    }
    $this->useSchool($this->schoolA);
    expect(DB::table('audit_logs')->count())->toBe(1);
});
