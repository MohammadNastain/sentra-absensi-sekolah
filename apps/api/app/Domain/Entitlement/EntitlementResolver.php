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
     * @return array<string, array{enabled: bool, source: string, missing: list<string>}>
     */
    public function matrix(string $schoolId): array
    {
        $plan = DB::table('school_subscriptions')
            ->where('school_id', $schoolId)->where('status', '<>', 'ended')
            ->value('plan_code');

        $planFeatures = $plan === null ? [] : array_values(array_map('strval', DB::table('plan_features')->where('plan_code', $plan)->pluck('feature_code')->all()));
        $overrides = DB::table('school_feature_overrides')->where('school_id', $schoolId)->pluck('effect', 'feature_code')->map(fn ($effect) => (string) $effect)->all();

        return $this->calculator->resolve($planFeatures, $overrides);
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
