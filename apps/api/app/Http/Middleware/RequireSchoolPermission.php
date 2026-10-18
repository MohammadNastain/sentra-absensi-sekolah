<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Permission;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Izin per peran dalam konteks sekolah aktif. Dideklarasikan per rute (`school.can:<izin>`)
 * agar matriks otorisasi dapat membaca kebutuhan izin setiap rute (T08–T09).
 */
final class RequireSchoolPermission
{
    public const ALIAS = 'school.can';

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->tenant->can(Permission::from($permission))) {
            throw ApiProblem::permissionDenied();
        }

        return $next($request);
    }

    public static function using(Permission $permission): string
    {
        return self::ALIAS.':'.$permission->value;
    }
}
