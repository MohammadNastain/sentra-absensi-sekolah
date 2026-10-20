<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Tenancy\SupportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupportSessionRequest;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Akses dukungan Super Admin ke satu sekolah (break-glass, D-21). Membuka sesi butuh alasan; sesinya
 * berbatas waktu dan setiap request di dalamnya masuk audit log sekolah.
 */
final class PlatformSupportController extends Controller
{
    public function __construct(private readonly SupportAccess $support) {}

    public function show(Request $request, School $school): JsonResponse
    {
        return response()->json(['data' => $this->present($this->support->active($this->actor($request), $school))]);
    }

    public function store(SupportSessionRequest $request, School $school): JsonResponse
    {
        $grant = $this->support->start($this->actor($request), $school, $request->reason());

        return response()->json(['data' => $this->present($grant)], 201);
    }

    public function destroy(Request $request, School $school): Response
    {
        $this->support->end($this->actor($request), $school);

        return response()->noContent();
    }

    /**
     * @param  array{id: string, expires_at: Carbon}|null  $grant
     * @return array{active: bool, expires_at: string|null}
     */
    private function present(?array $grant): array
    {
        return ['active' => $grant !== null, 'expires_at' => $grant === null ? null : $grant['expires_at']->toIso8601String()];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
