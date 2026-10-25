<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Operasi tenant oleh Super Admin (platform). Audit platform memakai school_id NULL sehingga hanya
 * terlihat dalam konteks platform; entity_id menunjuk sekolahnya (DATABASE_DESIGN §3.6).
 */
final class ManageSchools
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): School
    {
        return DB::transaction(function () use ($actor, $attributes) {
            $plan = is_string($attributes['plan_code'] ?? null) ? $attributes['plan_code'] : 'legacy';
            $trialEnds = is_string($attributes['trial_ends_on'] ?? null) ? $attributes['trial_ends_on'] : null;
            $status = is_string($attributes['status'] ?? null) ? $attributes['status'] : School::STATUS_ACTIVE;
            unset($attributes['plan_code'], $attributes['trial_ends_on'], $attributes['status']);

            $school = new School;
            $school->fill($attributes);
            $school->status = $status;
            $school->save();

            // Setiap sekolah selalu punya langganan (ADR 0009 §8); tanggal uji coba hanya berlaku bagi sekolah berstatus trial.
            $trial = $school->status === School::STATUS_TRIAL;
            DB::table('school_subscriptions')->insert([
                'school_id' => $school->id,
                'plan_code' => $plan,
                'status' => $trial ? 'trial' : 'active',
                'ends_on' => $trial ? $trialEnds : null,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record('platform.school.created', null, 'school', $school->id, after: $this->snapshot($school), actor: $actor);

            return $school;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, School $school, array $attributes, bool $platform): School
    {
        return DB::transaction(function () use ($actor, $school, $attributes, $platform) {
            $before = $this->snapshot($school);
            $school->fill($attributes)->save();

            $this->audit->record(
                $platform ? 'platform.school.updated' : 'school.profile.updated',
                $platform ? null : $school->id,
                'school',
                $school->id,
                $before,
                $this->snapshot($school),
                actor: $actor,
            );

            return $school;
        });
    }

    public function suspend(User $actor, School $school, string $reason): School
    {
        return $this->changeStatus($actor, $school, [School::STATUS_TRIAL, School::STATUS_ACTIVE], School::STATUS_SUSPENDED, 'platform.school.suspended', $reason);
    }

    public function activate(User $actor, School $school, ?string $reason): School
    {
        return $this->changeStatus($actor, $school, [School::STATUS_SUSPENDED], School::STATUS_ACTIVE, 'platform.school.activated', $reason);
    }

    /**
     * @param  list<string>  $allowedFrom
     */
    private function changeStatus(User $actor, School $school, array $allowedFrom, string $to, string $action, ?string $reason): School
    {
        return DB::transaction(function () use ($actor, $school, $allowedFrom, $to, $action, $reason) {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $allowedFrom, true)) {
                throw ApiProblem::invalidStateTransition("Sekolah berstatus '{$locked->status}' tidak dapat diubah menjadi '{$to}'.");
            }

            $before = $this->snapshot($locked);
            $locked->status = $to;
            $locked->save();

            $this->audit->record($action, null, 'school', $locked->id, $before, $this->snapshot($locked), $reason, $actor);

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(School $school): array
    {
        return $school->only(['code', 'npsn', 'name', 'education_levels', 'timezone', 'address', 'status']);
    }
}
