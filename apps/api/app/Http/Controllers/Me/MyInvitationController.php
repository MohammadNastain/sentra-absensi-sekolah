<?php

namespace App\Http\Controllers\Me;

use App\Domain\Membership\Actions\ResolveInvitation;
use App\Http\Controllers\Controller;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Undangan yang ditujukan ke email user yang login. Dibaca lewat policy RLS `own_invitations_read`
 * (konteks app.user_id) tanpa konteks sekolah.
 */
final class MyInvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = MembershipInvitation::query()
            ->safe()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();

        $schools = School::query()->whereIn('id', $items->pluck('school_id'))->get()->keyBy('id');

        return response()->json(['data' => $items->map(fn (MembershipInvitation $i) => [
            'id' => $i->id,
            'school' => ['name' => $schools[$i->school_id]->name ?? null],
            'roles' => $i->role_codes,
            'expires_at' => $i->expires_at->toIso8601String(),
        ])->all()]);
    }

    public function accept(Request $request, string $invitation, ResolveInvitation $resolve): JsonResponse
    {
        $found = $this->find($invitation);
        $resolve->accept($found, $this->user($request));
        $school = School::query()->findOrFail($found->school_id);

        return response()->json(['data' => ['school' => ['code' => $school->code, 'name' => $school->name]]], 201);
    }

    public function decline(Request $request, string $invitation, ResolveInvitation $resolve): JsonResponse
    {
        $resolve->decline($this->find($invitation), $this->user($request));

        return response()->json(null, 204);
    }

    private function find(string $id): MembershipInvitation
    {
        return MembershipInvitation::query()->safe()->find($id) ?? throw new NotFoundHttpException;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
