<?php

namespace App\Domain\Entitlement;

use App\Domain\Audit\AuditLogger;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pengelolaan paket dan override fitur sebuah sekolah oleh Super Admin (ADR 0009 §4, §7).
 *
 * Setiap perubahan dihitung dulu sebagai "apa yang terjadi bila diterapkan" (matriks sebelum dan sesudah). Dampak yang
 * mematikan fitur lain wajib dikonfirmasi; `dry_run` hanya menampilkan pratinjau. Override tidak pernah dihapus diam-diam.
 * Konteks platform wajib aktif (RLS tabel langganan/override hanya mengizinkan platform menulis).
 *
 * Bentuk hasil: ['applied' => bool, 'unchanged' => bool, 'requires_confirmation' => bool, 'impact' => [...],
 * 'entitlements' => matriks sesudah (hanya bila diterapkan)].
 */
final class ManageEntitlements
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Matriks lengkap untuk halaman "Akses Modul & Fitur".
     *
     * @return array<string, mixed>
     */
    public function view(School $school): array
    {
        $state = $this->resolver->state($school->id);
        $matrix = $this->calculator->resolve($state['plan_features'], $state['overrides']);
        $subscription = DB::table('school_subscriptions as s')
            ->join('plans as p', 'p.code', '=', 's.plan_code')
            ->where('s.school_id', $school->id)->where('s.status', '<>', 'ended')
            ->first(['s.plan_code', 'p.name as plan_name', 's.status', 's.starts_on', 's.ends_on']);

        return [
            'subscription' => $subscription === null ? null : [
                'plan_code' => $subscription->plan_code,
                'plan_name' => $subscription->plan_name,
                'status' => $subscription->status,
                'starts_on' => $subscription->starts_on,
                'ends_on' => $subscription->ends_on,
            ],
            'features' => array_map(fn (Feature $feature) => [
                'code' => $feature->value,
                'label' => $feature->label(),
                'parent' => $feature->parent()?->value,
                'requires' => array_map(fn (Feature $f) => $f->value, $feature->requires()),
                'released' => $feature->released(),
                'in_plan' => in_array($feature->value, $state['plan_features'], true),
                'override' => $state['overrides'][$feature->value] ?? null,
                'enabled' => $matrix[$feature->value]['enabled'],
                'source' => $matrix[$feature->value]['source'],
                'missing' => $matrix[$feature->value]['missing'],
            ], Feature::cases()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changePlan(User $actor, School $school, string $planCode, ?string $reason, bool $dryRun, bool $confirmImpact): array
    {
        return DB::transaction(function () use ($actor, $school, $planCode, $reason, $dryRun, $confirmImpact) {
            $this->lock($school);
            $state = $this->resolver->state($school->id);

            $plan = DB::table('plans')->where('code', $planCode)->first();
            if ($plan === null || (! $plan->is_active && $planCode !== $state['plan'])) {
                throw ValidationException::withMessages(['plan_code' => 'Paket tidak dikenal atau tidak ditawarkan.']);
            }
            if ($planCode === $state['plan']) {
                throw ValidationException::withMessages(['plan_code' => 'Sekolah sudah memakai paket ini.']);
            }

            $newFeatures = $this->resolver->planFeatures($planCode);
            $impact = $this->impact($state['plan_features'], $state['overrides'], $newFeatures, $state['overrides']);
            $needsConfirmation = $impact['lost'] !== [];

            if ($dryRun) {
                return $this->preview($impact, $needsConfirmation);
            }
            if ($needsConfirmation && ! $confirmImpact) {
                throw ApiProblem::impactConfirmationRequired($impact);
            }

            $previous = DB::table('school_subscriptions')->where('school_id', $school->id)->where('status', '<>', 'ended')->first();
            if ($previous !== null) {
                DB::update("UPDATE school_subscriptions SET status = 'ended', ends_on = CURRENT_DATE, updated_at = now() WHERE id = ?", [$previous->id]);
            }
            $id = DB::table('school_subscriptions')->insertGetId([
                'school_id' => $school->id,
                'plan_code' => $planCode,
                'status' => $previous->status ?? 'active',
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                'platform.subscription.changed', null, 'school_subscription', (string) $id,
                $previous === null ? null : ['school_id' => $school->id, 'plan_code' => $previous->plan_code, 'status' => $previous->status],
                ['school_id' => $school->id, 'plan_code' => $planCode, 'status' => $previous->status ?? 'active', 'impact' => $impact],
                $reason, $actor,
            );

            return $this->applied($school, $impact);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setOverride(User $actor, School $school, Feature $feature, string $effect, ?string $reason, bool $dryRun, bool $confirmImpact): array
    {
        return DB::transaction(function () use ($actor, $school, $feature, $effect, $reason, $dryRun, $confirmImpact) {
            $this->lock($school);
            $state = $this->resolver->state($school->id);
            $current = $state['overrides'][$feature->value] ?? null;

            $newOverrides = [...$state['overrides'], $feature->value => $effect];
            $after = $this->calculator->resolve($state['plan_features'], $newOverrides);

            if ($effect === 'grant') {
                if (! $feature->released()) {
                    throw ApiProblem::featureUnreleased($feature);
                }
                if (! $after[$feature->value]['enabled']) {
                    throw ApiProblem::featureDependencyMissing($feature, $after[$feature->value]['missing']);
                }
            }

            $impact = $this->impact($state['plan_features'], $state['overrides'], $state['plan_features'], $newOverrides);
            $needsConfirmation = array_values(array_diff($impact['lost'], [$feature->value])) !== [];

            if ($current === $effect) {
                return ['applied' => false, 'unchanged' => true, 'requires_confirmation' => false, 'impact' => $impact];
            }
            if ($dryRun) {
                return $this->preview($impact, $needsConfirmation);
            }
            if ($needsConfirmation && ! $confirmImpact) {
                throw ApiProblem::impactConfirmationRequired($impact);
            }

            $existing = DB::table('school_feature_overrides')->where('school_id', $school->id)->where('feature_code', $feature->value)->first();
            if ($existing === null) {
                $id = (string) DB::table('school_feature_overrides')->insertGetId([
                    'school_id' => $school->id, 'feature_code' => $feature->value, 'effect' => $effect,
                    'reason' => $reason, 'created_by_user_id' => $actor->id,
                ]);
            } else {
                $id = (string) $existing->id;
                DB::update('UPDATE school_feature_overrides SET effect = ?, reason = ?, created_by_user_id = ?, updated_at = now() WHERE id = ?', [$effect, $reason, $actor->id, $id]);
            }

            $this->audit->record(
                'platform.entitlement.override_set', null, 'school_feature_override', $id,
                $current === null ? null : ['school_id' => $school->id, 'feature_code' => $feature->value, 'effect' => $current],
                ['school_id' => $school->id, 'feature_code' => $feature->value, 'effect' => $effect, 'impact' => $impact],
                $reason, $actor,
            );

            return $this->applied($school, $impact);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function removeOverride(User $actor, School $school, Feature $feature, ?string $reason, bool $dryRun, bool $confirmImpact): array
    {
        return DB::transaction(function () use ($actor, $school, $feature, $reason, $dryRun, $confirmImpact) {
            $this->lock($school);
            $state = $this->resolver->state($school->id);
            $existing = DB::table('school_feature_overrides')->where('school_id', $school->id)->where('feature_code', $feature->value)->first();
            if ($existing === null) {
                throw new NotFoundHttpException;
            }

            $newOverrides = $state['overrides'];
            unset($newOverrides[$feature->value]);
            $impact = $this->impact($state['plan_features'], $state['overrides'], $state['plan_features'], $newOverrides);
            $needsConfirmation = array_values(array_diff($impact['lost'], [$feature->value])) !== [];

            if ($dryRun) {
                return $this->preview($impact, $needsConfirmation);
            }
            if ($needsConfirmation && ! $confirmImpact) {
                throw ApiProblem::impactConfirmationRequired($impact);
            }

            DB::delete('DELETE FROM school_feature_overrides WHERE id = ?', [$existing->id]);

            $this->audit->record(
                'platform.entitlement.override_removed', null, 'school_feature_override', (string) $existing->id,
                ['school_id' => $school->id, 'feature_code' => $feature->value, 'effect' => $existing->effect],
                null, $reason, $actor,
            );

            return $this->applied($school, $impact);
        });
    }

    /** Serialkan perubahan entitlement per sekolah agar pratinjau dan penerapan membaca keadaan yang sama. */
    private function lock(School $school): void
    {
        School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Selisih akses efektif sebelum dan sesudah perubahan.
     *
     * - lost: efektif sebelumnya, tidak lagi sesudahnya (termasuk fitur turunan yang tertahan prasyarat)
     * - gained: sebaliknya
     * - redundant_overrides: override yang setelah perubahan sama saja dengan paket (grant pada fitur yang sudah di paket,
     *   revoke pada fitur yang tidak ada di paket); tetap tersimpan, hanya ditandai agar Super Admin memutuskan
     *
     * @param  list<string>  $oldPlan
     * @param  array<string, string>  $oldOverrides
     * @param  list<string>  $newPlan
     * @param  array<string, string>  $newOverrides
     * @return array{lost: list<string>, gained: list<string>, redundant_overrides: list<string>}
     */
    private function impact(array $oldPlan, array $oldOverrides, array $newPlan, array $newOverrides): array
    {
        $before = $this->calculator->resolve($oldPlan, $oldOverrides);
        $after = $this->calculator->resolve($newPlan, $newOverrides);
        $lost = [];
        $gained = [];

        foreach (Feature::cases() as $feature) {
            $was = $before[$feature->value]['enabled'];
            $is = $after[$feature->value]['enabled'];
            if ($was && ! $is) {
                $lost[] = $feature->value;
            } elseif (! $was && $is) {
                $gained[] = $feature->value;
            }
        }

        $redundant = [];
        foreach ($newOverrides as $code => $effect) {
            $inPlan = in_array($code, $newPlan, true);
            if (($effect === 'grant' && $inPlan) || ($effect === 'revoke' && ! $inPlan)) {
                $redundant[] = $code;
            }
        }
        sort($redundant);

        return ['lost' => $lost, 'gained' => $gained, 'redundant_overrides' => $redundant];
    }

    /**
     * @param  array{lost: list<string>, gained: list<string>, redundant_overrides: list<string>}  $impact
     * @return array<string, mixed>
     */
    private function preview(array $impact, bool $needsConfirmation): array
    {
        return ['applied' => false, 'unchanged' => false, 'requires_confirmation' => $needsConfirmation, 'impact' => $impact];
    }

    /**
     * @param  array{lost: list<string>, gained: list<string>, redundant_overrides: list<string>}  $impact
     * @return array<string, mixed>
     */
    private function applied(School $school, array $impact): array
    {
        return ['applied' => true, 'unchanged' => false, 'requires_confirmation' => false, 'impact' => $impact, 'entitlements' => $this->view($school)];
    }
}
