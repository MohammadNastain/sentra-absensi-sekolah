<?php

namespace App\Http\Middleware;

use App\Domain\Entitlement\Feature;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entitlement fitur per sekolah (ADR 0009 §5). Dipasang per rute (`school.feature:<kode>[,<kode>]`) sebelum
 * `school.can`, sehingga fitur yang tidak dibeli ditolak 403 FEATURE_NOT_ENTITLED lebih dulu daripada izin peran.
 * Beberapa kode berarti "salah satu"; pemeriksaan yang lebih spesifik dilakukan pengendali.
 */
final class RequireSchoolFeature
{
    public const ALIAS = 'school.feature';

    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $this->tenant->requireAnyFeature(...array_map(Feature::from(...), $features));

        return $next($request);
    }

    public static function using(Feature ...$features): string
    {
        return self::ALIAS.':'.implode(',', array_map(fn (Feature $f) => $f->value, $features));
    }
}
