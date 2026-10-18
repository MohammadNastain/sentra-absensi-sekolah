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
            'Aktifkan dan konfirmasi 2FA di pengaturan keamanan akun, atau masuk dengan kode autentikator.',
        );
    }

    public static function permissionDenied(): self
    {
        return new self(403, 'PERMISSION_DENIED', 'Anda tidak memiliki izin untuk tindakan ini.');
    }

    public static function emailNotConfigured(): self
    {
        return new self(
            503,
            'EMAIL_NOT_CONFIGURED',
            'Layanan email belum dikonfigurasi.',
            'Undangan dan reset password membutuhkan layanan email. Hubungi administrator platform.',
        );
    }

    public static function invitationUnavailable(): self
    {
        return new self(410, 'INVITATION_UNAVAILABLE', 'Undangan tidak berlaku. Minta admin sekolah mengirim undangan baru.');
    }

    public static function loginRequired(): self
    {
        return new self(401, 'LOGIN_REQUIRED', 'Email ini sudah terdaftar. Masuk dengan akun tersebut untuk menerima undangan.');
    }

    public static function invitationEmailMismatch(): self
    {
        return new self(403, 'INVITATION_EMAIL_MISMATCH', 'Undangan ini ditujukan untuk akun dengan email yang berbeda.');
    }

    public static function invitationAlreadyPending(): self
    {
        return new self(409, 'INVITATION_ALREADY_PENDING', 'Masih ada undangan yang belum diterima untuk email ini.', 'Kirim ulang atau cabut undangan yang ada.');
    }

    public static function alreadyMember(): self
    {
        return new self(409, 'ALREADY_MEMBER', 'Akun ini sudah menjadi anggota sekolah.');
    }

    public static function lastSchoolAdmin(): self
    {
        return new self(
            409,
            'LAST_SCHOOL_ADMIN',
            'Admin sekolah aktif terakhir tidak dapat dicabut perannya, ditangguhkan, atau diakhiri.',
            'Tambahkan admin sekolah lain terlebih dahulu.',
        );
    }

    public static function cannotChangeOwnRoles(): self
    {
        return new self(403, 'CANNOT_CHANGE_OWN_ROLES', 'Anda tidak dapat mengubah peran Anda sendiri.');
    }

    public static function academicYearOverlap(): self
    {
        return new self(409, 'ACADEMIC_YEAR_OVERLAP', 'Tanggal tahun ajaran tumpang tindih dengan tahun ajaran lain.', 'Ubah tanggal mulai atau selesai agar tidak beririsan.');
    }

    public static function duplicateValue(string $detail): self
    {
        return new self(409, 'DUPLICATE_VALUE', 'Data yang sama sudah ada.', $detail);
    }

    public static function scheduleConflict(string $detail): self
    {
        return new self(409, 'SCHEDULE_CONFLICT', 'Jadwal bentrok.', $detail);
    }

    public static function enrollmentConflict(string $detail): self
    {
        return new self(409, 'ENROLLMENT_CONFLICT', 'Penempatan kelas bentrok.', $detail);
    }

    public static function resetThrottled(): self
    {
        return new self(429, 'RESET_THROTTLED', 'Tautan reset baru saja dikirim.', 'Tunggu sebentar sebelum meminta tautan reset lagi untuk anggota ini.');
    }

    public static function invalidStateTransition(string $detail): self
    {
        return new self(409, 'INVALID_STATE_TRANSITION', 'Perubahan status tidak diizinkan.', $detail);
    }
}
