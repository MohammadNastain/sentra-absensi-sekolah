<?php

namespace App\Http\Controllers\Me;

use App\Domain\Identity\Permission;
use App\Http\Controllers\Controller;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar sekolah milik user (keanggotaan active/suspended). Dibaca lewat policy RLS
 * `own_memberships_read` (konteks app.user_id), tanpa konteks sekolah.
 */
final class MySchoolsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', SchoolMembership::STATUS_ENDED)
            ->with(['school', 'roles'])
            ->get()
            ->sortBy(fn (SchoolMembership $m) => $m->school->name)
            ->values();

        return response()->json([
            'data' => $memberships->map(fn (SchoolMembership $m) => [
                'school' => [
                    'code' => $m->school->code,
                    'name' => $m->school->name,
                    'status' => $m->school->status,
                    'accessible' => $m->school->isAccessible(),
                ],
                'membership_status' => $m->status,
                'roles' => array_map(fn ($role) => $role->value, $m->roleEnums()),
                'permissions' => array_map(fn ($p) => $p->value, Permission::grantedTo($m->roleEnums())),
            ])->all(),
        ]);
    }
}
