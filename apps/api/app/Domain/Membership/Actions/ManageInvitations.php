<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Membership\InvitationToken;
use App\Domain\Tenancy\DatabaseContext;
use App\Jobs\SendInvitationEmail;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Membuat, mengirim ulang, dan mencabut undangan keanggotaan (ADR 0004, TAHAP_1_FONDASI §3).
 * Setiap perubahan dan audit-nya berada dalam satu transaksi; email dikirim sesudah commit.
 */
final class ManageInvitations
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  non-empty-list<Role>  $roles
     */
    public function invite(School $school, User $inviter, string $email, array $roles): MembershipInvitation
    {
        $token = InvitationToken::generate();

        $invitation = $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $inviter, $email, $roles, $token) {
            $this->assertNotAlreadyMember($school, $email);
            $this->retireStaleInvitation($school, $email);

            $id = (string) Str::uuid7();
            $roleCodes = array_map(fn (Role $role) => $role->value, $roles);
            sort($roleCodes);

            DB::table('membership_invitations')->insert([
                'id' => $id,
                'school_id' => $school->id,
                'email' => $email,
                'role_codes' => '{'.implode(',', $roleCodes).'}',
                'token_hash' => InvitationToken::hash($token),
                'invited_by_user_id' => $inviter->id,
                'expires_at' => now()->addDays(InvitationToken::LIFETIME_DAYS),
            ]);

            $invitation = MembershipInvitation::query()->safe()->findOrFail($id);
            $this->audit->record(
                'school.invitation.created',
                $school->id,
                'membership_invitation',
                $id,
                after: $this->snapshot($invitation),
                actor: $inviter,
            );

            return $invitation;
        }));

        SendInvitationEmail::dispatch($school->id, $invitation->id, $token);

        return $invitation->fresh() ?? $invitation;
    }

    public function resend(School $school, User $actor, string $invitationId): MembershipInvitation
    {
        $token = InvitationToken::generate();

        $invitation = $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $invitationId, $token) {
            $invitation = $this->lockUnresolved($school, $invitationId);
            $before = $this->snapshot($invitation);

            DB::table('membership_invitations')->where('id', $invitationId)->update([
                'token_hash' => InvitationToken::hash($token),
                'expires_at' => now()->addDays(InvitationToken::LIFETIME_DAYS),
                'delivery_status' => 'queued',
                'delivery_error' => null,
                'updated_at' => now(),
            ]);

            $updated = MembershipInvitation::query()->safe()->findOrFail($invitationId);
            $this->audit->record('school.invitation.resent', $school->id, 'membership_invitation', $invitationId, $before, $this->snapshot($updated), actor: $actor);

            return $updated;
        }));

        SendInvitationEmail::dispatch($school->id, $invitation->id, $token);

        return $invitation->fresh() ?? $invitation;
    }

    public function revoke(School $school, User $actor, string $invitationId): MembershipInvitation
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $invitationId) {
            $invitation = $this->lockUnresolved($school, $invitationId);
            $before = $this->snapshot($invitation);

            DB::table('membership_invitations')->where('id', $invitationId)->update(['revoked_at' => now(), 'updated_at' => now()]);

            $updated = MembershipInvitation::query()->safe()->findOrFail($invitationId);
            $this->audit->record('school.invitation.revoked', $school->id, 'membership_invitation', $invitationId, $before, $this->snapshot($updated), actor: $actor);

            return $updated;
        }));
    }

    private function assertNotAlreadyMember(School $school, string $email): void
    {
        $userId = User::query()->where('email', $email)->value('id');

        if ($userId === null) {
            return;
        }

        $exists = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $userId)
            ->where('status', '!=', SchoolMembership::STATUS_ENDED)
            ->exists();

        if ($exists) {
            throw ApiProblem::alreadyMember();
        }
    }

    /**
     * Indeks unik "satu undangan tertunda per email" (I05) juga mencakup undangan kedaluwarsa yang belum
     * dicabut. Undangan kedaluwarsa dicabut otomatis; yang masih berlaku menolak undangan baru.
     */
    private function retireStaleInvitation(School $school, string $email): void
    {
        $existing = DB::table('membership_invitations')
            ->where('school_id', $school->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first(['id', 'expires_at']);

        if ($existing === null) {
            return;
        }

        if (now()->lt($existing->expires_at)) {
            throw ApiProblem::invitationAlreadyPending();
        }

        DB::table('membership_invitations')->where('id', $existing->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    private function lockUnresolved(School $school, string $invitationId): MembershipInvitation
    {
        $row = DB::table('membership_invitations')
            ->where('id', $invitationId)
            ->where('school_id', $school->id)
            ->lockForUpdate()
            ->first(['id', 'accepted_at', 'revoked_at']);

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        if ($row->accepted_at !== null || $row->revoked_at !== null) {
            throw ApiProblem::invalidStateTransition('Undangan sudah diterima atau dicabut.');
        }

        return MembershipInvitation::query()->safe()->findOrFail($invitationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MembershipInvitation $invitation): array
    {
        return [
            'email' => $invitation->email,
            'role_codes' => $invitation->role_codes,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'delivery_status' => $invitation->delivery_status,
        ];
    }
}
