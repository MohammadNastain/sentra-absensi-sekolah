<?php

namespace Tests\Feature\Authorization;

use App\Domain\Identity\Permission;

/**
 * Deklarasi akses SETIAP rute (T08). Rute baru tanpa entri di sini menggagalkan test.
 *
 * Nilai:
 * - 'public'          : tanpa login (tetap dibatasi rate limit/CSRF)
 * - 'authenticated'   : login, tanpa konteks sekolah
 * - 'platform'        : Super Admin + 2FA
 * - 'device'          : token perangkat terminal (guard device)
 * - 'pairing'         : terminal belum dipasangkan (kode pairing)
 * - Permission::X     : anggota aktif sekolah {school} dengan izin X
 */
final class RouteMatrix
{
    /**
     * @return array<string, string|Permission>
     */
    public static function routes(): array
    {
        return [
            // Bawaan framework
            'GET up' => 'public',
            'GET sanctum/csrf-cookie' => 'public',

            // Autentikasi
            'POST api/v1/auth/login' => 'public',
            'POST api/v1/auth/two-factor-challenge' => 'public',
            'POST api/v1/auth/forgot-password' => 'public',
            'POST api/v1/auth/reset-password' => 'public',
            'POST api/v1/auth/logout' => 'authenticated',
            'GET api/v1/auth/me' => 'authenticated',
            'PUT api/v1/auth/password' => 'authenticated',
            'POST api/v1/auth/confirm-password' => 'authenticated',
            'GET api/v1/auth/confirmed-password-status' => 'authenticated',
            'POST api/v1/auth/two-factor' => 'authenticated',
            'DELETE api/v1/auth/two-factor' => 'authenticated',
            'POST api/v1/auth/two-factor/confirm' => 'authenticated',
            'GET api/v1/auth/two-factor/qr-code' => 'authenticated',
            'GET api/v1/auth/two-factor/secret-key' => 'authenticated',
            'GET api/v1/auth/two-factor/recovery-codes' => 'authenticated',
            'POST api/v1/auth/two-factor/recovery-codes' => 'authenticated',

            // Undangan lewat token (publik, dibatasi laju)
            'GET api/v1/invitations/{token}' => 'public',
            'POST api/v1/invitations/{token}/accept' => 'public',

            // Milik user
            'GET api/v1/me/schools' => 'authenticated',
            'GET api/v1/me/invitations' => 'authenticated',
            'POST api/v1/me/invitations/{invitation}/accept' => 'authenticated',
            'POST api/v1/me/invitations/{invitation}/decline' => 'authenticated',

            // Platform
            'GET api/v1/platform/overview' => 'platform',
            'GET api/v1/platform/schools' => 'platform',
            'POST api/v1/platform/schools' => 'platform',
            'GET api/v1/platform/schools/{school}' => 'platform',
            'PATCH api/v1/platform/schools/{school}' => 'platform',
            'POST api/v1/platform/schools/{school}/suspend' => 'platform',
            'POST api/v1/platform/schools/{school}/activate' => 'platform',
            'POST api/v1/platform/schools/{school}/invitations' => 'platform',

            // Sekolah
            'GET api/v1/schools/{school}' => Permission::SchoolProfileView,
            'PATCH api/v1/schools/{school}' => Permission::SchoolProfileUpdate,
            'GET api/v1/schools/{school}/members' => Permission::SchoolMembersView,
            'POST api/v1/schools/{school}/members/accounts' => Permission::SchoolMembersManage,
            'PUT api/v1/schools/{school}/members/{membership}/password' => Permission::SchoolMembersManage,
            'GET api/v1/schools/{school}/members/{membership}' => Permission::SchoolMembersView,
            'PUT api/v1/schools/{school}/members/{membership}/roles' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/suspend' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/reactivate' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/end' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/password-reset' => Permission::SchoolMembersManage,

            // Terminal (Tahap 3B)
            'POST api/v1/terminal/pair' => 'pairing',
            'GET api/v1/terminal/config' => 'device',
            'POST api/v1/terminal/heartbeat' => 'device',
            'POST api/v1/terminal/scans' => 'device',
            'POST api/v1/terminal/assist/session' => 'device',
            'DELETE api/v1/terminal/assist/session' => 'device',
            'GET api/v1/terminal/assist/students' => 'device',
            'POST api/v1/terminal/assist/attendance' => 'device',
            'GET api/v1/terminal/recent' => 'device',

            // Perangkat (Tahap 3B)
            'GET api/v1/schools/{school}/devices' => Permission::SchoolDevicesView,
            'POST api/v1/schools/{school}/devices' => Permission::SchoolDevicesManage,
            'GET api/v1/schools/{school}/devices/{device}' => Permission::SchoolDevicesView,
            'PATCH api/v1/schools/{school}/devices/{device}' => Permission::SchoolDevicesManage,
            'POST api/v1/schools/{school}/devices/{device}/pairing-code' => Permission::SchoolDevicesManage,
            'POST api/v1/schools/{school}/devices/{device}/revoke' => Permission::SchoolDevicesManage,

            // Kredensial (Tahap 3A)
            'GET api/v1/schools/{school}/students/{student}/credentials' => Permission::SchoolCredentialsView,
            'POST api/v1/schools/{school}/students/{student}/credentials/rfid' => Permission::SchoolCredentialsManage,
            'POST api/v1/schools/{school}/students/{student}/credentials/qr' => Permission::SchoolCredentialsManage,
            'POST api/v1/schools/{school}/classes/{schoolClass}/credentials/qr' => Permission::SchoolCredentialsManage,
            'POST api/v1/schools/{school}/credentials/{credential}/block' => Permission::SchoolCredentialsManage,
            'POST api/v1/schools/{school}/credentials/{credential}/unblock' => Permission::SchoolCredentialsManage,
            'POST api/v1/schools/{school}/credentials/{credential}/revoke' => Permission::SchoolCredentialsManage,
            'GET api/v1/schools/{school}/invitations' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/invitations' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/invitations/{invitation}/resend' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/invitations/{invitation}/revoke' => Permission::SchoolMembersManage,
            'GET api/v1/schools/{school}/audit-logs' => Permission::SchoolAuditView,
            'GET api/v1/schools/{school}/academic-years' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/academic-years' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/academic-years/{academicYear}' => Permission::SchoolAcademicView,
            'PATCH api/v1/schools/{school}/academic-years/{academicYear}' => Permission::SchoolAcademicManage,
            'POST api/v1/schools/{school}/academic-years/{academicYear}/activate' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/teachers' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/teachers' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/teachers/{teacher}' => Permission::SchoolAcademicView,
            'PATCH api/v1/schools/{school}/teachers/{teacher}' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/students' => Permission::SchoolStudentsView,
            'POST api/v1/schools/{school}/students' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/students/{student}' => Permission::SchoolStudentsView,
            'PATCH api/v1/schools/{school}/students/{student}' => Permission::SchoolStudentsManage,
            'POST api/v1/schools/{school}/students/{student}/enrollments' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/imports' => Permission::SchoolStudentsManage,
            'POST api/v1/schools/{school}/imports/students' => Permission::SchoolStudentsManage,
            'POST api/v1/schools/{school}/imports/teachers' => Permission::SchoolAcademicManage,
            'POST api/v1/schools/{school}/imports/teaching-schedule' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/subjects' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/subjects' => Permission::SchoolAcademicManage,
            'PATCH api/v1/schools/{school}/subjects/{subject}' => Permission::SchoolAcademicManage,
            'DELETE api/v1/schools/{school}/subjects/{subject}' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/teaching-slots' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/teaching-slots' => Permission::SchoolAcademicManage,
            'PATCH api/v1/schools/{school}/teaching-slots/{slot}' => Permission::SchoolAcademicManage,
            'DELETE api/v1/schools/{school}/teaching-slots/{slot}' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/imports/template' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/imports/{import}' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/imports/{import}/rows' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/imports/{import}/failed-rows' => Permission::SchoolStudentsManage,
            'POST api/v1/schools/{school}/imports/{import}/commit' => Permission::SchoolStudentsManage,
            'GET api/v1/schools/{school}/holidays' => Permission::SchoolScheduleView,
            'POST api/v1/schools/{school}/holidays' => Permission::SchoolScheduleManage,
            'GET api/v1/schools/{school}/holidays/{holiday}' => Permission::SchoolScheduleView,
            'PATCH api/v1/schools/{school}/holidays/{holiday}' => Permission::SchoolScheduleManage,
            'DELETE api/v1/schools/{school}/holidays/{holiday}' => Permission::SchoolScheduleManage,
            'GET api/v1/schools/{school}/schedules' => Permission::SchoolScheduleView,
            'POST api/v1/schools/{school}/schedules' => Permission::SchoolScheduleManage,
            'GET api/v1/schools/{school}/schedules/{schedule}' => Permission::SchoolScheduleView,
            'PATCH api/v1/schools/{school}/schedules/{schedule}' => Permission::SchoolScheduleManage,
            'GET api/v1/schools/{school}/calendar' => Permission::SchoolScheduleView,
            'GET api/v1/schools/{school}/attendance/today' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/attendance/assist/students' => Permission::SchoolAttendanceAssist,
            'POST api/v1/schools/{school}/attendance/assisted' => Permission::SchoolAttendanceAssist,
            'GET api/v1/schools/{school}/attendance/absence-settings' => Permission::SchoolAttendanceView,
            'PUT api/v1/schools/{school}/attendance/absence-settings' => Permission::SchoolProfileUpdate,
            'GET api/v1/schools/{school}/attendance/absences' => Permission::SchoolAttendanceVerify,
            'POST api/v1/schools/{school}/attendance/absences/verify' => Permission::SchoolAttendanceVerify,
            'POST api/v1/schools/{school}/attendance/days/{date}/disruption' => Permission::SchoolAttendanceReview,
            'POST api/v1/schools/{school}/attendance/days/{date}/release' => Permission::SchoolAttendanceReview,
            'POST api/v1/schools/{school}/attendance/corrections' => Permission::SchoolAttendanceCorrect,
            'GET api/v1/schools/{school}/attendance/corrections/history' => Permission::SchoolAttendanceReview,
            'GET api/v1/schools/{school}/attendance/correction-requests' => Permission::SchoolAttendanceCorrectionRequest,
            'POST api/v1/schools/{school}/attendance/correction-requests' => Permission::SchoolAttendanceCorrectionRequest,
            'POST api/v1/schools/{school}/attendance/correction-requests/{correction}/decision' => Permission::SchoolAttendanceCorrect,
            'POST api/v1/schools/{school}/attendance/correction-requests/{correction}/cancel' => Permission::SchoolAttendanceCorrectionRequest,
            'GET api/v1/schools/{school}/reports/summary' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/trend' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/students' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/lateness' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/daily' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/monthly' => Permission::SchoolAttendanceView,
            'GET api/v1/schools/{school}/reports/events' => Permission::SchoolAttendanceReview,
            'GET api/v1/schools/{school}/exports' => Permission::SchoolReportsExport,
            'POST api/v1/schools/{school}/exports' => Permission::SchoolReportsExport,
            'GET api/v1/schools/{school}/exports/{export}' => Permission::SchoolReportsExport,
            'GET api/v1/schools/{school}/exports/{export}/download' => Permission::SchoolReportsExport,
            'GET api/v1/schools/{school}/leave-requests' => Permission::SchoolLeaveManage,
            'GET api/v1/schools/{school}/leave-requests/students' => Permission::SchoolLeaveManage,
            'POST api/v1/schools/{school}/leave-requests' => Permission::SchoolLeaveManage,
            'POST api/v1/schools/{school}/leave-requests/{leave}/attachment' => Permission::SchoolLeaveManage,
            'GET api/v1/schools/{school}/leave-requests/{leave}/attachment' => Permission::SchoolLeaveManage,
            'POST api/v1/schools/{school}/leave-requests/{leave}/decision' => Permission::SchoolLeaveApprove,
            'POST api/v1/schools/{school}/leave-requests/{leave}/cancel' => Permission::SchoolLeaveManage,
            'GET api/v1/schools/{school}/attendance/review-queue' => Permission::SchoolAttendanceReview,
            'POST api/v1/schools/{school}/attendance/review-queue/{event}/resolve' => Permission::SchoolAttendanceReview,
            'GET api/v1/schools/{school}/attendance/assist-report' => Permission::SchoolAttendanceReview,
            'GET api/v1/schools/{school}/classes' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/classes' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/classes/{schoolClass}' => Permission::SchoolAcademicView,
            'PATCH api/v1/schools/{school}/classes/{schoolClass}' => Permission::SchoolAcademicManage,
        ];
    }
}
