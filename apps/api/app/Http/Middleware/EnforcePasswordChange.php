<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selama `must_change_password` menyala (akun siswa dengan sandi awal, ADR 0010 §1), API hanya melayani profil,
 * penggantian sandi, dan keluar. Dipasang di grup web setelah EnsureUserActive.
 */
final class EnforcePasswordChange
{
    /** Nama rute yang tetap boleh diakses. */
    private const ALLOWED = ['auth.me', 'user-password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            throw ApiProblem::passwordChangeRequired();
        }

        return $next($request);
    }
}
