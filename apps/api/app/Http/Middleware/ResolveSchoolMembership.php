<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Identity\SessionPolicy;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Langkah 3–4 pipeline rute sekolah (TAHAP_1_FONDASI §4, ADR 0004/0007 D-34).
 *
 * - {school} tidak ada, bukan anggota, atau keanggotaan ended → 404 (tidak membedakan, anti enumerasi)
 * - sekolah suspended/terminated → 403 SCHOOL_UNAVAILABLE
 * - keanggotaan suspended → 403 MEMBERSHIP_SUSPENDED
 * Pengecualian (D-21): Super Admin yang lulus 2FA lolos ke sekolah mana pun sebagai Admin Sekolah virtual, dan
 * setiap request-nya tercatat di audit log sekolah. Tanpa 2FA ia tetap 404 (T13).
 */
final class ResolveSchoolMembership
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
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

        if ($school !== null && $membership === null && $user->isPlatformAdmin() && SessionPolicy::twoFactorPassed($request, $user)
            && $school->status !== School::STATUS_TERMINATED) {
            return $this->handlePlatformAdmin($request, $next, $user, $school);
        }

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

    /**
     * Super Admin (lulus 2FA) mengakses sekolah mana pun sebagai Admin Sekolah virtual (D-21): tidak ada baris
     * keanggotaan yang disimpan, dan setiap request tercatat di audit log sekolah.
     */
    private function handlePlatformAdmin(Request $request, Closure $next, User $user, School $school): Response
    {
        $virtual = new SchoolMembership(['school_id' => $school->id, 'user_id' => $user->id, 'status' => SchoolMembership::STATUS_ACTIVE]);
        $virtual->setRelation('school', $school);

        $this->database->setSchool($school->id);
        $this->tenant->enterPlatformAdmin($school, $virtual, [Role::SchoolAdmin]);
        $this->recordRequest($request, $user, $school);

        $request->route()?->setParameter('school', $school);

        return $next($request);
    }

    /** Setiap request dicatat; GET yang sama dalam 60 detik dicatat sekali agar polling tidak membanjiri audit. */
    private function recordRequest(Request $request, User $user, School $school): void
    {
        $path = '/'.ltrim($request->path(), '/');

        if ($request->isMethodSafe() && ! Cache::add("platform-audit:{$user->id}:{$path}", 1, 60)) {
            return;
        }

        $this->audit->record(
            'platform.access.request', $school->id, 'platform_access', $school->id,
            null, ['method' => $request->method(), 'path' => $path], null, $user,
        );
    }
}
