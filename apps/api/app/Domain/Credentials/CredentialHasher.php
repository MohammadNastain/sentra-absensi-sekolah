<?php

namespace App\Domain\Credentials;

use RuntimeException;

/**
 * HMAC-SHA256 berversi kunci untuk nilai kredensial (QR/RFID). Nilai mentah tidak pernah disimpan atau dicatat;
 * database hanya memegang hash 32 byte (dikirim sebagai hex lalu didekode di SQL).
 */
final class CredentialHasher
{
    public function currentVersion(): int
    {
        return (int) config('sentra.credential_hmac_current_version', 1);
    }

    /** Hash heksadesimal (64 karakter) dari nilai ternormalisasi. */
    public function hashHex(string $normalizedValue, ?int $version = null): string
    {
        return hash_hmac('sha256', $normalizedValue, $this->key($version ?? $this->currentVersion()));
    }

    private function key(int $version): string
    {
        $configured = config('sentra.credential_hmac_key');
        if (is_string($configured) && $configured !== '') {
            $raw = base64_decode($configured, true);
            if ($raw === false || strlen($raw) < 32) {
                throw new RuntimeException('CREDENTIAL_HMAC_KEY harus base64 dari minimal 32 byte.');
            }

            return hash_hmac('sha256', "sentra.credential.v{$version}", $raw, true);
        }

        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if ($raw === false || $raw === '') {
            throw new RuntimeException('APP_KEY belum diatur; kunci kredensial tidak dapat diturunkan.');
        }

        return hash_hkdf('sha256', $raw, 32, "sentra.credential.v{$version}");
    }
}
