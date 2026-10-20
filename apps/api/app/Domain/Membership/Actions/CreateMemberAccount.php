<?php

namespace App\Domain\Membership\Actions;

use App\Domain\Academic\Actions\ManageTeachers;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Role;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Admin Sekolah membuat akun anggota langsung (email + password), tanpa undangan surel. Untuk peran Wali Kelas,
 * data guru dibuat dan ditautkan sekaligus supaya guru itu dapat langsung ditetapkan sebagai wali sebuah kelas.
 */
final class CreateMemberAccount
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly ManageTeachers $teachers,
    ) {}

    /**
     * @param  non-empty-list<Role>  $roles
     */
    public function create(School $school, User $actor, string $name, string $email, string $password, array $roles, ?string $employeeNumber = null): SchoolMembership
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $name, $email, $password, $roles, $employeeNumber) {
            if (User::query()->where('email', $email)->exists()) {
                throw ApiProblem::duplicateValue('Email sudah terdaftar. Gunakan email lain untuk akun ini.');
            }

            $user = new User;
            $user->forceFill([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                // Admin yang menyerahkan akun langsung; tidak ada tautan surel yang perlu dibuktikan.
                'email_verified_at' => now(),
            ])->save();

            $membership = SchoolMembership::query()->create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'status' => SchoolMembership::STATUS_ACTIVE,
            ]);

            $codes = array_map(fn (Role $role) => $role->value, $roles);
            foreach ($codes as $code) {
                DB::table('membership_roles')->insert([
                    'school_id' => $school->id,
                    'membership_id' => $membership->id,
                    'role_code' => $code,
                    'granted_by_user_id' => $actor->id,
                ]);
            }

            $this->audit->record('user.account.created', $school->id, 'user', $user->id, after: ['email' => $user->email, 'name' => $user->name, 'status' => $user->status], actor: $actor);
            $this->audit->record('school.member.joined', $school->id, 'school_membership', $membership->id, after: [
                'user_id' => $user->id, 'status' => SchoolMembership::STATUS_ACTIVE, 'roles' => $codes,
            ], actor: $actor);

            if (in_array(Role::HomeroomTeacher, $roles, true)) {
                $this->teachers->create($school, $actor, ['full_name' => $name, 'employee_number' => $employeeNumber, 'membership_id' => $membership->id]);
            }

            // joined_at diisi default database; muat ulang agar tersedia bagi presenter.
            return $membership->refresh()->load(['user', 'roles']);
        }));
    }

    /** Admin menetapkan password baru untuk anggota tanpa surel (password diserahkan manual). */
    public function setPassword(School $school, User $actor, string $membershipId, string $password): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $membershipId, $password) {
            $membership = SchoolMembership::query()->where('school_id', $school->id)->with('user')->findOrFail($membershipId);
            $user = $membership->user;
            $user->forceFill(['password' => $password])->save();

            $this->audit->record('school.member.password_set', $school->id, 'school_membership', $membership->id, after: ['user_id' => $user->id], actor: $actor);
        }));
    }
}
