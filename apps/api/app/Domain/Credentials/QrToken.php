<?php

namespace App\Domain\Credentials;

/**
 * Token QR: `SA1.` + 128 bit acak dalam base32 (26 karakter). Tidak memuat data pribadi; hanya hash-nya yang
 * disimpan, sehingga token hanya terlihat sekali saat terbit.
 */
final class QrToken
{
    public const PREFIX = 'SA1.';

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= '00'; // 128 bit → 26 kelompok 5 bit

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec($chunk)];
        }

        return self::PREFIX.$out;
    }

    public static function looksLikeToken(string $raw): bool
    {
        return preg_match('/^SA1\.[A-Z2-7]{26}$/', self::normalize($raw)) === 1;
    }

    /** Pemindai dapat menambah spasi/baris baru dan kapitalisasi berbeda; token sendiri huruf besar. */
    public static function normalize(string $raw): string
    {
        return strtoupper(trim($raw));
    }
}
