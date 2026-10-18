<?php

namespace App\Http\Controllers\School;

use App\Domain\Scheduling\Actions\ManageSchedules;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleRequest;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ScheduleController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageSchedules $schedules) {}

    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope_type');
        $grade = $request->query('grade_level');
        $classId = $request->query('class_id');
        $active = $request->query('is_active');

        $page = Schedule::query()
            ->with('schoolClass')
            ->when(is_string($scope) && $scope !== '', fn ($q) => $q->where('scope_type', $scope))
            ->when(is_numeric($grade), fn ($q) => $q->where('grade_level', (int) $grade))
            ->when(is_string($classId) && Str::isUuid($classId), fn ($q) => $q->where('class_id', $classId))
            ->when(in_array($active, ['0', '1', 'true', 'false'], true), fn ($q) => $q->where('is_active', in_array($active, ['1', 'true'], true)))
            ->orderBy('scope_type')
            ->orderBy('grade_level')
            ->orderBy('class_id')
            ->orderByDesc('effective_from')
            ->orderBy('day_of_week')
            ->orderBy('id')
            ->paginate(100);

        return response()->json([
            'data' => collect($page->items())->map(fn (Schedule $s) => self::present($s))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $schedule): JsonResponse
    {
        $found = Schedule::query()->with('schoolClass')->find($schedule) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found)]);
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        /** @var array{scope_type: string, grade_level?: int|null, class_id?: string|null, days: list<int>, check_in_start: string, check_in_deadline: string, late_grace_minutes: int, check_out_start: string, scheduled_check_out: string, effective_from: string} $data */
        $data = $request->validated();
        $created = $this->schedules->publish($this->tenant->school(), $this->actor($request), $data);

        return response()->json(['data' => $created->load('schoolClass')->map(fn (Schedule $s) => self::present($s))->all()], 201);
    }

    public function update(ScheduleRequest $request, School $school, string $schedule): JsonResponse
    {
        /** @var array{effective_until?: string|null, is_active?: bool} $changes */
        $changes = $request->validated();
        $updated = $this->schedules->update($this->tenant->school(), $this->actor($request), $schedule, $changes);

        return response()->json(['data' => self::present($updated->load('schoolClass'))]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Schedule $s): array
    {
        $class = $s->schoolClass;

        return [
            'id' => $s->id,
            'scope_type' => $s->scope_type,
            'grade_level' => $s->grade_level,
            'class' => $class === null ? null : ['id' => $class->id, 'name' => $class->name],
            'day_of_week' => $s->day_of_week,
            'check_in_start' => substr($s->check_in_start, 0, 5),
            'check_in_deadline' => substr($s->check_in_deadline, 0, 5),
            'late_grace_minutes' => $s->late_grace_minutes,
            'check_out_start' => substr($s->check_out_start, 0, 5),
            'scheduled_check_out' => substr($s->scheduled_check_out, 0, 5),
            'effective_from' => $s->effective_from->toDateString(),
            'effective_until' => $s->effective_until?->toDateString(),
            'is_active' => $s->is_active,
        ];
    }
}
