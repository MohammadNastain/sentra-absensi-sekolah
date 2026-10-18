<?php

namespace App\Domain\Devices;

use App\Domain\Credentials\CredentialHasher;

/**
 * Rahasia perangkat terminal: kode pairing (8 karakter tanpa karakter yang mudah tertukar, berlaku 10 menit)
 * dan token perangkat 256-bit. Keduanya hanya disimpan sebagai hash; kode di-HMAC agar ruang 40 bit tidak dapat
 * ditebak offline dari salinan database.
 */
final class DeviceSecrets
{
    public const PAIRING_TTL_MINUTES = 10;

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly CredentialHasher $hasher) {}

    public function newPairingCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Pemindai/keyboard dapat menambah spasi atau tanda hubung; huruf kecil dianggap sama. */
    public function normalizeCode(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $input));
    }

    public function pairingCodeHashHex(string $code): string
    {
        return $this->hasher->hashHex('pair:'.$this->normalizeCode($code), 1);
    }

    public function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function tokenHashHex(string $token): string
    {
        return hash('sha256', $token);
    }
}
