<?php

use App\Domain\Identity\Role;
use App\Domain\Tenancy\GuardianScope;
use App\Domain\Tenancy\TenantContext;
use App\Mail\InvitationMail;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// ADR 0011 §1, §2, §8 (G2) — admin menambah, mengundang, menautkan, dan melepas wali.

beforeEach(function () {
    Mail::fake();
    config(['sentra.parent_portal_released' => true]);

    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    foreach (['parent_portal', 'parent_portal.attendance', 'parent_portal.leave'] as $feature) {
        setFeatureOverride($this->school->id, $feature, 'grant');
    }
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    $this->principal = User::factory()->create();
    $this->addMember($this->school, $this->principal, [Role::Principal]);

    $student = fn (string $n) => $this->inSchool($this->school, fn () => Student::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Siswa '.$n, 'student_number' => $n, 'status' => 'active',
    ])->id);
    $this->anak1 = $student('1');
    $this->anak2 = $student('2');

    $this->url = '/api/v1/schools/sekolah-a';
    $this->add = fn (string $studentId, array $over = []) => $this->actingAs($this->admin)->postJson("{$this->url}/students/{$studentId}/guardians", array_merge([
        'full_name' => 'Bapak Wali', 'email' => 'wali@contoh.test', 'relationship' => 'ayah',
    ], $over));
});

it('menambah wali baru menautkannya ke anak sebagai wali utama dan mengirim undangan wali', function () {
    ($this->add)($this->anak1)->assertCreated()
        ->assertJsonPath('data.full_name', 'Bapak Wali')
        ->assertJsonPath('data.email', 'wali@contoh.test')
        ->assertJsonPath('data.account', 'invited')
        ->assertJsonPath('data.children.0.student_id', $this->anak1)
        ->assertJsonPath('data.children.0.relationship', 'ayah')
        ->assertJsonPath('data.children.0.is_primary', true);

    Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('wali@contoh.test') && ! str_contains($m->url, 'Siswa'));
    $this->useSchool($this->school);
    expect(DB::table('membership_invitations')->value('role_codes'))->toBe('{guardian}');
});

it('wali menerima undangan lalu menjadi pemilik anaknya di GuardianScope', function () {
    ($this->add)($this->anak1)->assertCreated();
    $token = $this->lastInvitationToken();

    $this->asGuest()->postJson("/api/v1/invitations/{$token}/accept", [
        'name' => 'Bapak Wali', 'password' => 'kata-sandi-baru-123', 'password_confirmation' => 'kata-sandi-baru-123',
    ])->assertCreated();

    $this->actingAs($this->admin)->getJson("{$this->url}/guardians")->assertOk()
        ->assertJsonPath('data.0.account', 'active')->assertJsonPath('data.0.email', 'wali@contoh.test');

    $user = User::query()->where('email', 'wali@contoh.test')->firstOrFail();
    $this->inSchool($this->school, function () use ($user) {
        $tenant = app(TenantContext::class);
        $tenant->enter($this->school, SchoolMembership::query()->where('user_id', $user->id)->firstOrFail(), [Role::Guardian]);
        expect((new GuardianScope($tenant))->childIds())->toBe([$this->anak1]);
        $tenant->clear();
    });
});

it('hanya admin sekolah yang mengelola wali; kepala sekolah dan wali ditolak', function () {
    $this->actingAs($this->principal)->getJson("{$this->url}/guardians")->assertForbidden();
    $this->actingAs($this->principal)->postJson("{$this->url}/students/{$this->anak1}/guardians", ['full_name' => 'X', 'email' => 'x@contoh.test', 'relationship' => 'ibu'])->assertForbidden();
});

it('validasi: hubungan, surel, dan nama wajib sah; siswa tidak ada → 404', function () {
    ($this->add)($this->anak1, ['relationship' => 'paman'])->assertUnprocessable()->assertJsonValidationErrors('relationship');
    ($this->add)($this->anak1, ['email' => 'bukan-surel'])->assertUnprocessable()->assertJsonValidationErrors('email');
    ($this->add)($this->anak1, ['full_name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('full_name');
    ($this->add)('00000000-0000-7000-8000-000000000009')->assertNotFound();
});

it('wali yang sama ditautkan ke anak lain tanpa undangan kedua', function () {
    $guardianId = ($this->add)($this->anak1)->assertCreated()->json('data.id');

    $this->actingAs($this->admin)->postJson("{$this->url}/students/{$this->anak2}/guardians", ['guardian_id' => $guardianId, 'relationship' => 'ayah'])
        ->assertCreated()->assertJsonCount(2, 'data.children');

    Mail::assertSent(InvitationMail::class, 1);
    $this->actingAs($this->admin)->postJson("{$this->url}/students/{$this->anak2}/guardians", ['guardian_id' => $guardianId, 'relationship' => 'ayah'])->assertStatus(409);
});

it('menambah wali dengan surel yang masih punya undangan berlaku ditolak tanpa wali yatim', function () {
    ($this->add)($this->anak1)->assertCreated();
    ($this->add)($this->anak2)->assertStatus(409)->assertJsonPath('code', 'INVITATION_ALREADY_PENDING');

    $this->inSchool($this->school, fn () => expect(Guardian::query()->count())->toBe(1));
});

it('satu siswa maksimal empat wali', function () {
    foreach (range(1, 4) as $n) {
        ($this->add)($this->anak1, ['full_name' => "Wali {$n}", 'email' => "wali{$n}@contoh.test", 'relationship' => 'wali'])->assertCreated();
    }

    ($this->add)($this->anak1, ['full_name' => 'Wali 5', 'email' => 'wali5@contoh.test'])->assertStatus(409);
    $this->inSchool($this->school, fn () => expect(Guardian::query()->count())->toBe(4));
});

it('selalu ada tepat satu wali utama: wali pertama utama, mengganti utama, dan melepas utama', function () {
    $a = ($this->add)($this->anak1, ['email' => 'a@contoh.test'])->json('data.id');
    $b = ($this->add)($this->anak1, ['email' => 'b@contoh.test', 'full_name' => 'Ibu', 'relationship' => 'ibu'])->json('data.id');
    $primary = fn () => $this->inSchool($this->school, fn () => StudentGuardian::query()->where('student_id', $this->anak1)->where('is_primary', true)->pluck('guardian_id')->all());

    expect($primary())->toBe([$a]);

    $this->actingAs($this->admin)->patchJson("{$this->url}/students/{$this->anak1}/guardians/{$b}", ['is_primary' => true])->assertOk();
    expect($primary())->toBe([$b]);

    // Tidak boleh menurunkan wali utama tanpa menunjuk penggantinya.
    $this->actingAs($this->admin)->patchJson("{$this->url}/students/{$this->anak1}/guardians/{$b}", ['is_primary' => false])->assertStatus(409);

    $this->actingAs($this->admin)->deleteJson("{$this->url}/students/{$this->anak1}/guardians/{$b}")->assertNoContent();
    expect($primary())->toBe([$a]);
});

it('melepas tautan menutup akses anak itu; menonaktifkan wali menutup semua anaknya', function () {
    $id = ($this->add)($this->anak1)->json('data.id');
    $this->actingAs($this->admin)->postJson("{$this->url}/students/{$this->anak2}/guardians", ['guardian_id' => $id, 'relationship' => 'ayah'])->assertCreated();
    $token = $this->lastInvitationToken();
    $this->asGuest()->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Wali', 'password' => 'kata-sandi-baru-123', 'password_confirmation' => 'kata-sandi-baru-123'])->assertCreated();
    $user = User::query()->where('email', 'wali@contoh.test')->firstOrFail();
    $children = function () use ($user) {
        $result = [];
        $this->inSchool($this->school, function () use ($user, &$result) {
            $tenant = app(TenantContext::class);
            $tenant->enter($this->school, SchoolMembership::query()->where('user_id', $user->id)->firstOrFail(), [Role::Guardian]);
            $result = (new GuardianScope($tenant))->childIds();
            $tenant->clear();
        });
        sort($result);

        return $result;
    };

    $both = [$this->anak1, $this->anak2];
    sort($both);
    expect($children())->toBe($both);

    $this->actingAs($this->admin)->deleteJson("{$this->url}/students/{$this->anak2}/guardians/{$id}")->assertNoContent();
    expect($children())->toBe([$this->anak1]);

    $this->actingAs($this->admin)->patchJson("{$this->url}/guardians/{$id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    expect($children())->toBe([]);
});

it('mengirim ulang undangan menerbitkan token baru; undangan dicabut diganti undangan baru', function () {
    $id = ($this->add)($this->anak1)->json('data.id');
    $first = $this->lastInvitationToken();

    $this->actingAs($this->admin)->postJson("{$this->url}/guardians/{$id}/invitation")->assertOk()->assertJsonPath('data.account', 'invited');
    $second = $this->lastInvitationToken();
    expect($second)->not->toBe($first);

    $this->useSchool($this->school);
    $invitationId = DB::table('membership_invitations')->value('id');
    $this->actingAs($this->admin)->postJson("{$this->url}/invitations/{$invitationId}/revoke")->assertOk();
    $this->actingAs($this->admin)->getJson("{$this->url}/guardians")->assertJsonPath('data.0.account', 'none');

    $this->actingAs($this->admin)->postJson("{$this->url}/guardians/{$id}/invitation")->assertOk()->assertJsonPath('data.account', 'invited');
    $this->useSchool($this->school);
    expect(DB::table('membership_invitations')->whereNull('revoked_at')->count())->toBe(1);
});

it('anggota sekolah yang sudah ada (guru) menjadi wali tanpa undangan dan perannya tidak hilang saat peran diubah', function () {
    $teacher = User::factory()->create(['email' => 'guru@contoh.test']);
    $membership = $this->addMember($this->school, $teacher, [Role::Teacher]);

    ($this->add)($this->anak1, ['email' => 'guru@contoh.test', 'full_name' => 'Pak Guru'])->assertCreated()->assertJsonPath('data.account', 'active');
    Mail::assertNothingSent();

    $this->useSchool($this->school);
    $roles = fn () => DB::table('membership_roles')->where('membership_id', $membership->id)->pluck('role_code')->sort()->values()->all();
    expect($roles())->toBe(['guardian', 'teacher']);

    // Admin mengubah peran lain; peran wali dipertahankan.
    $this->actingAs($this->admin)->putJson("{$this->url}/members/{$membership->id}/roles", ['roles' => ['homeroom_teacher'], 'reason' => 'Pindah tugas'])->assertOk();
    $this->useSchool($this->school);
    expect($roles())->toBe(['guardian', 'homeroom_teacher']);
});

it('akun yang sudah terdaftar sebagai wali tidak didaftarkan ulang', function () {
    $teacher = User::factory()->create(['email' => 'guru@contoh.test']);
    $this->addMember($this->school, $teacher, [Role::Teacher]);
    ($this->add)($this->anak1, ['email' => 'guru@contoh.test'])->assertCreated();

    ($this->add)($this->anak2, ['email' => 'guru@contoh.test'])->assertStatus(409);
});

it('wali sekolah lain tidak terlihat dan tidak dapat ditautkan', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    setFeatureOverride($other->id, 'parent_portal', 'grant');
    $foreign = $this->inSchool($other, fn () => Guardian::query()->create(['school_id' => $other->id, 'full_name' => 'Wali B'])->id);

    $this->actingAs($this->admin)->getJson("{$this->url}/guardians")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->admin)->postJson("{$this->url}/students/{$this->anak1}/guardians", ['guardian_id' => $foreign, 'relationship' => 'ayah'])->assertNotFound();
    $this->actingAs($this->admin)->patchJson("{$this->url}/guardians/{$foreign}", ['status' => 'inactive'])->assertNotFound();
});

it('perubahan wali tercatat di audit tanpa nomor telepon', function () {
    $id = ($this->add)($this->anak1, ['phone' => '081234567890'])->json('data.id');
    $this->actingAs($this->admin)->patchJson("{$this->url}/guardians/{$id}", ['full_name' => 'Bapak Baru'])->assertOk();

    $this->useSchool($this->school);
    $rows = DB::table('audit_logs')->where('entity_type', 'like', '%guardian')->orderBy('created_at')->get(['action', 'after_value']);
    expect($rows->pluck('action')->all())->toContain('school.guardian.created', 'school.student_guardian.linked', 'school.guardian.updated')
        ->and(json_encode($rows))->not->toContain('081234567890');
});

it('menonaktifkan siswa menyembunyikannya dari wali tanpa menghapus tautan', function () {
    ($this->add)($this->anak1)->assertCreated();
    $this->inSchool($this->school, fn () => Student::query()->whereKey($this->anak1)->update(['status' => 'inactive']));

    $this->actingAs($this->admin)->getJson("{$this->url}/students/{$this->anak1}/guardians")->assertOk()->assertJsonCount(1, 'data');
});
