<?php

namespace App\Http\Controllers\School;

use App\Domain\Tenancy\Actions\ManageSchools;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSchoolRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class SchoolProfileController extends Controller
{
    public function show(TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => $this->present($tenant)]);
    }

    public function update(UpdateSchoolRequest $request, TenantContext $tenant, ManageSchools $schools): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $schools->update($actor, $tenant->school(), $request->validated(), platform: false);

        return response()->json(['data' => $this->present($tenant)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TenantContext $tenant): array
    {
        $school = $tenant->school();

        return [
            'code' => $school->code,
            'npsn' => $school->npsn,
            'name' => $school->name,
            'education_levels' => $school->education_levels,
            'timezone' => $school->timezone,
            'address' => $school->address,
            'status' => $school->status,
            'my_roles' => array_map(fn ($role) => $role->value, $tenant->roles()),
            'my_permissions' => array_map(fn ($p) => $p->value, $tenant->permissions()),
        ];
    }
}
