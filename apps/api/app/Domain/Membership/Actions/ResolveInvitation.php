<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Menerima atau menolak undangan oleh penerimanya (ADR 0004/0007 D-37).
 *
 * Menerima = user (baru atau lama) + keanggotaan + peran + penandaan undangan + audit, dalam SATU
 * transaksi. Baris undangan dikunci (FOR UPDATE) sehingga dua penerimaan paralel tidak bisa lolos
 * keduanya (I02/I03). Pemegang token tidak pernah bisa menerima atas nama email lain.
 */
final class ResolveInvitation
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name?: string, password?: string}  $newAccount  dipakai hanya bila email belum terdaftar
     */
    public function accept(MembershipInvitation $found, ?User $loggedIn, array $newAccount = []): User
    {
        $school = School::query()->findOrFail($found->school_id);

        if (! $school->isAccessible()) {
            throw ApiProblem::schoolUnavailable();
        }

        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($found, $school, $loggedIn, $newAccount) {
            $invitation = $this->lockPending($found->id, $school->id);
            $user = User::query()->where('email', $invitation->email)->first();
            $created = false;

            if ($user !== null) {
                if ($loggedIn === null) {
                    throw ApiProblem::loginRequired();
                }
                if ($loggedIn->id !== $user->id) {
                    throw ApiProblem::invitationEmailMismatch();
                }
            } else {
                $user = $this->createAccount($invitation->email, $newAccount);
                $created = true;
            }

            $membership = $this->joinSchool($school, $user, $invitation);

            DB::table('membership_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
                'updated_at' => now(),
            ]);

            if ($created) {
                $this->audit->record('user.account.created', $school->id, 'user', $user->id, after: ['email' => $user->email, 'name' => $user->name, 'status' => $user->status], actor: $user);
            }
            $this->audit->record('school.member.joined', $school->id, 'school_membership', $membership->id, after: [
                'user_id' => $user->id, 'status' => SchoolMembership::STATUS_ACTIVE, 'roles' => $invitation->role_codes,
            ], actor: $user);
            $this->audit->record('school.invitation.accepted', $school->id, 'membership_invitation', $invitation->id, after: [
                'email' => $invitation->email, 'role_codes' => $invitation->role_codes, 'accepted_at' => now()->toIso8601String(),
            ], actor: $user);

            return $user;
        }));
    }

    public function decline(MembershipInvitation $found, User $user): void
    {
        $this->database->runInSchool($found->school_id, fn () => DB::transaction(function () use ($found, $user) {
            $invitation = $this->lockPending($found->id, $found->school_id);

            if (strcasecmp($invitation->email, $user->email) !== 0) {
                throw ApiProblem::invitationEmailMismatch();
            }

            DB::table('membership_invitations')->where('id', $invitation->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->audit->record('school.invitation.declined', $invitation->school_id, 'membership_invitation', $invitation->id, after: [
                'email' => $invitation->email, 'revoked_at' => now()->toIso8601String(),
            ], actor: $user);
        }));
    }

    private function lockPending(string $invitationId, string $schoolId): MembershipInvitation
    {
        $locked = DB::table('membership_invitations')
            ->where('id', $invitationId)
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first(['id']);

        $invitation = $locked === null ? null : MembershipInvitation::query()->safe()->find($invitationId);

        if ($invitation === null || ! $invitation->isPending()) {
            throw ApiProblem::invitationUnavailable();
        }

        return $invitation;
    }

    /**
     * @param  array{name?: string, password?: string}  $account
     */
    private function createAccount(string $email, array $account): User
    {
        $user = new User;
        $user->forceFill([
            'email' => $email,
            'name' => (string) ($account['name'] ?? ''),
            'password' => (string) ($account['password'] ?? ''),
            'status' => User::STATUS_ACTIVE,
            // Kepemilikan email terbukti lewat kepemilikan token (dikirim ke email itu).
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function joinSchool(School $school, User $user, MembershipInvitation $invitation): SchoolMembership
    {
        $membership = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($membership !== null && $membership->status !== SchoolMembership::STATUS_ENDED) {
            throw ApiProblem::alreadyMember();
        }

        if ($membership === null) {
            $membership = SchoolMembership::query()->create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'status' => SchoolMembership::STATUS_ACTIVE,
            ]);
        } else {
            DB::table('membership_roles')->where('membership_id', $membership->id)->delete();
            $membership->forceFill([
                'status' => SchoolMembership::STATUS_ACTIVE,
                'status_reason' => null,
                'ended_at' => null,
                'joined_at' => now(),
            ])->save();
        }

        foreach ($invitation->role_codes as $roleCode) {
            DB::table('membership_roles')->insert([
                'school_id' => $school->id,
                'membership_id' => $membership->id,
                'role_code' => $roleCode,
                'granted_by_user_id' => $invitation->invited_by_user_id,
            ]);
        }

        return $membership;
    }
}
