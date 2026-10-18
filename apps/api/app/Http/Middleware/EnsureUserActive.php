<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SessionPolicy;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak user nonaktif di SETIAP request (A03) dan menegakkan batas umur sesi absolut 12 jam.
 * Setelah lolos, konteks RLS app.user_id ditetapkan agar user dapat membaca keanggotaannya sendiri.
 */
final class EnsureUserActive
{
    public function __construct(private readonly DatabaseContext $database) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->isActive() || SessionPolicy::hasExpired($request)) {
            $this->terminateSession($request);

            throw new AuthenticationException;
        }

        SessionPolicy::ensureStarted($request);
        $this->database->setUser($user->id);

        return $next($request);
    }

    private function terminateSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
