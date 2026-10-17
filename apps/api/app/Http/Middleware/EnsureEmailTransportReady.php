<?php

namespace App\Http\Middleware;

use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Di luar local/testing, mailer `log`/`array` tidak benar-benar mengirim, jadi endpoint yang
 * bergantung pada email menolak dengan 503 EMAIL_NOT_CONFIGURED alih-alih berpura-pura terkirim (ADR 0008).
 */
final class EnsureEmailTransportReady
{
    public const ALIAS = 'email.ready';

    public function handle(Request $request, Closure $next): Response
    {
        $fake = in_array(config('mail.default'), ['log', 'array', null], true);

        if ($fake && ! in_array(config('app.env'), ['local', 'testing'], true)) {
            throw ApiProblem::emailNotConfigured();
        }

        return $next($request);
    }
}
