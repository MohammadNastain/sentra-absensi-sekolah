<?php

namespace App\Http\Middleware;

use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Header kustom memaksa preflight CORS, sehingga halaman origin lain tidak bisa memicu endpoint terminal. */
final class RequireTerminalHeader
{
    public const ALIAS = 'terminal.header';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header(AuthenticateDevice::HEADER) !== '1') {
            throw ApiProblem::terminalRequestRejected();
        }

        return $next($request);
    }
}
