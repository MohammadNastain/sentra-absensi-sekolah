<?php

namespace App\Domain\Membership;

/**
 * Token undangan 256-bit. Basis data hanya menyimpan SHA-256 (bytea); token mentah hanya ada di
 * tautan email (ADR 0004, I01).
 */
final class InvitationToken
{
    public const LIFETIME_DAYS = 7;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /**
     * Literal bytea heksadesimal untuk binding parameter (hash biner bukan UTF-8 valid).
     */
    public static function hash(string $token): string
    {
        return '\\x'.hash('sha256', $token);
    }
}
