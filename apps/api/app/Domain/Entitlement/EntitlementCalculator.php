<?php

namespace App\Domain\Entitlement;

/**
 * Aturan akses efektif (ADR 0009 §3), murni tanpa database agar mudah diuji.
 *
 * Fitur efektif bila: sudah dirilis; dinyatakan aktif oleh paket atau override `grant` (override `revoke`
 * mengalahkan paket); dan induk serta semua prasyaratnya juga efektif.
 */
final class EntitlementCalculator
{
    public const SOURCE_PLAN = 'plan';

    public const SOURCE_GRANT = 'override_grant';

    public const SOURCE_REVOKE = 'override_revoke';

    public const SOURCE_NOT_IN_PLAN = 'not_in_plan';

    public const SOURCE_BLOCKED = 'blocked_by_dependency';

    public const SOURCE_UNRELEASED = 'unreleased';

    /**
     * @param  list<string>  $planFeatures  kode fitur pada paket aktif sekolah
     * @param  array<string, string>  $overrides  kode fitur => 'grant' atau 'revoke'
     * @return array<string, array{enabled: bool, source: string, missing: list<string>}> menurut kode fitur
     */
    public function resolve(array $planFeatures, array $overrides): array
    {
        $inPlan = array_flip($planFeatures);
        $result = [];

        foreach (Feature::cases() as $feature) {
            $this->state($feature, $inPlan, $overrides, $result);
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $inPlan
     * @param  array<string, string>  $overrides
     * @param  array<string, array{enabled: bool, source: string, missing: list<string>}>  $result
     * @return array{enabled: bool, source: string, missing: list<string>}
     */
    private function state(Feature $feature, array $inPlan, array $overrides, array &$result): array
    {
        if (isset($result[$feature->value])) {
            return $result[$feature->value];
        }

        $override = $overrides[$feature->value] ?? null;

        if (! $feature->released()) {
            return $result[$feature->value] = ['enabled' => false, 'source' => self::SOURCE_UNRELEASED, 'missing' => []];
        }

        if ($override === 'revoke') {
            return $result[$feature->value] = ['enabled' => false, 'source' => self::SOURCE_REVOKE, 'missing' => []];
        }

        if ($override !== 'grant' && ! isset($inPlan[$feature->value])) {
            return $result[$feature->value] = ['enabled' => false, 'source' => self::SOURCE_NOT_IN_PLAN, 'missing' => []];
        }

        $source = $override === 'grant' ? self::SOURCE_GRANT : self::SOURCE_PLAN;
        $missing = [];

        foreach ($feature->prerequisites() as $prerequisite) {
            if (! $this->state($prerequisite, $inPlan, $overrides, $result)['enabled']) {
                $missing[] = $prerequisite->value;
            }
        }

        return $result[$feature->value] = $missing === []
            ? ['enabled' => true, 'source' => $source, 'missing' => []]
            : ['enabled' => false, 'source' => self::SOURCE_BLOCKED, 'missing' => $missing];
    }
}
