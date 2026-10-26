<?php

namespace App\Domain\Guardians;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Membership\Actions\ManageInvitations;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin mengelola wali dan tautannya ke anak (ADR 0011 §1–§2, §8). Wali baru diundang lewat surel (alur undangan yang
 * ada); orang yang sudah menjadi anggota sekolah (mis. guru) mendapat peran wali pada keanggotaannya tanpa undangan.
 * Satu siswa maksimal {@see StudentGuardian::MAX_PER_STUDENT} wali dan satu wali utama. Semua perubahan beraudit.
 */
final class ManageGuardians
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly ManageInvitations $invitations,
    ) {}

    /**
     * Wali baru untuk satu anak: dibuat, ditautkan, lalu diundang (atau langsung diberi peran bila sudah anggota sekolah).
     *
     * @param  array{full_name: string, email: string, phone?: string|null, relationship: string, is_primary?: bool}  $fields
     */
    public function createAndLink(School $school, User $actor, string $studentId, array $fields): Guardian
    {
        $guardian = $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentId, $fields) {
            $this->lockStudent($studentId);
            $this->assertRoom($studentId);

            $pending = DB::table('membership_invitations')->where('school_id', $school->id)->where('email', $fields['email'])
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
            if ($pending) {
                throw ApiProblem::invitationAlreadyPending();
            }

            $existingUser = User::query()->where('email', $fields['email'])->first();
            $membership = $existingUser === null ? null : SchoolMembership::query()
                ->where('school_id', $school->id)->where('user_id', $existingUser->id)->where('status', '!=', SchoolMembership::STATUS_ENDED)
                ->lockForUpdate()->first();

            if ($membership !== null && Guardian::query()->where('membership_id', $membership->id)->exists()) {
                throw ApiProblem::invalidStateTransition('Akun ini sudah terdaftar sebagai wali. Tautkan wali yang sudah ada ke anak ini.');
            }

            $guardian = Guardian::query()->create([
                'school_id' => $school->id, 'full_name' => $this->clean($fields['full_name']), 'phone' => $this->phone($fields['phone'] ?? null),
                'membership_id' => $membership?->id, 'status' => Guardian::STATUS_ACTIVE,
            ]);
            $this->audit->record('school.guardian.created', $school->id, 'guardian', $guardian->id, after: self::audited($guardian), actor: $actor);

            if ($membership !== null) {
                $this->grantGuardianRole($school, $actor, $membership);
            }
            $this->link($school, $actor, $studentId, $guardian->id, $fields['relationship'], (bool) ($fields['is_primary'] ?? false));

            return $guardian;
        }));

        if ($guardian->membership_id === null) {
            $this->invite($school, $actor, $guardian, $fields['email']);
        }

        return $guardian->fresh() ?? $guardian;
    }

    /** Menautkan wali yang sudah ada ke anak lain (mis. kakak-adik). */
    public function linkExisting(School $school, User $actor, string $studentId, string $guardianId, string $relationship, bool $primary): StudentGuardian
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentId, $guardianId, $relationship, $primary) {
            $this->lockStudent($studentId);
            Guardian::query()->findOrFail($guardianId);
            if (StudentGuardian::query()->where('student_id', $studentId)->where('guardian_id', $guardianId)->exists()) {
                throw ApiProblem::duplicateValue('Wali ini sudah ditautkan ke siswa ini.');
            }
            $this->assertRoom($studentId);

            return $this->link($school, $actor, $studentId, $guardianId, $relationship, $primary);
        }));
    }

    /**
     * @param  array{relationship?: string, is_primary?: bool}  $changes
     */
    public function updateLink(School $school, User $actor, string $studentId, string $guardianId, array $changes): StudentGuardian
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentId, $guardianId, $changes) {
            $this->lockStudent($studentId);
            $link = StudentGuardian::query()->where('student_id', $studentId)->where('guardian_id', $guardianId)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
            $before = self::auditedLink($link);

            if (isset($changes['relationship'])) {
                $link->relationship = $changes['relationship'];
            }
            if (array_key_exists('is_primary', $changes)) {
                if ($changes['is_primary']) {
                    StudentGuardian::query()->where('student_id', $studentId)->where('id', '!=', $link->id)->update(['is_primary' => false]);
                    $link->is_primary = true;
                } elseif ($link->is_primary) {
                    // Wali utama tidak dicabut begitu saja: jadikan wali lain utama, atau biarkan.
                    throw ApiProblem::invalidStateTransition('Pilih wali lain sebagai wali utama untuk mengganti.');
                }
            }
            $link->save();
            $this->audit->record('school.student_guardian.updated', $school->id, 'student_guardian', $link->id, $before, self::auditedLink($link), actor: $actor);

            return $link;
        }));
    }

    public function unlink(School $school, User $actor, string $studentId, string $guardianId): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $studentId, $guardianId) {
            $this->lockStudent($studentId);
            $link = StudentGuardian::query()->where('student_id', $studentId)->where('guardian_id', $guardianId)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
            $before = self::auditedLink($link);
            $link->delete();

            // Wali utama yang dilepas digantikan wali tertua yang tersisa, agar selalu ada satu.
            if ($link->is_primary) {
                StudentGuardian::query()->where('student_id', $studentId)->orderBy('created_at')->orderBy('id')->limit(1)->update(['is_primary' => true]);
            }
            $this->audit->record('school.student_guardian.unlinked', $school->id, 'student_guardian', $link->id, $before, null, actor: $actor);
        }));
    }

    /**
     * @param  array{full_name?: string, phone?: string|null, status?: string}  $changes
     */
    public function update(School $school, User $actor, string $guardianId, array $changes): Guardian
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $guardianId, $changes) {
            $guardian = Guardian::query()->lockForUpdate()->find($guardianId) ?? throw new NotFoundHttpException;
            $before = self::audited($guardian);

            if (isset($changes['full_name'])) {
                $guardian->full_name = $this->clean($changes['full_name']);
            }
            if (array_key_exists('phone', $changes)) {
                $guardian->phone = $this->phone($changes['phone']);
            }
            if (isset($changes['status'])) {
                $guardian->status = $changes['status'];
            }
            $guardian->save();
            $this->audit->record('school.guardian.updated', $school->id, 'guardian', $guardian->id, $before, self::audited($guardian), actor: $actor);

            return $guardian;
        }));
    }

    /** Mengirim ulang undangan wali; undangan yang dicabut atau ditolak diganti undangan baru ke surel yang sama. */
    public function reinvite(School $school, User $actor, string $guardianId, ?string $email = null): Guardian
    {
        $state = $this->database->runInSchool($school->id, function () use ($guardianId, $email) {
            $guardian = Guardian::query()->find($guardianId) ?? throw new NotFoundHttpException;
            if ($guardian->membership_id !== null) {
                throw ApiProblem::invalidStateTransition('Wali ini sudah memiliki akun.');
            }
            $invitation = $guardian->invitation_id === null ? null : DB::table('membership_invitations')->where('id', $guardian->invitation_id)->first(['id', 'email', 'accepted_at', 'revoked_at']);
            if ($invitation === null && $email === null) {
                throw ApiProblem::invalidStateTransition('Wali ini belum pernah diundang; sertakan surelnya.');
            }

            return [$guardian, $invitation];
        });
        [$guardian, $invitation] = $state;

        if ($invitation !== null && $invitation->accepted_at === null && $invitation->revoked_at === null) {
            $this->invitations->resend($school, $actor, $invitation->id);
        } else {
            $this->invite($school, $actor, $guardian, $email ?? (string) $invitation?->email);
        }

        return $guardian->fresh() ?? $guardian;
    }

    /** Undangan baru untuk wali ini; id-nya disimpan di wali agar penerimaan mengisi keanggotaannya. */
    private function invite(School $school, User $actor, Guardian $guardian, string $email): void
    {
        $invitation = $this->invitations->invite($school, $actor, $email, [Role::Guardian]);
        $this->database->runInSchool($school->id, fn () => Guardian::query()->whereKey($guardian->id)->update(['invitation_id' => $invitation->id]));
    }

    private function link(School $school, User $actor, string $studentId, string $guardianId, string $relationship, bool $primary): StudentGuardian
    {
        $hasPrimary = StudentGuardian::query()->where('student_id', $studentId)->where('is_primary', true)->exists();
        $makePrimary = $primary || ! $hasPrimary;
        if ($makePrimary) {
            StudentGuardian::query()->where('student_id', $studentId)->update(['is_primary' => false]);
        }

        $link = StudentGuardian::query()->create([
            'school_id' => $school->id, 'student_id' => $studentId, 'guardian_id' => $guardianId, 'relationship' => $relationship, 'is_primary' => $makePrimary,
        ]);
        $this->audit->record('school.student_guardian.linked', $school->id, 'student_guardian', $link->id, after: self::auditedLink($link), actor: $actor);

        return $link;
    }

    /** Anggota yang sudah ada diberi peran wali pada keanggotaannya (peran lain tetap). */
    private function grantGuardianRole(School $school, User $actor, SchoolMembership $membership): void
    {
        $before = DB::table('membership_roles')->where('membership_id', $membership->id)->pluck('role_code')->map(fn ($c) => (string) $c)->sort()->values()->all();
        DB::table('membership_roles')->insert([
            'school_id' => $school->id, 'membership_id' => $membership->id, 'role_code' => Role::Guardian->value, 'granted_by_user_id' => $actor->id,
        ]);
        $after = [...$before, Role::Guardian->value];
        sort($after);
        $this->audit->record('school.member.roles_changed', $school->id, 'school_membership', $membership->id, ['roles' => $before], ['roles' => $after], actor: $actor);
    }

    private function lockStudent(string $studentId): void
    {
        Student::query()->lockForUpdate()->find($studentId) ?? throw new NotFoundHttpException;
    }

    private function assertRoom(string $studentId): void
    {
        if (StudentGuardian::query()->where('student_id', $studentId)->count() >= StudentGuardian::MAX_PER_STUDENT) {
            throw ApiProblem::invalidStateTransition('Satu siswa maksimal memiliki '.StudentGuardian::MAX_PER_STUDENT.' wali.');
        }
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    private function phone(?string $phone): ?string
    {
        $phone = $phone === null ? '' : trim($phone);

        return $phone === '' ? null : $phone;
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(Guardian $guardian): array
    {
        return ['full_name' => $guardian->full_name, 'membership_id' => $guardian->membership_id, 'status' => $guardian->status];
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditedLink(StudentGuardian $link): array
    {
        return ['student_id' => $link->student_id, 'guardian_id' => $link->guardian_id, 'relationship' => $link->relationship, 'is_primary' => $link->is_primary];
    }
}
