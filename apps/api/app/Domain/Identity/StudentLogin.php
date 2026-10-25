<?php

namespace App\Domain\Identity;

/**
 * Identitas login siswa (ADR 0010 §1). Model identitas tetap berbasis email, jadi akun siswa memakai alamat sintetis
 * `<nis>.<kode-sekolah>@siswa.sentra.invalid` (TLD `.invalid` tidak pernah dapat dikirimi surat). Siswa mengetik
 * `<kode-sekolah>/<NIS>`; kelas ini memetakannya ke alamat sintetis dan sebaliknya mengenali alamat itu.
 */
final class StudentLogin
{
    public const DOMAIN = 'siswa.sentra.invalid';

    /** NIS yang dapat menjadi bagian alamat dan identitas login. */
    public const NIS_PATTERN = '/^[A-Za-z0-9._-]{1,30}$/';

    /**
     * Isian kolom login → alamat email. Bentuk `kode/NIS` dipetakan; yang lain dikembalikan apa adanya (huruf kecil).
     */
    public static function toEmail(string $identifier): string
    {
        $identifier = trim($identifier);

        if (! str_contains($identifier, '@') && str_contains($identifier, '/')) {
            [$code, $nis] = explode('/', $identifier, 2);
            $code = mb_strtolower(trim($code));
            $nis = trim($nis);

            if ($code !== '' && preg_match(self::NIS_PATTERN, $nis) === 1) {
                return self::syntheticEmail($code, $nis);
            }
        }

        return mb_strtolower($identifier);
    }

    public static function syntheticEmail(string $schoolCode, string $nis): string
    {
        return mb_strtolower($nis.'.'.$schoolCode.'@'.self::DOMAIN);
    }

    public static function isSynthetic(string $email): bool
    {
        return str_ends_with(mb_strtolower($email), '@'.self::DOMAIN);
    }

    public static function isValidNis(string $nis): bool
    {
        return preg_match(self::NIS_PATTERN, $nis) === 1;
    }

    /** Kata sandi awal: tanpa karakter yang mudah tertukar (0/O, 1/l/I). */
    public static function generatePassword(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
