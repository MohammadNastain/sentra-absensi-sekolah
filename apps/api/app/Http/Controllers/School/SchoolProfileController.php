<?php

namespace App\Http\Controllers\School;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class SchoolProfileController extends Controller
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $school = $tenant->school();

        return response()->json([
            'data' => [
                'code' => $school->code,
                'npsn' => $school->npsn,
                'name' => $school->name,
                'education_levels' => $school->education_levels,
                'timezone' => $school->timezone,
                'address' => $school->address,
                'status' => $school->status,
                'my_roles' => array_map(fn ($role) => $role->value, $tenant->roles()),
                'my_permissions' => array_map(fn ($p) => $p->value, $tenant->permissions()),
            ],
        ]);
    }
}
