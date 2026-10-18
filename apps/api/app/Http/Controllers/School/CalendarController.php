<?php

namespace App\Http\Controllers\School;

use App\Domain\Scheduling\ResolvedDay;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Kalender satu bulan: tiap tanggal berisi hari libur atau jadwal yang berlaku, memakai ScheduleResolver yang
 * sama dengan jalur absensi. Tanpa class_id yang dihitung hanya jadwal sekolah; dengan class_id berlaku
 * prioritas kelas > jenjang > sekolah untuk kelas itu.
 */
final class CalendarController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScheduleResolver $resolver, private readonly ClassScope $scope) {}

    public function show(Request $request, School $school): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'class_id' => ['nullable', 'bail', 'uuid'],
        ]);

        $class = null;
        $classId = $validated['class_id'] ?? null;
        if (is_string($classId) && Str::isUuid($classId)) {
            $class = SchoolClass::query()->find($classId);
            abort_if($class === null || ! $this->scope->allows($class->id), 404);
        }

        $first = Carbon::parse($validated['month'].'-01')->startOfMonth();

        $days = $this->resolver->resolveRange($this->tenant->school()->id, $first, $first->copy()->endOfMonth(), $class);

        return response()->json([
            'data' => [
                'month' => $validated['month'],
                'class_id' => $class?->id,
                'days' => array_values(array_map(fn (ResolvedDay $d) => [
                    'date' => $d->date,
                    'day_of_week' => $d->dayOfWeek,
                    'holiday' => $d->holiday === null ? null : ['id' => $d->holiday->id, 'name' => $d->holiday->name],
                    'schedule' => $d->schedule === null ? null : ScheduleController::present($d->schedule),
                ], $days)),
            ],
        ]);
    }
}
