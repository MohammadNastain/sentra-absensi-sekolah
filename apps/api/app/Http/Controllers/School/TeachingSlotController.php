<?php

namespace App\Http\Controllers\School;

use App\Domain\Teaching\ManageTeachingSlots;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeachingSlotRequest;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TeachingSlotController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ManageTeachingSlots $slots,
        private readonly ClassScope $scope,
    ) {}

    /**
     * Seluruh jam mengajar kelas aktif, urut hari lalu jam (jumlahnya terbatas oleh jumlah kelas). Wali kelas
     * (pengguna yang dibatasi) hanya melihat jadwal kelas yang ia ampu dan jam yang ia ajar sendiri.
     */
    public function index(Request $request): JsonResponse
    {
        $classId = $request->query('class_id');
        $teacherId = $request->query('teacher_id');
        $day = $request->query('day_of_week');

        $slots = TeachingSlot::query()
            ->with(['schoolClass', 'subject', 'teacher'])
            ->whereHas('schoolClass', fn ($q) => $q->where('status', 'active'))
            ->when($this->scope->isRestricted(), function ($q) {
                $q->where(function ($w) {
                    $w->whereIn('class_id', $this->scope->classIds() ?? [])
                        ->orWhereIn('teacher_id', Teacher::query()->where('membership_id', $this->tenant->membership()->id)->select('id'));
                });
            })
            ->when(is_string($classId) && $classId !== '', fn ($q) => $q->where('class_id', $classId))
            ->when(is_string($teacherId) && $teacherId !== '', fn ($q) => $q->where('teacher_id', $teacherId))
            ->when(is_string($day) && ctype_digit($day), fn ($q) => $q->where('day_of_week', (int) $day))
            ->orderBy('day_of_week')->orderBy('start_time')->orderBy('id')
            ->limit(5000)
            ->get();

        return response()->json(['data' => $slots->map(fn (TeachingSlot $s) => self::present($s))->all()]);
    }

    public function store(TeachingSlotRequest $request): JsonResponse
    {
        /** @var array{class_id: string, subject_id: string, teacher_id: string, day_of_week: int, start_time: string, end_time: string} $data */
        $data = $request->validated();
        $created = $this->slots->create($this->tenant->school(), $this->actor($request), $data);

        return response()->json(['data' => self::present($this->load($created->id))], 201);
    }

    public function update(TeachingSlotRequest $request, School $school, string $slot): JsonResponse
    {
        $updated = $this->slots->update($this->tenant->school(), $this->actor($request), $slot, $request->validated());

        return response()->json(['data' => self::present($this->load($updated->id))]);
    }

    public function destroy(Request $request, School $school, string $slot): Response
    {
        $this->slots->delete($this->tenant->school(), $this->actor($request), $slot);

        return response()->noContent();
    }

    private function load(string $id): TeachingSlot
    {
        return TeachingSlot::query()->with(['schoolClass', 'subject', 'teacher'])->find($id) ?? throw new NotFoundHttpException;
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
    public static function present(TeachingSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'day_of_week' => $slot->day_of_week,
            'start_time' => substr($slot->start_time, 0, 5),
            'end_time' => substr($slot->end_time, 0, 5),
            'class' => ['id' => $slot->schoolClass->id, 'name' => $slot->schoolClass->name],
            'subject' => ['id' => $slot->subject->id, 'name' => $slot->subject->name],
            'teacher' => ['id' => $slot->teacher->id, 'full_name' => $slot->teacher->full_name],
        ];
    }
}
