<?php

namespace App\Domain\Identity;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Kebijakan sesi dashboard (TAHAP_1_FONDASI §3): idle 60 menit (SESSION_LIFETIME),
 * maksimum 12 jam sejak login, dan penanda bahwa tantangan 2FA lulus di sesi ini.
 */
final class SessionPolicy
{
    public const MAX_AGE_SECONDS = 12 * 60 * 60;

    private const LOGIN_AT = 'auth.login_at';

    private const TWO_FACTOR_USER = 'auth.two_factor_passed_user_id';

    /**
     * Penanda 2FA sengaja TIDAK dihapus di sini: Fortify memicu ValidTwoFactorAuthenticationCodeProvided
     * sebelum event Login. Penanda terikat ke user ID dan hilang saat logout (sesi di-invalidate).
     */
    public static function markLogin(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::LOGIN_AT, now()->getTimestamp());
        }
    }

    /**
     * Sesi tanpa penanda waktu login (mis. dibuat sebelum kebijakan ini ada) mulai dihitung sekarang.
     */
    public static function ensureStarted(Request $request): void
    {
        if ($request->hasSession() && ! $request->session()->has(self::LOGIN_AT)) {
            $request->session()->put(self::LOGIN_AT, now()->getTimestamp());
        }
    }

    public static function hasExpired(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $loginAt = $request->session()->get(self::LOGIN_AT);

        return is_int($loginAt) && now()->getTimestamp() - $loginAt > self::MAX_AGE_SECONDS;
    }

    public static function markTwoFactorPassed(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::TWO_FACTOR_USER, $user->id);
        }
    }

    public static function twoFactorPassed(Request $request, User $user): bool
    {
        return $request->hasSession()
            && $user->hasConfirmedTwoFactor()
            && $request->session()->get(self::TWO_FACTOR_USER) === $user->id;
    }
}
