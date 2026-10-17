<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;

final class PlatformSchoolController extends Controller
{
    public function index(): JsonResponse
    {
        $schools = School::query()->orderBy('name')->paginate(50);

        return response()->json([
            'data' => collect($schools->items())->map(fn (School $school) => [
                'id' => $school->id,
                'code' => $school->code,
                'npsn' => $school->npsn,
                'name' => $school->name,
                'education_levels' => $school->education_levels,
                'timezone' => $school->timezone,
                'status' => $school->status,
            ])->all(),
            'meta' => [
                'current_page' => $schools->currentPage(),
                'last_page' => $schools->lastPage(),
                'total' => $schools->total(),
            ],
        ]);
    }
}
