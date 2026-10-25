<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mengubah peran dan status keanggotaan (TAHAP_1_FONDASI §5).
 *
 * Invarian (R01–R03):
 * - admin aktif terakhir tidak boleh dicabut perannya, ditangguhkan, atau diakhiri;
 * - user tidak boleh mengubah perannya sendiri;
 * - alasan wajib (divalidasi di FormRequest) dan tersimpan di audit beserta before/after.
 * Baris sekolah dikunci (FOR UPDATE) sehingga dua perubahan paralel tidak bisa sama-sama menghapus
 * admin terakhir.
 */
final class ManageMembers
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  non-empty-list<Role>  $roles
     */
    public function changeRoles(School $school, User $actor, string $membershipId, array $roles, string $reason): SchoolMembership
    {
        return $this->mutate($school, $membershipId, function (SchoolMembership $membership) use ($school, $actor, $roles, $reason) {
            if ($membership->user_id === $actor->id) {
                throw ApiProblem::cannotChangeOwnRoles();
            }
            $this->assertNotEnded($membership);
            // Akun siswa dikelola dari data siswa; peran lain tidak boleh ditempelkan padanya (ADR 0010 §1).
            if (DB::table('membership_roles')->where('membership_id', $membership->id)->where('role_code', Role::Student->value)->exists()) {
                throw ApiProblem::invalidStateTransition('Peran akun siswa tidak dapat diubah dari manajemen anggota.');
            }

            $newCodes = array_map(fn (Role $role) => $role->value, $roles);
            sort($newCodes);
            $before = $this->snapshot($membership);

            if (! in_array(Role::SchoolAdmin->value, $newCodes, true)) {
                $this->assertNotLastAdmin($school, $membership);
            }

            DB::table('membership_roles')->where('membership_id', $membership->id)->whereNotIn('role_code', $newCodes)->delete();
            $existing = DB::table('membership_roles')->where('membership_id', $membership->id)->pluck('role_code')->all();
            foreach (array_diff($newCodes, $existing) as $code) {
                DB::table('membership_roles')->insert([
                    'school_id' => $school->id,
                    'membership_id' => $membership->id,
                    'role_code' => $code,
                    'granted_by_user_id' => $actor->id,
                ]);
            }

            $membership->unsetRelation('roles');
            $this->audit->record('school.member.roles_changed', $school->id, 'school_membership', $membership->id, $before, $this->snapshot($membership), $reason, $actor);
        });
    }

    public function suspend(School $school, User $actor, string $membershipId, string $reason): SchoolMembership
    {
        return $this->transition($school, $actor, $membershipId, [SchoolMembership::STATUS_ACTIVE], SchoolMembership::STATUS_SUSPENDED, 'school.member.suspended', $reason, guardLastAdmin: true);
    }

    public function reactivate(School $school, User $actor, string $membershipId, ?string $reason): SchoolMembership
    {
        return $this->transition($school, $actor, $membershipId, [SchoolMembership::STATUS_SUSPENDED], SchoolMembership::STATUS_ACTIVE, 'school.member.reactivated', $reason, guardLastAdmin: false);
    }

    public function end(School $school, User $actor, string $membershipId, string $reason): SchoolMembership
    {
        return $this->transition($school, $actor, $membershipId, [SchoolMembership::STATUS_ACTIVE, SchoolMembership::STATUS_SUSPENDED], SchoolMembership::STATUS_ENDED, 'school.member.ended', $reason, guardLastAdmin: true);
    }

    /**
     * Mengirim tautan reset ke email pemilik akun (ADR 0007/D-37): admin tidak pernah melihat atau menetapkan
     * password. Hanya anggota aktif milik orang lain; penerima tetap harus membuka tautan di emailnya.
     */
    public function requestPasswordReset(School $school, User $actor, string $membershipId, string $reason): void
    {
        $this->mutate($school, $membershipId, function (SchoolMembership $membership) use ($school, $actor, $reason) {
            if ($membership->user_id === $actor->id) {
                throw ApiProblem::invalidStateTransition('Gunakan "Lupa password" untuk akun Anda sendiri.');
            }
            if ($membership->status !== SchoolMembership::STATUS_ACTIVE) {
                throw ApiProblem::invalidStateTransition('Hanya anggota aktif yang dapat dikirimi tautan reset.');
            }

            $user = User::query()->find($membership->user_id) ?? throw new NotFoundHttpException;
            if (! $user->isActive()) {
                throw ApiProblem::invalidStateTransition('Akun pengguna tidak aktif.');
            }

            $status = Password::sendResetLink(['email' => $user->email], function ($recipient, string $token) {
                if ($recipient instanceof User) {
                    $recipient->sendPasswordResetNotification($token);
                }
            });
            if ($status === Password::RESET_THROTTLED) {
                throw ApiProblem::resetThrottled();
            }

            $this->audit->record('school.member.password_reset_requested', $school->id, 'school_membership', $membership->id, null, null, $reason, $actor);
        });
    }

    /**
     * @param  list<string>  $allowedFrom
     */
    private function transition(School $school, User $actor, string $membershipId, array $allowedFrom, string $to, string $action, ?string $reason, bool $guardLastAdmin): SchoolMembership
    {
        return $this->mutate($school, $membershipId, function (SchoolMembership $membership) use ($school, $actor, $allowedFrom, $to, $action, $reason, $guardLastAdmin) {
            if (! in_array($membership->status, $allowedFrom, true)) {
                throw ApiProblem::invalidStateTransition("Keanggotaan berstatus '{$membership->status}' tidak dapat diubah menjadi '{$to}'.");
            }
            if ($guardLastAdmin) {
                $this->assertNotLastAdmin($school, $membership);
            }

            $before = $this->snapshot($membership);

            DB::table('school_memberships')->where('id', $membership->id)->update([
                'status' => $to,
                'status_reason' => $reason,
                'ended_at' => $to === SchoolMembership::STATUS_ENDED ? now() : null,
                'updated_at' => now(),
            ]);
            $membership->refresh();

            $this->audit->record($action, $school->id, 'school_membership', $membership->id, $before, $this->snapshot($membership), $reason, $actor);
        });
    }

    /**
     * Konteks sekolah + transaksi + kunci baris sekolah (serialisasi pemeriksaan admin terakhir).
     *
     * @param  callable(SchoolMembership): mixed  $change
     */
    private function mutate(School $school, string $membershipId, callable $change): SchoolMembership
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $membershipId, $change) {
            DB::table('schools')->where('id', $school->id)->lockForUpdate()->first(['id']);

            $membership = SchoolMembership::query()
                ->where('school_id', $school->id)
                ->with('roles')
                ->find($membershipId) ?? throw new NotFoundHttpException;

            $change($membership);

            return $membership->fresh(['roles', 'user']) ?? $membership;
        }));
    }

    private function assertNotEnded(SchoolMembership $membership): void
    {
        if ($membership->status === SchoolMembership::STATUS_ENDED) {
            throw ApiProblem::invalidStateTransition('Keanggotaan sudah berakhir.');
        }
    }

    private function assertNotLastAdmin(School $school, SchoolMembership $membership): void
    {
        $isActiveAdmin = $membership->status === SchoolMembership::STATUS_ACTIVE
            && DB::table('membership_roles')->where('membership_id', $membership->id)->where('role_code', Role::SchoolAdmin->value)->exists();

        if (! $isActiveAdmin) {
            return;
        }

        $otherAdmins = DB::table('school_memberships as m')
            ->join('membership_roles as r', 'r.membership_id', '=', 'm.id')
            ->where('m.school_id', $school->id)
            ->where('m.status', SchoolMembership::STATUS_ACTIVE)
            ->where('r.role_code', Role::SchoolAdmin->value)
            ->where('m.id', '!=', $membership->id)
            ->count();

        if ($otherAdmins === 0) {
            throw ApiProblem::lastSchoolAdmin();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SchoolMembership $membership): array
    {
        $roles = DB::table('membership_roles')->where('membership_id', $membership->id)->orderBy('role_code')->pluck('role_code')->all();

        return [
            'user_id' => $membership->user_id,
            'status' => $membership->status,
            'status_reason' => $membership->status_reason,
            'roles' => $roles,
        ];
    }
}
