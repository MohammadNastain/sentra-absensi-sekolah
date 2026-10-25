<?php

namespace App\Support\Http;

use App\Domain\Entitlement\Feature;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Error API dengan `code` stabil yang dirender sebagai RFC 9457 problem+json.
 */
class ApiProblem extends HttpException
{
    /**
     * @param  array<string, mixed>  $extensions  field tambahan pada body problem+json (mis. `feature`)
     */
    public function __construct(
        int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
    ) {
        parent::__construct($status, $detail ?? $title);
    }

    /** Sekolah belum berhak atas fitur ini (paket/override, ADR 0009). Berbeda dari izin peran. */
    public static function featureNotEntitled(Feature $feature): self
    {
        return new self(
            403,
            'FEATURE_NOT_ENTITLED',
            'Fitur ini tidak termasuk paket sekolah Anda.',
            "Fitur \"{$feature->label()}\" belum aktif untuk sekolah ini. Hubungi admin Sentra untuk mengaktifkannya.",
            ['feature' => $feature->value],
        );
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

    public static function deviceLimitReached(int $limit): self
    {
        return new self(409, 'DEVICE_LIMIT_REACHED', 'Batas jumlah perangkat tercapai.', "Sekolah ini boleh memiliki maksimum {$limit} perangkat (belum dicabut). Cabut perangkat lama atau hubungi administrator platform.");
    }

    public static function pairingCodeInvalid(): self
    {
        return new self(422, 'PAIRING_CODE_INVALID', 'Kode pairing tidak valid atau sudah kedaluwarsa.', 'Minta admin sekolah membuat kode baru.');
    }

    public static function deviceUnpaired(): self
    {
        return new self(401, 'DEVICE_UNPAIRED', 'Terminal belum dipasangkan.', 'Masukkan kode pairing dari admin sekolah.');
    }

    public static function deviceRevoked(): self
    {
        return new self(403, 'DEVICE_REVOKED', 'Perangkat ini telah dicabut.', 'Hubungi admin sekolah untuk memasangkan perangkat baru.');
    }

    public static function terminalRequestRejected(): self
    {
        return new self(400, 'TERMINAL_REQUEST_REJECTED', 'Permintaan terminal tidak valid.');
    }

    public static function resetThrottled(): self
    {
        return new self(429, 'RESET_THROTTLED', 'Tautan reset baru saja dikirim.', 'Tunggu sebentar sebelum meminta tautan reset lagi untuk anggota ini.');
    }

    public static function invalidStateTransition(string $detail): self
    {
        return new self(409, 'INVALID_STATE_TRANSITION', 'Perubahan status tidak diizinkan.', $detail);
    }

    public static function credentialInUse(string $detail): self
    {
        return new self(409, 'CREDENTIAL_IN_USE', 'Kartu sudah terdaftar.', $detail);
    }

    public static function assistLoginFailed(): self
    {
        return new self(401, 'ASSIST_LOGIN_FAILED', 'Login petugas gagal.', 'Email, password, atau kode verifikasi salah, atau akun tidak berwenang membantu absensi.');
    }

    public static function featureUnreleased(Feature $feature): self
    {
        return new self(422, 'FEATURE_UNRELEASED', 'Fitur ini belum tersedia di aplikasi.', "Fitur \"{$feature->label()}\" belum dirilis sehingga tidak dapat diaktifkan.", ['feature' => $feature->value]);
    }

    /**
     * @param  list<string>  $missing  prasyarat yang belum efektif
     */
    public static function featureDependencyMissing(Feature $feature, array $missing): self
    {
        return new self(422, 'FEATURE_DEPENDENCY_MISSING', 'Prasyarat fitur belum terpenuhi.', "Fitur \"{$feature->label()}\" membutuhkan fitur lain yang belum aktif untuk sekolah ini.", ['feature' => $feature->value, 'missing' => $missing]);
    }

    /**
     * @param  array<string, mixed>  $impact  pratinjau dampak (lihat ManageEntitlements)
     */
    public static function impactConfirmationRequired(array $impact): self
    {
        return new self(409, 'IMPACT_CONFIRMATION_REQUIRED', 'Perubahan ini mematikan fitur lain.', 'Tinjau daftar dampak, lalu ulangi permintaan dengan confirm_impact=true.', ['impact' => $impact]);
    }

    public static function assistSessionInvalid(): self
    {
        return new self(401, 'ASSIST_SESSION_INVALID', 'Sesi bantuan berakhir.', 'Masuk kembali sebagai petugas untuk melanjutkan.');
    }
}
