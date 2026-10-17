<?php

namespace App\Domain\Identity;

use Illuminate\Validation\Rules\Password;

/**
 * Aturan password (TAHAP_1_FONDASI §3): minimal 10 karakter dan tidak termasuk daftar password bocor.
 * Cek bocor memakai k-anonymity (hanya 5 karakter awal hash SHA-1 yang dikirim) dan dapat
 * dimatikan per environment lewat PASSWORD_CHECK_UNCOMPROMISED (ADR 0007 D-39).
 */
final class PasswordRules
{
    public static function default(): Password
    {
        $rule = Password::min(10)->max(255);

        return config('auth.password_check_uncompromised') ? $rule->uncompromised() : $rule;
    }

    /**
     * @return list<mixed>
     */
    public static function forNewPassword(): array
    {
        return ['required', 'string', self::default(), 'confirmed'];
    }
}
