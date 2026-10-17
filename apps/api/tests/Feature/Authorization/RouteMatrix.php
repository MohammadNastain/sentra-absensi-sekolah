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

            // Milik user
            'GET api/v1/me/schools' => 'authenticated',

            // Platform
            'GET api/v1/platform/schools' => 'platform',

            // Sekolah
            'GET api/v1/schools/{school}' => Permission::SchoolProfileView,
        ];
    }
}
