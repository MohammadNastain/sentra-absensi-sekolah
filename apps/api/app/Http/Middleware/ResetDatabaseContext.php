<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global: konteks RLS di-reset di awal (defensif) dan WAJIB di-reset saat request selesai,
 * agar konteks satu request tidak pernah terbawa ke request berikutnya pada koneksi yang sama (T11).
 */
final class ResetDatabaseContext
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->database->reset();
        $this->tenant->clear();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->database->reset();
        $this->tenant->clear();
    }
}
