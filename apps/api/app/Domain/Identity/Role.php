<?php

namespace App\Domain\Identity;

/**
 * Peran tetap per keanggotaan sekolah (ARCHITECTURE §7). Harus sinkron dengan seed tabel `roles`
 * dan CHECK membership_invitations.role_codes.
 */
enum Role: string
{
    case SchoolAdmin = 'school_admin';
    case Principal = 'principal';
    case HomeroomTeacher = 'homeroom_teacher';
    case DutyOfficer = 'duty_officer';
    case TerminalOperator = 'terminal_operator';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SchoolAdmin => [
                Permission::SchoolProfileView,
                Permission::SchoolProfileUpdate,
                Permission::SchoolMembersView,
                Permission::SchoolMembersManage,
                Permission::SchoolAuditView,
                Permission::SchoolAcademicView,
                Permission::SchoolAcademicManage,
                Permission::SchoolStudentsView,
                Permission::SchoolStudentsManage,
                Permission::SchoolScheduleView,
                Permission::SchoolScheduleManage,
            ],
            self::Principal => [
                Permission::SchoolProfileView,
                Permission::SchoolMembersView,
                Permission::SchoolAuditView,
                Permission::SchoolAcademicView,
                Permission::SchoolStudentsView,
                Permission::SchoolScheduleView,
            ],
            // Data akademik wali kelas dibatasi ke kelas yang ia ampu oleh ClassScope.
            self::HomeroomTeacher => [
                Permission::SchoolProfileView,
                Permission::SchoolAcademicView,
                Permission::SchoolStudentsView,
                Permission::SchoolScheduleView,
            ],
            self::DutyOfficer,
            self::TerminalOperator => [
                Permission::SchoolProfileView,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
