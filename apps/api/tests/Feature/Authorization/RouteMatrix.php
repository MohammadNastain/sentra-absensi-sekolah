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
            'GET api/v1/schools/{school}/members/{membership}' => Permission::SchoolMembersView,
            'PUT api/v1/schools/{school}/members/{membership}/roles' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/suspend' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/reactivate' => Permission::SchoolMembersManage,
            'POST api/v1/schools/{school}/members/{membership}/end' => Permission::SchoolMembersManage,
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
            'GET api/v1/schools/{school}/classes' => Permission::SchoolAcademicView,
            'POST api/v1/schools/{school}/classes' => Permission::SchoolAcademicManage,
            'GET api/v1/schools/{school}/classes/{schoolClass}' => Permission::SchoolAcademicView,
            'PATCH api/v1/schools/{school}/classes/{schoolClass}' => Permission::SchoolAcademicManage,
        ];
    }
}
