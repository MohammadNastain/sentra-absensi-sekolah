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
    'admin' => [Role::SchoolAdmin, ['school.profile.view', 'school.profile.update', 'school.members.view', 'school.members.manage', 'school.audit.view']],
    'kepala sekolah' => [Role::Principal, ['school.profile.view', 'school.members.view', 'school.audit.view']],
    'wali kelas' => [Role::HomeroomTeacher, ['school.profile.view']],
    'petugas piket' => [Role::DutyOfficer, ['school.profile.view']],
    'operator terminal' => [Role::TerminalOperator, ['school.profile.view']],
]);

it('menggabungkan izin beberapa peran tanpa duplikat dan terurut', function () {
    $granted = Permission::grantedTo([Role::Principal, Role::SchoolAdmin, Role::HomeroomTeacher]);

    expect(array_map(fn (Permission $p) => $p->value, $granted))->toBe([
        'school.audit.view', 'school.members.manage', 'school.members.view', 'school.profile.update', 'school.profile.view',
    ]);
});

it('hanya admin sekolah yang dapat mengelola anggota dan profil', function () {
    foreach (Role::cases() as $role) {
        $can = in_array(Permission::SchoolMembersManage, $role->permissions(), true);
        expect($can)->toBe($role === Role::SchoolAdmin);
    }
});

it('kode peran sinkron dengan seed tabel roles dan CHECK undangan', function () {
    expect(Role::values())->toBe(['school_admin', 'principal', 'homeroom_teacher', 'duty_officer', 'terminal_operator']);
});
