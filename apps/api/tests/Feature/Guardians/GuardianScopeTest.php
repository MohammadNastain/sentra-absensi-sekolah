<?php

use App\Domain\Entitlement\EntitlementResolver;
use App\Domain\Entitlement\Feature;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\GuardianScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;

// ADR 0011 §1, §4, §7 — peran wali, izin portal, dan batas "anak sendiri" (GuardianScope).

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);

    $student = fn (string $number, string $status = 'active') => $this->inSchool($this->school, fn () => Student::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Siswa '.$number, 'student_number' => $number, 'status' => $status,
    ])->id);
    $this->anak1 = $student('1');
    $this->anak2 = $student('2');
    $this->anakNonaktif = $student('3', 'inactive');
    $this->orangLain = $student('4');

    $this->guardianUser = User::factory()->create();
    $this->membership = $this->addMember($this->school, $this->guardianUser, [Role::Guardian]);
    $this->guardianId = $this->inSchool($this->school, fn () => Guardian::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Bapak Wali', 'membership_id' => $this->membership->id,
    ])->id);
    $link = fn (string $studentId, string $guardianId, string $relationship = 'ayah') => $this->inSchool($this->school, fn () => StudentGuardian::query()->create([
        'school_id' => $this->school->id, 'student_id' => $studentId, 'guardian_id' => $guardianId, 'relationship' => $relationship,
    ]));
    $this->link = $link;
    foreach ([$this->anak1, $this->anak2, $this->anakNonaktif] as $childId) {
        $link($childId, $this->guardianId);
    }
});

/** Menjalankan $check dengan GuardianScope bagi pengguna ini, di dalam konteks RLS sekolah (seperti middleware pada request nyata). */
function withGuardianScope(User $user, array $roles, Closure $check): void
{
    test()->inSchool(test()->school, function () use ($user, $roles, $check) {
        $tenant = app(TenantContext::class);
        $tenant->enter(test()->school, SchoolMembership::query()->where('user_id', $user->id)->firstOrFail(), $roles);
        $check(new GuardianScope($tenant));
        $tenant->clear();
    });
}

it('wali mendapat izin portal saja, tanpa izin operasional atau LMS', function () {
    $granted = array_map(fn (Permission $p) => $p->value, Role::Guardian->permissions());
    sort($granted);

    expect($granted)->toBe(['school.portal.leave', 'school.portal.view', 'school.profile.view']);
    foreach ([Role::SchoolAdmin, Role::Principal, Role::HomeroomTeacher, Role::DutyOfficer, Role::TerminalOperator, Role::Teacher, Role::Student] as $role) {
        expect($role->permissions())->not->toContain(Permission::SchoolPortalView)->not->toContain(Permission::SchoolPortalLeave);
    }
    expect(Role::SchoolAdmin->permissions())->toContain(Permission::SchoolGuardiansManage)
        ->and(Role::Principal->permissions())->not->toContain(Permission::SchoolGuardiansManage);
});

it('wali tidak bisa diberikan lewat undangan umum atau ubah peran', function () {
    expect(Role::assignableValues())->not->toContain('guardian')->not->toContain('student');
});

it('wali hanya memiliki anak aktif yang ditautkan kepadanya', function () {
    withGuardianScope($this->guardianUser, [Role::Guardian], function (GuardianScope $scope) {
        expect($scope->childIds())->toEqualCanonicalizing([$this->anak1, $this->anak2])
            ->and($scope->owns($this->anak1))->toBeTrue()
            ->and($scope->owns($this->anakNonaktif))->toBeFalse()
            ->and($scope->owns($this->orangLain))->toBeFalse();
    });
});

it('wali dinonaktifkan atau belum menerima undangan tidak memiliki anak', function () {
    $this->inSchool($this->school, fn () => Guardian::query()->whereKey($this->guardianId)->update(['status' => 'inactive']));
    withGuardianScope($this->guardianUser, [Role::Guardian], fn (GuardianScope $scope) => expect($scope->childIds())->toBe([]));

    $this->inSchool($this->school, fn () => Guardian::query()->whereKey($this->guardianId)->update(['status' => 'active', 'membership_id' => null]));
    withGuardianScope($this->guardianUser, [Role::Guardian], fn (GuardianScope $scope) => expect($scope->childIds())->toBe([]));
});

it('tautan yang dilepas menutup akses anak itu saja', function () {
    $this->inSchool($this->school, fn () => StudentGuardian::query()->where('student_id', $this->anak2)->delete());

    withGuardianScope($this->guardianUser, [Role::Guardian], fn (GuardianScope $scope) => expect($scope->childIds())->toBe([$this->anak1]));
});

it('wali tidak melihat anak wali lain', function () {
    $other = User::factory()->create();
    $otherMembership = $this->addMember($this->school, $other, [Role::Guardian]);
    $otherGuardian = $this->inSchool($this->school, fn () => Guardian::query()->create(['school_id' => $this->school->id, 'full_name' => 'Ibu Lain', 'membership_id' => $otherMembership->id])->id);
    ($this->link)($this->orangLain, $otherGuardian, 'ibu');

    withGuardianScope($other, [Role::Guardian], fn (GuardianScope $scope) => expect($scope->childIds())->toBe([$this->orangLain])->and($scope->owns($this->anak1))->toBeFalse());
    withGuardianScope($this->guardianUser, [Role::Guardian], fn (GuardianScope $scope) => expect($scope->owns($this->orangLain))->toBeFalse());
});

it('orang yang juga guru tidak memiliki anak tanpa peran wali pada keanggotaannya', function () {
    withGuardianScope($this->guardianUser, [Role::Teacher], fn (GuardianScope $scope) => expect($scope->isGuardian())->toBeFalse()->and($scope->childIds())->toBe([]));
});

it('wali satu sekolah tidak melihat anak di sekolah lain', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $this->addMember($other, $this->guardianUser, [Role::Guardian]);

    $this->inSchool($other, function () use ($other) {
        $tenant = app(TenantContext::class);
        $tenant->enter($other, SchoolMembership::query()->where('school_id', $other->id)->where('user_id', $this->guardianUser->id)->firstOrFail(), [Role::Guardian]);
        expect((new GuardianScope($tenant))->childIds())->toBe([]);
        $tenant->clear();
    });
});

it('fitur portal orang tua: anak butuh induk dan prasyarat, belum dirilis, dan hanya ada di Standard dan Premium', function () {
    expect(Feature::ParentPortalAttendance->prerequisites())->toBe([Feature::ParentPortal, Feature::Attendance])
        ->and(Feature::ParentPortalLeave->prerequisites())->toBe([Feature::ParentPortal, Feature::AttendanceLeave])
        ->and(Feature::ParentPortalLms->prerequisites())->toBe([Feature::ParentPortal, Feature::LmsAssignments]);

    $resolver = app(EntitlementResolver::class);
    $enabled = fn (string $planCode) => $this->dbContext()->runAsPlatform(function () use ($resolver, $planCode) {
        $school = School::factory()->create();
        DB::table('school_subscriptions')->where('school_id', $school->id)->update(['plan_code' => $planCode]);

        return $resolver->enabled($school->id);
    });

    // Belum dirilis: tidak efektif walau paketnya memuat fitur.
    expect($enabled('premium'))->not->toContain('parent_portal');

    config(['sentra.parent_portal_released' => true, 'sentra.lms_released' => false]);
    expect($enabled('standard'))->toContain('parent_portal', 'parent_portal.attendance', 'parent_portal.leave')->not->toContain('parent_portal.lms')
        ->and($enabled('basic'))->not->toContain('parent_portal')
        ->and($enabled('premium'))->not->toContain('parent_portal.lms');

    // Tugas dan nilai wali baru efektif bila LMS juga dirilis (tinjauan D-26).
    config(['sentra.lms_released' => true]);
    expect($enabled('premium'))->toContain('parent_portal.lms')
        ->and($enabled('standard'))->not->toContain('parent_portal.lms');
});
