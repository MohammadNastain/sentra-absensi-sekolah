<?php

namespace App\Http\Controllers\School;

use App\Domain\Academic\Actions\ManageClasses;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolClassRequest;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SchoolClassController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageClasses $classes) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $year = $request->query('academic_year_id');
        $grade = $request->query('grade_level');

        $page = SchoolClass::query()
            ->with(['academicYear', 'homeroomTeacher'])
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->when(is_string($year) && Str::isUuid($year), fn ($q) => $q->where('academic_year_id', $year))
            ->when(is_numeric($grade), fn ($q) => $q->where('grade_level', (int) $grade))
            ->orderBy('grade_level')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (SchoolClass $c) => self::present($c))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $schoolClass): JsonResponse
    {
        $found = SchoolClass::query()->with(['academicYear', 'homeroomTeacher'])->find($schoolClass) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found)]);
    }

    public function store(SchoolClassRequest $request): JsonResponse
    {
        /** @var array{academic_year_id: string, name: string, grade_level: int, homeroom_teacher_id?: string|null} $data */
        $data = $request->validated();
        $created = $this->classes->create($this->tenant->school(), $this->actor($request), $data);

        return response()->json(['data' => self::present($created->load(['academicYear', 'homeroomTeacher']))], 201);
    }

    public function update(SchoolClassRequest $request, School $school, string $schoolClass): JsonResponse
    {
        $updated = $this->classes->update($this->tenant->school(), $this->actor($request), $schoolClass, $request->validated());

        return response()->json(['data' => self::present($updated->load(['academicYear', 'homeroomTeacher']))]);
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
    public static function present(SchoolClass $class): array
    {
        $teacher = $class->homeroomTeacher;

        return [
            'id' => $class->id,
            'name' => $class->name,
            'grade_level' => $class->grade_level,
            'status' => $class->status,
            'academic_year' => ['id' => $class->academicYear->id, 'name' => $class->academicYear->name],
            'homeroom_teacher' => $teacher === null ? null : ['id' => $teacher->id, 'full_name' => $teacher->full_name],
        ];
    }
}
