<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Entitlement\Feature;
use App\Domain\Entitlement\ManageEntitlements;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Paket, fitur, dan override per sekolah untuk Super Admin (ADR 0009 §6). Perubahan menerima `dry_run` (pratinjau
 * dampak) dan `confirm_impact` (wajib bila perubahan mematikan fitur lain).
 */
final class PlatformEntitlementController extends Controller
{
    public function __construct(private readonly ManageEntitlements $entitlements) {}

    public function features(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (Feature $f) => [
            'code' => $f->value,
            'label' => $f->label(),
            'parent' => $f->parent()?->value,
            'requires' => array_map(fn (Feature $r) => $r->value, $f->requires()),
            'released' => $f->released(),
        ], Feature::cases())]);
    }

    public function plans(): JsonResponse
    {
        $features = DB::table('plan_features')->orderBy('feature_code')->get()->groupBy('plan_code');

        return response()->json(['data' => DB::table('plans')->orderBy('price_per_student_idr')->get()->map(fn ($plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'price_per_student_idr' => $plan->price_per_student_idr,
            'min_monthly_idr' => $plan->min_monthly_idr,
            'is_active' => $plan->is_active,
            'features' => $features->get($plan->code, collect())->pluck('feature_code')->values()->all(),
        ])->all()]);
    }

    public function show(School $school): JsonResponse
    {
        return response()->json(['data' => $this->entitlements->view($school)]);
    }

    public function changePlan(Request $request, School $school): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:20']] + self::changeRules());

        return response()->json(['data' => $this->entitlements->changePlan(
            $this->actor($request), $school, $data['plan_code'], $data['reason'] ?? null, (bool) ($data['dry_run'] ?? false), (bool) ($data['confirm_impact'] ?? false),
        )]);
    }

    public function setOverride(Request $request, School $school, string $feature): JsonResponse
    {
        $data = $request->validate(['effect' => ['required', 'in:grant,revoke']] + self::changeRules());

        return response()->json(['data' => $this->entitlements->setOverride(
            $this->actor($request), $school, $this->feature($feature), $data['effect'], $data['reason'] ?? null, (bool) ($data['dry_run'] ?? false), (bool) ($data['confirm_impact'] ?? false),
        )]);
    }

    public function removeOverride(Request $request, School $school, string $feature): JsonResponse
    {
        $data = $request->validate(self::changeRules());

        return response()->json(['data' => $this->entitlements->removeOverride(
            $this->actor($request), $school, $this->feature($feature), $data['reason'] ?? null, (bool) ($data['dry_run'] ?? false), (bool) ($data['confirm_impact'] ?? false),
        )]);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function changeRules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'dry_run' => ['sometimes', 'boolean'],
            'confirm_impact' => ['sometimes', 'boolean'],
        ];
    }

    private function feature(string $code): Feature
    {
        return Feature::tryFrom($code) ?? throw new NotFoundHttpException;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
