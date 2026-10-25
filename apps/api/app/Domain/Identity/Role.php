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
    case Teacher = 'teacher';
    case Student = 'student';

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
                Permission::SchoolCredentialsView,
                Permission::SchoolCredentialsManage,
                Permission::SchoolDevicesView,
                Permission::SchoolDevicesManage,
                Permission::SchoolAttendanceView,
                Permission::SchoolAttendanceAssist,
                Permission::SchoolAttendanceReview,
                Permission::SchoolAttendanceVerify,
                Permission::SchoolAttendanceCorrect,
                Permission::SchoolAttendanceCorrectionRequest,
                Permission::SchoolLeaveManage,
                Permission::SchoolLeaveApprove,
                Permission::SchoolReportsExport,
                Permission::SchoolLmsView,
                Permission::SchoolLmsManage,
                Permission::SchoolLmsGrade,
            ],
            self::Principal => [
                Permission::SchoolProfileView,
                Permission::SchoolMembersView,
                Permission::SchoolAuditView,
                Permission::SchoolAcademicView,
                Permission::SchoolStudentsView,
                Permission::SchoolScheduleView,
                Permission::SchoolAttendanceView,
                Permission::SchoolReportsExport,
                Permission::SchoolLmsView,
            ],
            // Data akademik wali kelas dibatasi ke kelas yang ia ampu oleh ClassScope.
            self::HomeroomTeacher => [
                Permission::SchoolProfileView,
                Permission::SchoolAcademicView,
                Permission::SchoolStudentsView,
                Permission::SchoolScheduleView,
                Permission::SchoolAttendanceView,
                Permission::SchoolAttendanceVerify,
                Permission::SchoolAttendanceCorrectionRequest,
                Permission::SchoolLeaveManage,
                Permission::SchoolReportsExport,
            ],
            // Petugas piket melihat ringkasan harian (tanpa data akademik); terminal memakai token perangkat, bukan akun.
            self::DutyOfficer => [
                Permission::SchoolProfileView,
                Permission::SchoolAttendanceView,
                Permission::SchoolAttendanceAssist,
                Permission::SchoolAttendanceReview,
                Permission::SchoolAttendanceVerify,
                Permission::SchoolLeaveManage,
                Permission::SchoolLeaveApprove,
            ],
            self::TerminalOperator => [
                Permission::SchoolProfileView,
            ],
            // Guru mata pelajaran: hanya LMS, dan hanya untuk kelas-mapel dari jadwal mengajarnya (LmsScope).
            self::Teacher => [
                Permission::SchoolProfileView,
                Permission::SchoolLmsView,
                Permission::SchoolLmsManage,
                Permission::SchoolLmsGrade,
            ],
            // Siswa: melihat konten kelasnya dan mengumpulkan tugas (LmsScope).
            self::Student => [
                Permission::SchoolProfileView,
                Permission::SchoolLmsView,
                Permission::SchoolLmsSubmit,
            ],
        };
    }

    /**
     * Peran yang boleh diberikan lewat undangan, akun langsung, dan ubah peran. Siswa tidak termasuk: akunnya hanya
     * dibuat dari data siswa (ADR 0010 §1).
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        $codes = [];
        foreach (self::cases() as $role) {
            if ($role !== self::Student) {
                $codes[] = $role->value;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
