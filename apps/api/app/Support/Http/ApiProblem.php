<?php

namespace App\Support\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Error API dengan `code` stabil yang dirender sebagai RFC 9457 problem+json.
 */
class ApiProblem extends HttpException
{
    public function __construct(
        int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($status, $detail ?? $title);
    }

    public static function membershipSuspended(): self
    {
        return new self(403, 'MEMBERSHIP_SUSPENDED', 'Keanggotaan Anda di sekolah ini sedang ditangguhkan.');
    }

    public static function schoolUnavailable(): self
    {
        return new self(403, 'SCHOOL_UNAVAILABLE', 'Sekolah ini sedang tidak dapat diakses.');
    }

    public static function platformAdminRequired(): self
    {
        return new self(403, 'PLATFORM_ADMIN_REQUIRED', 'Hanya Super Admin yang dapat mengakses fitur ini.');
    }

    public static function twoFactorRequired(): self
    {
        return new self(
            403,
            'TWO_FACTOR_REQUIRED',
            'Verifikasi dua langkah wajib untuk fitur ini.',
            'Aktifkan dan konfirmasi 2FA, lalu masuk ulang dengan kode autentikator.',
        );
    }

    public static function permissionDenied(): self
    {
        return new self(403, 'PERMISSION_DENIED', 'Anda tidak memiliki izin untuk tindakan ini.');
    }
}
