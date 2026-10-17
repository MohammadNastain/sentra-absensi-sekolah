<?php

namespace App\Http\Controllers\Platform;

use App\Models\MembershipInvitation;

/**
 * Bentuk respons undangan. Tidak pernah memuat token (hanya ada di email) maupun hash-nya.
 */
final class InvitationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(MembershipInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'roles' => $invitation->role_codes,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'delivery_status' => $invitation->delivery_status,
            'delivery_error' => $invitation->delivery_error,
            'invited_by_user_id' => $invitation->invited_by_user_id,
            'created_at' => $invitation->created_at->toIso8601String(),
        ];
    }
}
