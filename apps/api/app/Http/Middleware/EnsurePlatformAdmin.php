<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SessionPolicy;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rute platform: hanya Super Admin (403) yang sudah mengonfirmasi 2FA DAN lulus tantangan 2FA
 * di sesi ini (ADR 0007 D-25, A08). Setelah lolos, konteks RLS platform diaktifkan.
 */
final class EnsurePlatformAdmin
{
    public function __construct(private readonly DatabaseContext $database) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->isPlatformAdmin()) {
            throw ApiProblem::platformAdminRequired();
        }

        if (! SessionPolicy::twoFactorPassed($request, $user)) {
            throw ApiProblem::twoFactorRequired();
        }

        $this->database->setPlatform(true);

        return $next($request);
    }
}
