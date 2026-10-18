<?php

namespace App\Http\Middleware;

use App\Domain\Attendance\AssistSessions;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi bantuan petugas di terminal. Dipasang setelah AuthenticateDevice: token sesi (header X-Assist-Session,
 * hanya di memori terminal) harus milik perangkat ini, belum idle/kedaluwarsa, dan petugasnya masih berwenang.
 * Setiap request yang lolos memperpanjang batas idle.
 */
final class RequireAssistSession
{
    public const HEADER = 'X-Assist-Session';

    public function __construct(private readonly AssistSessions $sessions, private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header(self::HEADER);
        if (! is_string($token) || $token === '') {
            throw ApiProblem::assistSessionInvalid();
        }

        $resolved = $this->sessions->resolve($token, $this->tenant->device());
        $request->attributes->set('assist_session_id', $resolved['id']);
        $request->attributes->set('assist_user', $resolved['user']);

        return $next($request);
    }
}
