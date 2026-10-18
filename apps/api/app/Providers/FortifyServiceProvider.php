<?php

namespace App\Providers;

use App\Domain\Identity\Actions\ResetUserPassword;
use App\Domain\Identity\Actions\UpdateUserPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Fortify headless (ADR 0007 D-38). Rute didaftarkan sendiri di routes/api.php ke controller Fortify
 * agar path & rate limit sesuai TAHAP_1_FONDASI §6.
 */
class FortifyServiceProvider extends ServiceProvider
{
    /** Hash tiruan agar waktu respons email tak terdaftar ≈ password salah (A02). */
    private static ?string $dummyHash = null;

    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        // User nonaktif dan kredensial salah menghasilkan respons yang identik (A02, A03).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', (string) $request->input(Fortify::username()))->first();
            $valid = Hash::check((string) $request->input('password'), $user->password ?? (self::$dummyHash ??= Hash::make(Str::random(40))));

            return $user !== null && $valid && $user->isActive() ? $user : null;
        });

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $email = fn (Request $request): string => Str::lower((string) $request->input('email'));

        // Bawaan 5/menit per akun+IP. E2E CI login puluhan kali sebagai akun demo yang sama, jadi bisa dinaikkan lewat env.
        $perAccount = max(1, (int) config('sentra.login_attempts_per_minute'));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($perAccount)->by('login:'.$email($request).'|'.$request->ip()),
            Limit::perMinute(max(20, $perAccount * 4))->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by('two-factor:'.($request->session()->get('login.id') ?? $request->session()->getId())));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinutes(15, 3)->by('password-reset:'.$email($request)),
            Limit::perMinute(10)->by('password-reset-ip:'.$request->ip()),
        ]);
    }
}
