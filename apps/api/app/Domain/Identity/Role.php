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
            ],
            self::Principal => [
                Permission::SchoolProfileView,
                Permission::SchoolMembersView,
                Permission::SchoolAuditView,
                Permission::SchoolAcademicView,
            ],
            // Wali kelas baru mendapat akses data kelasnya sendiri setelah scope kelas ada (Tahap 2E).
            self::HomeroomTeacher,
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
