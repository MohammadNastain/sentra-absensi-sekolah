<?php

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// T01–T04, T11, T13 (TAHAP_1_FONDASI §8)

beforeEach(function () {
    $this->schoolA = School::factory()->create(['code' => 'sekolah-a']);
    $this->schoolB = School::factory()->create(['code' => 'sekolah-b']);
});

it('T01: bukan anggota mendapat 404, sama seperti sekolah yang tidak ada', function () {
    $user = User::factory()->create();
    $this->addMember($this->schoolA, $user, [Role::SchoolAdmin]);

    $this->actingAs($user)->getJson('/api/v1/schools/sekolah-a')->assertOk();

    $notMember = $this->getJson('/api/v1/schools/sekolah-b')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $missing = $this->getJson('/api/v1/schools/tidak-ada')->assertNotFound();

    expect($notMember->json('title'))->toBe($missing->json('title'));
});

it('T02: keanggotaan suspended → 403, ended → 404', function () {
    $suspended = User::factory()->create();
    $ended = User::factory()->create();
    $this->addMember($this->schoolA, $suspended, [Role::SchoolAdmin], SchoolMembership::STATUS_SUSPENDED);
    $this->addMember($this->schoolA, $ended, [Role::SchoolAdmin], SchoolMembership::STATUS_ENDED);

    $this->actingAs($suspended)->getJson('/api/v1/schools/sekolah-a')
        ->assertForbidden()->assertJsonPath('code', 'MEMBERSHIP_SUSPENDED');

    $this->app['auth']->forgetGuards();
    $this->actingAs($ended)->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});

it('T03: sekolah suspended → 403 untuk semua anggotanya, tetapi tetap 404 untuk bukan anggota', function () {
    $suspendedSchool = School::factory()->status(School::STATUS_SUSPENDED)->create(['code' => 'sekolah-c']);
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $this->addMember($suspendedSchool, $member, [Role::SchoolAdmin]);

    $this->actingAs($member)->getJson('/api/v1/schools/sekolah-c')
        ->assertForbidden()->assertJsonPath('code', 'SCHOOL_UNAVAILABLE');

    $this->app['auth']->forgetGuards();
    $this->actingAs($outsider)->getJson('/api/v1/schools/sekolah-c')->assertNotFound();
});

it('T04: peran di sekolah A tidak berlaku di sekolah B', function () {
    $user = User::factory()->create();
    $this->addMember($this->schoolA, $user, [Role::SchoolAdmin]);
    $this->addMember($this->schoolB, $user, [Role::DutyOfficer]);

    $this->actingAs($user);

    $this->getJson('/api/v1/schools/sekolah-a')
        ->assertOk()
        ->assertJsonPath('data.my_roles', ['school_admin'])
        ->assertJsonPath('data.my_permissions', fn (array $p) => in_array('school.members.manage', $p, true));

    $this->getJson('/api/v1/schools/sekolah-b')
        ->assertOk()
        ->assertJsonPath('data.my_roles', ['duty_officer'])
        ->assertJsonPath('data.my_permissions', ['school.attendance.assist', 'school.attendance.review', 'school.attendance.verify', 'school.attendance.view', 'school.leave.approve', 'school.leave.manage', 'school.profile.view']);
});

it('T11: konteks DB di-reset setelah setiap request, termasuk antar sekolah berbeda', function () {
    $user = User::factory()->create();
    $this->addMember($this->schoolA, $user, [Role::SchoolAdmin]);
    $this->addMember($this->schoolB, $user, [Role::Principal]);
    $this->actingAs($user);

    $this->getJson('/api/v1/schools/sekolah-a')->assertOk()->assertJsonPath('data.code', 'sekolah-a');
    expect($this->dbContext()->schoolId())->toBeNull()
        ->and($this->dbContext()->userId())->toBeNull()
        ->and(DB::scalar('SELECT count(*) FROM school_memberships'))->toBe(0);

    $this->getJson('/api/v1/schools/sekolah-b')->assertOk()->assertJsonPath('data.code', 'sekolah-b');
    expect($this->dbContext()->schoolId())->toBeNull();
});

it('T11: konteks sisa dari luar request tidak dipakai oleh request berikutnya', function () {
    $user = User::factory()->create();
    $this->addMember($this->schoolA, $user, [Role::SchoolAdmin]);

    // Simulasi koneksi yang "kotor": konteks sekolah B tertinggal sebelum request masuk.
    $this->dbContext()->setSchool($this->schoolB->id);

    $this->actingAs($user)->getJson('/api/v1/schools/sekolah-b')->assertNotFound();
});

it('T13: Super Admin bukan anggota mendapat 404 pada rute sekolah', function () {
    $admin = $this->makePlatformAdmin(User::factory()->withConfirmedTwoFactor()->create());

    $this->actingAsPlatformAdminWithTwoFactor($admin)->getJson('/api/v1/schools/sekolah-a')->assertNotFound();
});

it('menolak izin yang tidak dimiliki peran dengan 403 PERMISSION_DENIED', function () {
    // Rute profil butuh school.profile.view yang dimiliki semua peran; keanggotaan tanpa peran tidak.
    $user = User::factory()->create();
    $this->addMember($this->schoolA, $user, []);

    $this->actingAs($user)->getJson('/api/v1/schools/sekolah-a')
        ->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');
});

it('menampilkan daftar sekolah milik user tanpa sekolah lain dan tanpa keanggotaan ended', function () {
    $user = User::factory()->create();
    $schoolC = School::factory()->create(['code' => 'sekolah-c', 'name' => 'ZZZ']);
    $this->addMember($this->schoolA, $user, [Role::Principal, Role::HomeroomTeacher]);
    $this->addMember($this->schoolB, User::factory()->create(), [Role::SchoolAdmin]);
    $this->addMember($schoolC, $user, [Role::SchoolAdmin], SchoolMembership::STATUS_ENDED);

    $this->actingAs($user)->getJson('/api/v1/me/schools')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.school.code', 'sekolah-a')
        ->assertJsonPath('data.0.roles', ['homeroom_teacher', 'principal'])
        ->assertJsonPath('data.0.permissions', ['school.academic.view', 'school.attendance.correction_request', 'school.attendance.verify', 'school.attendance.view', 'school.audit.view', 'school.leave.manage', 'school.members.view', 'school.profile.view', 'school.reports.export', 'school.schedule.view', 'school.students.view']);
});
