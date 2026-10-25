<?php

use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\LmsScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ADR 0010 §1–§2 — peran guru/siswa dan unit akses kelas-mapel (LmsScope).

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);

    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $class = fn (string $name) => $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => 7, 'status' => 'active',
    ])->id);
    $subject = fn (string $name) => $this->inSchool($this->school, fn () => Subject::query()->create(['school_id' => $this->school->id, 'name' => $name])->id);

    $this->class7a = $class('7A');
    $this->class7b = $class('7B');
    $this->math = $subject('Matematika');
    $this->art = $subject('Seni');

    $this->teacherUser = User::factory()->create();
    $teacherMembership = $this->addMember($this->school, $this->teacherUser, [Role::Teacher]);
    $teacherId = $this->inSchool($this->school, fn () => Teacher::query()->create([
        'school_id' => $this->school->id, 'full_name' => 'Bu Guru', 'membership_id' => $teacherMembership->id, 'status' => 'active',
    ])->id);
    $this->inSchool($this->school, fn () => TeachingSlot::query()->create([
        'school_id' => $this->school->id, 'class_id' => $this->class7a, 'subject_id' => $this->math, 'teacher_id' => $teacherId,
        'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
    ]));

    $this->studentUser = User::factory()->create();
    $studentMembership = $this->addMember($this->school, $this->studentUser, [Role::Student]);
    $this->inSchool($this->school, function () use ($studentMembership) {
        $student = Student::query()->create([
            'school_id' => $this->school->id, 'full_name' => 'Siswa A', 'student_number' => '1', 'status' => 'active', 'membership_id' => $studentMembership->id,
        ]);
        ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $student->id, 'class_id' => $this->class7a, 'start_date' => '2020-01-01']);
    });
});

/** Menjalankan $check dengan LmsScope bagi pengguna ini, di dalam konteks RLS sekolah (seperti middleware pada request nyata). */
function withScope(User $user, array $roles, Closure $check): void
{
    test()->inSchool(test()->school, function () use ($user, $roles, $check) {
        $tenant = app(TenantContext::class);
        $tenant->enter(test()->school, SchoolMembership::query()->where('user_id', $user->id)->firstOrFail(), $roles);
        $check(new LmsScope($tenant));
        $tenant->clear();
    });
}

it('memetakan izin LMS per peran', function () {
    $has = fn (Role $r, Permission $p) => in_array($p, $r->permissions(), true);

    expect($has(Role::Teacher, Permission::SchoolLmsManage))->toBeTrue()
        ->and($has(Role::Teacher, Permission::SchoolLmsGrade))->toBeTrue()
        ->and($has(Role::Teacher, Permission::SchoolLmsSubmit))->toBeFalse()
        ->and($has(Role::Student, Permission::SchoolLmsSubmit))->toBeTrue()
        ->and($has(Role::Student, Permission::SchoolLmsManage))->toBeFalse()
        ->and($has(Role::Student, Permission::SchoolLmsGrade))->toBeFalse()
        ->and($has(Role::SchoolAdmin, Permission::SchoolLmsGrade))->toBeTrue()
        ->and($has(Role::SchoolAdmin, Permission::SchoolLmsSubmit))->toBeFalse()
        ->and($has(Role::Principal, Permission::SchoolLmsView))->toBeTrue()
        ->and($has(Role::Principal, Permission::SchoolLmsManage))->toBeFalse();
});

it('guru hanya mengelola dan melihat pasangan kelas-mapel dari jadwal mengajarnya', function () {
    withScope($this->teacherUser, [Role::Teacher], function (LmsScope $scope) {
        expect($scope->canManage($this->class7a, $this->math))->toBeTrue()
            ->and($scope->canManage($this->class7a, $this->art))->toBeFalse()
            ->and($scope->canManage($this->class7b, $this->math))->toBeFalse()
            ->and($scope->canView($this->class7a, $this->art))->toBeFalse();
    });
});

it('guru yang dinonaktifkan kehilangan akses', function () {
    $this->inSchool($this->school, fn () => Teacher::query()->update(['status' => 'inactive']));

    withScope($this->teacherUser, [Role::Teacher], fn (LmsScope $scope) => expect($scope->canManage($this->class7a, $this->math))->toBeFalse());
});

it('siswa hanya melihat kelas tempat ia terdaftar dan tidak pernah mengelola', function () {
    withScope($this->studentUser, [Role::Student], function (LmsScope $scope) {
        expect($scope->canView($this->class7a, $this->math))->toBeTrue()
            ->and($scope->canView($this->class7b, $this->math))->toBeFalse()
            ->and($scope->canManage($this->class7a, $this->math))->toBeFalse();
    });
});

it('siswa yang sudah keluar dari kelas atau tidak aktif tidak melihat apa pun', function () {
    $this->inSchool($this->school, fn () => ClassEnrollment::query()->update(['end_date' => '2020-06-30']));
    withScope($this->studentUser, [Role::Student], fn (LmsScope $scope) => expect($scope->canView($this->class7a, $this->math))->toBeFalse());

    $this->inSchool($this->school, fn () => ClassEnrollment::query()->update(['end_date' => null]));
    $this->inSchool($this->school, fn () => Student::query()->update(['status' => 'inactive']));
    withScope($this->studentUser, [Role::Student], fn (LmsScope $scope) => expect($scope->canView($this->class7a, $this->math))->toBeFalse());
});

it('admin mengelola semua dan kepala sekolah melihat semua tanpa mengelola', function () {
    withScope($this->teacherUser, [Role::SchoolAdmin], fn (LmsScope $scope) => expect($scope->canManage($this->class7b, $this->art))->toBeTrue());

    withScope($this->teacherUser, [Role::Principal], function (LmsScope $scope) {
        expect($scope->canView($this->class7b, $this->art))->toBeTrue()
            ->and($scope->canManage($this->class7b, $this->art))->toBeFalse();
    });
});

it('siswa tidak bisa diundang, dan peran akun siswa tidak bisa diubah dari manajemen anggota', function () {
    $admin = User::factory()->create();
    $this->addMember($this->school, $admin, [Role::SchoolAdmin]);
    $studentMembershipId = $this->inSchool($this->school, fn () => DB::table('school_memberships')->where('user_id', $this->studentUser->id)->value('id'));

    $this->actingAs($admin)->postJson('/api/v1/schools/sekolah-a/invitations', ['email' => 'x@contoh.test', 'roles' => ['student']])
        ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    $this->actingAs($admin)->postJson('/api/v1/schools/sekolah-a/invitations', ['email' => 'guru@contoh.test', 'roles' => ['teacher']])
        ->assertCreated();
    $this->actingAs($admin)->putJson("/api/v1/schools/sekolah-a/members/{$studentMembershipId}/roles", ['roles' => ['teacher'], 'reason' => 'uji perubahan'])
        ->assertStatus(409);
});
