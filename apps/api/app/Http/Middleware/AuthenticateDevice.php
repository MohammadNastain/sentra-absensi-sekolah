<?php

namespace App\Http\Middleware;

use App\Domain\Devices\DeviceSecrets;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\Device;
use App\Models\School;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard `device` untuk /terminal/* (ARCHITECTURE §6.2). Tanpa sesi dan tanpa CSRF cookie: token perangkat 256-bit
 * ada di cookie HttpOnly SameSite=Strict, dan header kustom X-Sentra-Terminal mewajibkan preflight CORS sehingga
 * halaman dari origin lain tidak dapat memicu permintaan terminal. Konteks sekolah berasal dari perangkat itu.
 * Pencabutan langsung efektif karena status dibaca di setiap request.
 */
final class AuthenticateDevice
{
    public const COOKIE = 'sentra_device';

    public const HEADER = 'X-Sentra-Terminal';

    /** Pembaruan last_seen_at dibatasi agar tidak menulis di setiap scan. */
    private const TOUCH_INTERVAL_SECONDS = 30;

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly TenantContext $tenant,
        private readonly DeviceSecrets $secrets,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header(self::HEADER) !== '1') {
            throw ApiProblem::terminalRequestRejected();
        }

        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || $token === '') {
            throw ApiProblem::deviceUnpaired();
        }

        $found = DB::selectOne("SELECT id, school_id, status FROM find_device_by_token_hash(decode(?, 'hex'))", [$this->secrets->tokenHashHex($token)]);
        if ($found === null) {
            throw ApiProblem::deviceUnpaired();
        }
        if ($found->status !== Device::STATUS_ACTIVE) {
            throw ApiProblem::deviceRevoked();
        }

        $this->database->setSchool((string) $found->school_id);
        $school = School::query()->find((string) $found->school_id);
        $device = Device::query()->find((string) $found->id);
        if ($school === null || $device === null) {
            throw ApiProblem::deviceUnpaired();
        }
        if (! $school->isAccessible()) {
            throw ApiProblem::schoolUnavailable();
        }

        $this->tenant->enterDevice($school, $device);

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            DB::update('UPDATE devices SET last_seen_at = now() WHERE id = ?', [$device->id]);
        }

        return $next($request);
    }
}
