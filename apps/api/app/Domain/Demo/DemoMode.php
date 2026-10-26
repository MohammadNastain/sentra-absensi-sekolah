<?php

namespace App\Domain\Demo;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mode demo: sekolah contoh yang dibuka lewat tombol "Coba demo" tanpa sandi. Semua pengecekan di sini
 * langsung bernilai false selama DEMO_ENABLED mati, sehingga tidak ada biaya atau pintu masuk tambahan.
 */
final class DemoMode
{
    /** @var array<string, bool> */
    private static array $schoolIds = [];

    public static function enabled(): bool
    {
        return (bool) config('sentra.demo.enabled', false);
    }

    public static function schoolCode(): string
    {
        return (string) config('sentra.demo.school_code');
    }

    public static function adminEmail(): string
    {
        return mb_strtolower((string) config('sentra.demo.admin_email'));
    }

    public static function isDemoSchoolId(string $schoolId): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return self::$schoolIds[$schoolId] ??= DB::table('schools')->where('id', $schoolId)->value('code') === self::schoolCode();
    }

    public static function isDemoSchoolCode(string $code): bool
    {
        return self::enabled() && $code !== '' && mb_strtolower($code) === self::schoolCode();
    }

    public static function isDemoUser(?User $user): bool
    {
        return self::enabled() && $user !== null && mb_strtolower($user->email) === self::adminEmail();
    }

    /** Dipakai tes: cache id sekolah tidak boleh bocor antar skenario. */
    public static function flush(): void
    {
        self::$schoolIds = [];
    }
}
