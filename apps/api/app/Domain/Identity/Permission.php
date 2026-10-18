<?php

namespace App\Domain\Identity;

/**
 * Izin dalam konteks sekolah (TAHAP_1_FONDASI §5). Penegakan selalu di backend;
 * frontend hanya menyembunyikan menu.
 */
enum Permission: string
{
    case SchoolProfileView = 'school.profile.view';
    case SchoolProfileUpdate = 'school.profile.update';
    case SchoolMembersView = 'school.members.view';
    case SchoolMembersManage = 'school.members.manage';
    case SchoolAuditView = 'school.audit.view';
    case SchoolAcademicView = 'school.academic.view';
    case SchoolAcademicManage = 'school.academic.manage';
    case SchoolStudentsView = 'school.students.view';
    case SchoolStudentsManage = 'school.students.manage';
    case SchoolScheduleView = 'school.schedule.view';
    case SchoolScheduleManage = 'school.schedule.manage';

    /**
     * @param  iterable<Role>  $roles
     * @return list<self>
     */
    public static function grantedTo(iterable $roles): array
    {
        $granted = [];

        foreach ($roles as $role) {
            foreach ($role->permissions() as $permission) {
                $granted[$permission->value] = $permission;
            }
        }

        ksort($granted);

        return array_values($granted);
    }
}
