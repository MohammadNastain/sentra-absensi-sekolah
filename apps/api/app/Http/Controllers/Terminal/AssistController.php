<?php

namespace App\Http\Controllers\Terminal;

use App\Domain\Attendance\AssistedAttendance;
use App\Domain\Attendance\AssistSessions;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistedAttendanceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mode bantuan di terminal (kartu tertinggal, pulang awal). Terminal tetap tidak pernah memegang kredensial
 * admin: sesi bantuan berumur pendek, terikat ke perangkat, dan token-nya hanya ada di memori terminal.
 */
final class AssistController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AssistSessions $sessions,
        private readonly AssistedAttendance $assisted,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $session = $this->sessions->start($this->tenant->school(), $this->tenant->device(), $data['email'], $data['password'], $data['code'] ?? null);

        return response()->json(['data' => [
            'token' => $session['token'],
            'officer_name' => $session['user']->name,
            'expires_at' => $session['expires_at']->toIso8601String(),
            'idle_seconds' => AssistSessions::IDLE_SECONDS,
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function end(Request $request): JsonResponse
    {
        $this->sessions->end((string) $request->attributes->get('assist_session_id'));

        return response()->json(['data' => ['ended' => true]]);
    }

    public function students(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->assisted->search($this->tenant->school(), $validated['q'])])
            ->header('Cache-Control', 'no-store, private');
    }

    public function attendance(AssistedAttendanceRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('assist_user');
        /** @var array{student_id: string, reason_code: string, note?: string|null, event_type?: string|null, idempotency_key: string} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->assisted->record($this->tenant->school(), $this->tenant->device(), $actor, $data)])
            ->header('Cache-Control', 'no-store, private');
    }
}
