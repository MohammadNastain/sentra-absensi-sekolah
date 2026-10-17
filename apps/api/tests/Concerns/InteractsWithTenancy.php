<?php

namespace Tests\Concerns;

use App\Domain\Identity\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait InteractsWithTenancy
{
    /**
     * Membuat keanggotaan (dengan peran) dalam konteks sekolah, seperti yang dilakukan aplikasi.
     *
     * @param  list<Role>  $roles
     */
    protected function addMember(School $school, User $user, array $roles = [], string $status = SchoolMembership::STATUS_ACTIVE): SchoolMembership
    {
        return $this->dbContext()->runInSchool($school->id, function () use ($school, $user, $roles, $status) {
            $membership = SchoolMembership::query()->create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'status' => $status,
                'ended_at' => $status === SchoolMembership::STATUS_ENDED ? now() : null,
            ]);

            foreach ($roles as $role) {
                DB::table('membership_roles')->insert([
                    'school_id' => $school->id,
                    'membership_id' => $membership->id,
                    'role_code' => $role->value,
                ]);
            }

            return $membership;
        });
    }

    protected function makePlatformAdmin(User $user): User
    {
        DB::table('platform_admins')->insert(['user_id' => $user->id]);

        return $user;
    }

    /**
     * Login sebagai Super Admin yang sudah lulus tantangan 2FA di sesi ini.
     */
    protected function actingAsPlatformAdminWithTwoFactor(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'auth.login_at' => now()->getTimestamp(),
            'auth.two_factor_passed_user_id' => $user->id,
        ]);
    }
}
