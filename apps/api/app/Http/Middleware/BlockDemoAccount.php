<?php

namespace App\Http\Middleware;

use App\Domain\Demo\DemoMode;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun demo dipakai bersama banyak pengunjung: sandi, 2FA, dan kredensial lain tidak boleh diubah
 * (kalau tidak, satu pengunjung bisa mengunci pengunjung berikutnya).
 */
final class BlockDemoAccount
{
    public const ALIAS = 'demo.blocked';

    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::isDemoUser($request->user())) {
            throw ApiProblem::demoRestricted();
        }

        return $next($request);
    }
}
