<?php

namespace App\Domain\Entitlement;

use Illuminate\Support\Facades\DB;

/**
 * Membaca paket aktif dan override sebuah sekolah lalu menghitung akses efektif (ADR 0009).
 * Sekolah tanpa langganan aktif tidak memiliki fitur apa pun (gagal tertutup).
 *
 * Boleh dipanggil dalam konteks sekolah itu sendiri maupun konteks platform; query selalu menyaring school_id.
 */
final class EntitlementResolver
{
    public function __construct(private readonly EntitlementCalculator $calculator = new EntitlementCalculator) {}

    /**
     * Keadaan mentah yang dibutuhkan kalkulator: paket berjalan, isinya, dan override.
     *
     * @return array{plan: string|null, plan_features: list<string>, overrides: array<string, string>}
     */
    public function state(string $schoolId): array
    {
        $plan = DB::table('school_subscriptions')
            ->where('school_id', $schoolId)->where('status', '<>', 'ended')
            ->value('plan_code');
        $plan = is_string($plan) ? $plan : null;

        return [
            'plan' => $plan,
            'plan_features' => $plan === null ? [] : $this->planFeatures($plan),
            'overrides' => array_map('strval', DB::table('school_feature_overrides')->where('school_id', $schoolId)->pluck('effect', 'feature_code')->all()),
        ];
    }

    /**
     * @return list<string>
     */
    public function planFeatures(string $plan): array
    {
        return array_values(array_map('strval', DB::table('plan_features')->where('plan_code', $plan)->pluck('feature_code')->all()));
    }

    /**
     * @return array<string, array{enabled: bool, source: string, missing: list<string>}>
     */
    public function matrix(string $schoolId): array
    {
        $state = $this->state($schoolId);

        return $this->calculator->resolve($state['plan_features'], $state['overrides']);
    }

    /** @return list<string> kode fitur yang efektif, terurut */
    public function enabled(string $schoolId): array
    {
        $codes = array_keys(array_filter($this->matrix($schoolId), fn (array $state) => $state['enabled']));
        sort($codes);

        return $codes;
    }

    public function allows(string $schoolId, Feature $feature): bool
    {
        return $this->matrix($schoolId)[$feature->value]['enabled'];
    }
}
