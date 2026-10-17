<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Ingat saya" dimatikan: cookie remember akan melewati batas sesi absolut 12 jam
 * (TAHAP_1_FONDASI §3). Input `remember` selalu dianggap false.
 */
final class RejectRememberMe
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge(['remember' => false]);

        return $next($request);
    }
}
