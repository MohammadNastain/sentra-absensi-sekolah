<?php

namespace App\Http\Controllers\School;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Fitur yang efektif bagi sekolah aktif. Hanya untuk UX (menyaring menu); penegakan tetap di setiap rute (ADR 0009).
 * Rute inti: tidak digerbang fitur supaya halaman "tidak termasuk paket" tetap dapat memuat daftar ini.
 */
final class EntitlementController extends Controller
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $plan = DB::table('school_subscriptions as s')->join('plans as p', 'p.code', '=', 's.plan_code')
            ->where('s.school_id', $tenant->school()->id)->where('s.status', '<>', 'ended')
            ->first(['p.code', 'p.name']);

        return response()->json(['data' => [
            'plan' => $plan === null ? null : ['code' => $plan->code, 'name' => $plan->name],
            'features' => $tenant->enabledFeatures(),
        ]]);
    }
}
