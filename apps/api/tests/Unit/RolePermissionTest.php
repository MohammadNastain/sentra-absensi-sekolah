<?php

use App\Domain\Identity\Permission;
use App\Domain\Identity\Role;

// Matriks izin TAHAP_1_FONDASI §5.

it('memetakan izin per peran persis sesuai spesifikasi', function (Role $role, array $expected) {
    $actual = array_map(fn (Permission $p) => $p->value, $role->permissions());
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
})->with([
    'admin' => [Role::SchoolAdmin, ['school.profile.view', 'school.profile.update', 'school.members.view', 'school.members.manage', 'school.audit.view', 'school.academic.view', 'school.academic.manage', 'school.students.view', 'school.students.manage', 'school.schedule.view', 'school.schedule.manage', 'school.credentials.view', 'school.credentials.manage', 'school.devices.view', 'school.devices.manage', 'school.attendance.view', 'school.attendance.assist', 'school.attendance.review', 'school.attendance.verify']],
    'kepala sekolah' => [Role::Principal, ['school.profile.view', 'school.members.view', 'school.audit.view', 'school.academic.view', 'school.students.view', 'school.schedule.view', 'school.attendance.view']],
    'wali kelas' => [Role::HomeroomTeacher, ['school.profile.view', 'school.academic.view', 'school.students.view', 'school.schedule.view', 'school.attendance.view', 'school.attendance.verify']],
    'petugas piket' => [Role::DutyOfficer, ['school.profile.view', 'school.attendance.view', 'school.attendance.assist', 'school.attendance.review', 'school.attendance.verify']],
    'operator terminal' => [Role::TerminalOperator, ['school.profile.view']],
]);

it('menggabungkan izin beberapa peran tanpa duplikat dan terurut', function () {
    $granted = Permission::grantedTo([Role::Principal, Role::SchoolAdmin, Role::HomeroomTeacher]);

    expect(array_map(fn (Permission $p) => $p->value, $granted))->toBe([
        'school.academic.manage', 'school.academic.view', 'school.attendance.assist', 'school.attendance.review', 'school.attendance.verify', 'school.attendance.view', 'school.audit.view', 'school.credentials.manage', 'school.credentials.view', 'school.devices.manage', 'school.devices.view', 'school.members.manage',
        'school.members.view', 'school.profile.update', 'school.profile.view', 'school.schedule.manage', 'school.schedule.view', 'school.students.manage', 'school.students.view',
    ]);
});

it('hanya admin sekolah yang dapat mengelola anggota dan profil', function () {
    foreach (Role::cases() as $role) {
        $can = in_array(Permission::SchoolMembersManage, $role->permissions(), true);
        expect($can)->toBe($role === Role::SchoolAdmin);
    }
});

it('hanya admin sekolah yang dapat mengelola data akademik; wali kelas belum punya akses sampai scope kelas ada', function () {
    foreach (Role::cases() as $role) {
        expect(in_array(Permission::SchoolAcademicManage, $role->permissions(), true))->toBe($role === Role::SchoolAdmin);
        expect(in_array(Permission::SchoolAcademicView, $role->permissions(), true))->toBe(in_array($role, [Role::SchoolAdmin, Role::Principal, Role::HomeroomTeacher], true));
    }
});

it('kode peran sinkron dengan seed tabel roles dan CHECK undangan', function () {
    expect(Role::values())->toBe(['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator']);
});
