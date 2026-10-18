<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// R01–R03, T05–T07, U01 — TAHAP_1_FONDASI §8

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create(['name' => 'Admin Satu']);
    $this->adminMembership = $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->guru = User::factory()->create(['name' => 'Guru Satu']);
    $this->guruMembership = $this->addMember($this->school, $this->guru, [Role::DutyOfficer]);
});

function members(string $path = '', string $method = 'getJson', array $data = [])
{
    $m = test();

    return $m->actingAs($m->admin)->{$method}("/api/v1/schools/sekolah-a/members{$path}", $data);
}

function rolesOf(string $membershipId): array
{
    return DB::table('membership_roles')->where('membership_id', $membershipId)->orderBy('role_code')->pluck('role_code')->all();
}

it('daftar anggota memuat user dan peran, terurut, dengan filter status', function () {
    $this->addMember($this->school, User::factory()->create(), [Role::Principal], SchoolMembership::STATUS_SUSPENDED);

    members()->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.user.name', 'Admin Satu')
        ->assertJsonPath('data.0.roles', ['school_admin']);
    members('?status=suspended')->assertJsonCount(1, 'data')->assertJsonPath('data.0.roles', ['principal']);
});

it('detail anggota', function () {
    members("/{$this->guruMembership->id}")->assertOk()
        ->assertJsonPath('data.user.email', $this->guru->email)
        ->assertJsonPath('data.roles', ['duty_officer'])
        ->assertJsonPath('data.status', 'active');
});

it('T05: membership milik sekolah lain pada path sekolah A → 404 untuk semua aksi', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = $this->addMember($schoolB, User::factory()->create(), [Role::SchoolAdmin, Role::Principal]);

    members("/{$foreign->id}")->assertNotFound();
    members("/{$foreign->id}/roles", 'putJson', ['roles' => ['duty_officer'], 'reason' => 'Percobaan lintas sekolah'])->assertNotFound();
    foreach (['suspend', 'end'] as $action) {
        members("/{$foreign->id}/{$action}", 'postJson', ['reason' => 'Percobaan lintas sekolah'])->assertNotFound();
    }
    members("/{$foreign->id}/reactivate", 'postJson')->assertNotFound();

    $this->useSchool($schoolB);
    expect(rolesOf($foreign->id))->toBe(['principal', 'school_admin'])
        ->and(DB::table('school_memberships')->where('id', $foreign->id)->value('status'))->toBe('active');
});

it('T05: ID bukan UUID → 404 (bukan error database)', function () {
    members('/bukan-uuid')->assertNotFound();
});

it('T06: daftar anggota tidak memuat sekolah lain, termasuk keanggotaan lain milik user yang sama', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b', 'name' => 'Sekolah B']);
    $this->addMember($schoolB, $this->admin, [Role::Principal]); // admin juga anggota B
    $this->addMember($schoolB, User::factory()->create(), [Role::SchoolAdmin]);

    $ids = collect(members()->assertOk()->json('data'))->pluck('id');

    expect($ids)->toHaveCount(2)->and($ids->all())->toContain($this->adminMembership->id, $this->guruMembership->id);
});

it('T07: school_id di body diabaikan', function () {
    $schoolB = School::factory()->create(['code' => 'sekolah-b']);

    members("/{$this->guruMembership->id}/roles", 'putJson', [
        'roles' => ['principal'], 'reason' => 'Penugasan baru', 'school_id' => $schoolB->id,
    ])->assertOk();

    $this->useSchool($this->school);
    expect(DB::table('membership_roles')->where('membership_id', $this->guruMembership->id)->value('school_id'))->toBe($this->school->id);
});

it('R03: ubah peran wajib alasan; audit memuat before/after peran dan alasan', function () {
    members("/{$this->guruMembership->id}/roles", 'putJson', ['roles' => ['principal']])
        ->assertUnprocessable()->assertJsonValidationErrors('reason', 'errors');

    members("/{$this->guruMembership->id}/roles", 'putJson', ['roles' => ['principal', 'homeroom_teacher'], 'reason' => 'Naik jabatan'])
        ->assertOk()->assertJsonPath('data.roles', ['homeroom_teacher', 'principal']);

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.member.roles_changed')->first();
    expect(rolesOf($this->guruMembership->id))->toBe(['homeroom_teacher', 'principal'])
        ->and($audit->reason)->toBe('Naik jabatan')
        ->and($audit->actor_user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($this->guruMembership->id)
        ->and(json_decode($audit->before_value, true)['roles'])->toBe(['duty_officer'])
        ->and(json_decode($audit->after_value, true)['roles'])->toBe(['homeroom_teacher', 'principal']);
});

it('R03: tangguhkan dan akhiri wajib alasan; reactivate boleh tanpa alasan', function () {
    foreach (['suspend', 'end'] as $action) {
        members("/{$this->guruMembership->id}/{$action}", 'postJson')->assertUnprocessable()->assertJsonValidationErrors('reason', 'errors');
        members("/{$this->guruMembership->id}/{$action}", 'postJson', ['reason' => 'abc'])->assertUnprocessable();
    }

    members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Cuti panjang'])
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Cuti panjang');
    members("/{$this->guruMembership->id}/reactivate", 'postJson')
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_reason', null);
});

it('anggota yang ditangguhkan langsung ditolak (403) dan setelah diaktifkan kembali dapat masuk lagi', function () {
    members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Investigasi internal'])->assertOk();

    $this->asGuest()->actingAs($this->guru)->getJson('/api/v1/schools/sekolah-a')
        ->assertForbidden()->assertJsonPath('code', 'MEMBERSHIP_SUSPENDED');

    members("/{$this->guruMembership->id}/reactivate", 'postJson')->assertOk();
    $this->asGuest()->actingAs($this->guru)->getJson('/api/v1/schools/sekolah-a')->assertOk();
});

it('anggota yang diakhiri mendapat 404 dan menghilang dari daftar sekolahnya', function () {
    members("/{$this->guruMembership->id}/end", 'postJson', ['reason' => 'Pindah tugas'])
        ->assertOk()->assertJsonPath('data.status', 'ended');

    $this->asGuest()->actingAs($this->guru)->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
    $this->getJson('/api/v1/me/schools')->assertJsonCount(0, 'data');
});

it('transisi status yang tidak valid → 409 INVALID_STATE_TRANSITION', function () {
    members("/{$this->guruMembership->id}/reactivate", 'postJson')->assertConflict()->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Alasan pertama'])->assertOk();
    members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Alasan kedua'])->assertConflict();
    members("/{$this->guruMembership->id}/end", 'postJson', ['reason' => 'Mengakhiri'])->assertOk();
    members("/{$this->guruMembership->id}/end", 'postJson', ['reason' => 'Mengakhiri lagi'])->assertConflict();
    members("/{$this->guruMembership->id}/roles", 'putJson', ['roles' => ['principal'], 'reason' => 'Setelah berakhir'])->assertConflict();
});

it('R02: user tidak dapat mengubah perannya sendiri', function () {
    members("/{$this->adminMembership->id}/roles", 'putJson', ['roles' => ['principal'], 'reason' => 'Ingin turun jabatan'])
        ->assertForbidden()->assertJsonPath('code', 'CANNOT_CHANGE_OWN_ROLES');

    $this->useSchool($this->school);
    expect(rolesOf($this->adminMembership->id))->toBe(['school_admin']);
});

it('R02: admin lain dapat mengubah peran admin', function () {
    $other = User::factory()->create();
    $otherMembership = $this->addMember($this->school, $other, [Role::SchoolAdmin]);

    members("/{$otherMembership->id}/roles", 'putJson', ['roles' => ['principal'], 'reason' => 'Rotasi tugas'])->assertOk();
});

it('R01: admin aktif terakhir tidak bisa ditangguhkan atau diakhiri', function () {
    foreach (['suspend', 'end'] as $action) {
        members("/{$this->adminMembership->id}/{$action}", 'postJson', ['reason' => 'Coba keluar sendiri'])
            ->assertConflict()->assertJsonPath('code', 'LAST_SCHOOL_ADMIN');
    }

    $this->useSchool($this->school);
    expect(DB::table('school_memberships')->where('id', $this->adminMembership->id)->value('status'))->toBe('active');
});

it('R01: admin yang ditangguhkan tidak dihitung; admin kedua yang aktif membuat admin pertama boleh keluar', function () {
    $second = User::factory()->create();
    $secondMembership = $this->addMember($this->school, $second, [Role::SchoolAdmin]);

    // Admin pertama menangguhkan admin kedua (masih ada dirinya) → boleh.
    members("/{$secondMembership->id}/suspend", 'postJson', ['reason' => 'Audit akun'])->assertOk();
    // Kini admin pertama adalah satu-satunya admin aktif.
    members("/{$this->adminMembership->id}/end", 'postJson', ['reason' => 'Mengundurkan diri'])
        ->assertConflict()->assertJsonPath('code', 'LAST_SCHOOL_ADMIN');

    members("/{$secondMembership->id}/reactivate", 'postJson')->assertOk();
    members("/{$this->adminMembership->id}/end", 'postJson', ['reason' => 'Mengundurkan diri'])->assertOk();
});

it('menolak peran tidak dikenal dan peran kosong', function (array $roles, string $key) {
    members("/{$this->guruMembership->id}/roles", 'putJson', ['roles' => $roles, 'reason' => 'Perubahan peran'])
        ->assertUnprocessable()->assertJsonValidationErrors($key, 'errors');
})->with([
    'kosong' => [[], 'roles'],
    'tak dikenal' => [['super_admin'], 'roles.0'],
    'ganda' => [['principal', 'principal'], 'roles.0'],
]);

it('U01: bila penulisan audit gagal, perubahan data dibatalkan', function () {
    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function __construct() {}

        public function record(string $action, ?string $schoolId, string $entityType, ?string $entityId = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?User $actor = null): string
        {
            throw new RuntimeException('audit gagal');
        }
    });

    members("/{$this->guruMembership->id}/roles", 'putJson', ['roles' => ['principal'], 'reason' => 'Naik jabatan'])
        ->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
    members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Cuti panjang'])->assertStatus(500);

    $this->useSchool($this->school);
    expect(rolesOf($this->guruMembership->id))->toBe(['duty_officer'])
        ->and(DB::table('school_memberships')->where('id', $this->guruMembership->id)->value('status'))->toBe('active');
});

it('error 500 tidak membocorkan pesan internal bila debug mati', function () {
    config(['app.debug' => false]);
    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function __construct() {}

        public function record(string $action, ?string $schoolId, string $entityType, ?string $entityId = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?User $actor = null): string
        {
            throw new RuntimeException('rahasia-internal-xyz');
        }
    });

    $response = members("/{$this->guruMembership->id}/suspend", 'postJson', ['reason' => 'Cuti panjang'])->assertStatus(500);

    expect($response->getContent())->not->toContain('rahasia-internal-xyz');
});

// Tahap 2E — admin memicu reset password; tautan hanya dikirim ke email pemilik akun (ADR 0007)

it('admin mengirim tautan reset ke email anggota dan tercatat di audit tanpa token', function () {
    Notification::fake();

    members("/{$this->guruMembership->id}/password-reset", 'postJson', ['reason' => 'Anggota lupa password'])
        ->assertStatus(202);

    Notification::assertSentTo($this->guru, ResetPassword::class);
    $this->useSchool($this->school);
    $log = DB::table('audit_logs')->where('action', 'school.member.password_reset_requested')->where('entity_id', $this->guruMembership->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->reason)->toBe('Anggota lupa password')
        ->and(json_encode($log))->not->toContain('token');
});

it('reset oleh admin ditolak: tanpa alasan, akun sendiri, anggota tidak aktif, atau akun nonaktif', function () {
    Notification::fake();

    members("/{$this->guruMembership->id}/password-reset", 'postJson', [])->assertJsonValidationErrors(['reason']);
    members("/{$this->adminMembership->id}/password-reset", 'postJson', ['reason' => 'Uji coba reset'])->assertStatus(409);

    $suspended = $this->addMember($this->school, User::factory()->create(), [Role::Principal], SchoolMembership::STATUS_SUSPENDED);
    members("/{$suspended->id}/password-reset", 'postJson', ['reason' => 'Uji coba reset'])->assertStatus(409);

    $disabledUser = User::factory()->disabled()->create();
    $disabled = $this->addMember($this->school, $disabledUser, [Role::Principal]);
    members("/{$disabled->id}/password-reset", 'postJson', ['reason' => 'Uji coba reset'])->assertStatus(409);

    Notification::assertNothingSent();
});

it('permintaan reset berulang dibatasi broker dan non-admin ditolak', function () {
    Notification::fake();

    members("/{$this->guruMembership->id}/password-reset", 'postJson', ['reason' => 'Percobaan pertama'])->assertStatus(202);
    members("/{$this->guruMembership->id}/password-reset", 'postJson', ['reason' => 'Percobaan kedua'])
        ->assertStatus(429)->assertJsonPath('code', 'RESET_THROTTLED');

    $this->actingAs($this->guru)->postJson("/api/v1/schools/sekolah-a/members/{$this->adminMembership->id}/password-reset", ['reason' => 'Bukan admin'])
        ->assertForbidden();
});
