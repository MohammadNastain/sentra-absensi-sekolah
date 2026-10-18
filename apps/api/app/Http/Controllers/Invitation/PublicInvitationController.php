<?php

namespace App\Http\Controllers\Invitation;

use App\Domain\Identity\PasswordRules;
use App\Domain\Membership\Actions\ResolveInvitation;
use App\Http\Controllers\Controller;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoint publik berbasis token undangan. Token tidak valid, kedaluwarsa, dicabut, atau sudah dipakai
 * selalu menghasilkan respons yang sama (410) agar status undangan tidak dapat ditebak.
 */
final class PublicInvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = $this->pending($token);
        $school = School::query()->findOrFail($invitation->school_id);

        return response()->json(['data' => [
            'school' => ['name' => $school->name],
            'email' => $invitation->maskedEmail(),
            'roles' => DB::table('roles')->whereIn('code', $invitation->role_codes)->orderBy('code')->pluck('name')->all(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'account_exists' => User::query()->where('email', $invitation->email)->exists(),
        ]]);
    }

    public function accept(Request $request, string $token, ResolveInvitation $resolve): JsonResponse
    {
        $invitation = $this->pending($token);
        $accountExists = User::query()->where('email', $invitation->email)->exists();

        $account = [];
        if (! $accountExists) {
            $account = Validator::make($request->all(), [
                'name' => ['required', 'string', 'min:2', 'max:150'],
                'password' => PasswordRules::forNewPassword(),
            ], [
                'name.required' => 'Nama lengkap wajib diisi.',
                'password.confirmed' => 'Konfirmasi password tidak sama.',
            ])->validate();
        }

        $user = $resolve->accept($invitation, $request->user(), $account);
        $school = School::query()->findOrFail($invitation->school_id);

        return response()->json(['data' => [
            'school' => ['code' => $school->code, 'name' => $school->name],
            'account_created' => ! $accountExists,
            'email' => $user->email,
        ]], 201);
    }

    private function pending(string $token): MembershipInvitation
    {
        $invitation = MembershipInvitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw ApiProblem::invitationUnavailable();
        }

        return $invitation;
    }
}
