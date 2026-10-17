<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Langkah 3–4 pipeline rute sekolah (TAHAP_1_FONDASI §4, ADR 0004/0007 D-34).
 *
 * - {school} tidak ada, bukan anggota, atau keanggotaan ended → 404 (tidak membedakan, anti enumerasi)
 * - sekolah suspended/terminated → 403 SCHOOL_UNAVAILABLE
 * - keanggotaan suspended → 403 MEMBERSHIP_SUSPENDED
 * Super Admin tidak mendapat pengecualian (T13).
 */
final class ResolveSchoolMembership
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $route = $request->route();
        $parameter = $route?->parameter('school');
        $code = $parameter instanceof School ? $parameter->code : $parameter;

        $school = is_string($code) ? School::query()->where('code', $code)->first() : null;

        // Konteks app.user_id sudah diset EnsureUserActive: policy RLS hanya menampilkan keanggotaan user ini.
        $membership = $school === null ? null : SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->first();

        if ($school === null || $membership === null || $membership->status === SchoolMembership::STATUS_ENDED) {
            throw new NotFoundHttpException;
        }

        if (! $school->isAccessible()) {
            throw ApiProblem::schoolUnavailable();
        }

        if ($membership->status !== SchoolMembership::STATUS_ACTIVE) {
            throw ApiProblem::membershipSuspended();
        }

        $this->database->setSchool($school->id);
        $membership->load('roles');
        $membership->setRelation('school', $school);
        $this->tenant->enter($school, $membership, $membership->roleEnums());

        $route?->setParameter('school', $school);

        return $next($request);
    }
}
