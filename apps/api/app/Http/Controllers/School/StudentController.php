<?php

namespace App\Http\Controllers\School;

use App\Domain\Academic\Actions\ManageStudents;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollmentRequest;
use App\Http\Requests\StudentRequest;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StudentController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageStudents $students, private readonly ClassScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $classId = $request->query('class_id');
        $search = $request->query('q');
        $today = $this->today();

        // Penempatan yang berlaku hari ini (zona waktu sekolah).
        $covering = fn ($q) => $q->where('start_date', '<=', $today)->where(fn ($e) => $e->whereNull('end_date')->orWhere('end_date', '>=', $today));

        $visible = $this->scope->classIds();

        $page = Student::query()
            ->with(['enrollments' => $covering, 'enrollments.schoolClass'])
            ->when($visible !== null, fn ($q) => $q->whereHas('enrollments', fn ($e) => $covering($e)->whereIn('class_id', $visible ?? [])))
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->when(is_string($classId) && Str::isUuid($classId), fn ($q) => $q->whereHas('enrollments', fn ($e) => $covering($e)->where('class_id', $classId)))
            ->when(is_string($search) && trim($search) !== '', function ($q) use ($search) {
                $like = '%'.addcslashes(trim($search), '\\%_').'%';

                return $q->where(fn ($w) => $w->where('full_name', 'ilike', $like)->orWhere('student_number', 'ilike', $like)->orWhere('nisn', 'like', $like));
            })
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Student $s) => self::present($s, $today))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $student): JsonResponse
    {
        $found = Student::query()->with('enrollments.schoolClass.academicYear')->find($student) ?? throw new NotFoundHttpException;
        $today = $this->today();

        // Wali kelas hanya melihat siswa yang saat ini ada di kelas yang ia ampu.
        if (($visible = $this->scope->classIds()) !== null) {
            $inScope = $found->enrollments->contains(
                fn (ClassEnrollment $e) => in_array($e->class_id, $visible, true)
                    && $e->start_date->toDateString() <= $today
                    && ($e->end_date === null || $e->end_date->toDateString() >= $today),
            );
            throw_unless($inScope, NotFoundHttpException::class);
        }

        return response()->json(['data' => self::present($found, $today, history: true)]);
    }

    public function store(StudentRequest $request): JsonResponse
    {
        /** @var array{student_number: string, nisn?: string|null, full_name: string, date_of_birth?: string|null} $data */
        $data = $request->validated();
        $created = $this->students->create($this->tenant->school(), $this->actor($request), $data);

        return response()->json(['data' => self::present($created->load('enrollments.schoolClass.academicYear'), $this->today(), history: true)], 201);
    }

    public function update(StudentRequest $request, School $school, string $student): JsonResponse
    {
        $updated = $this->students->update($this->tenant->school(), $this->actor($request), $student, $request->validated());

        return response()->json(['data' => self::present($updated->load('enrollments.schoolClass.academicYear'), $this->today(), history: true)]);
    }

    public function enroll(EnrollmentRequest $request, School $school, string $student): JsonResponse
    {
        /** @var array{class_id: string, start_date: string} $data */
        $data = $request->validated();
        $this->students->enroll($this->tenant->school(), $this->actor($request), $student, $data['class_id'], $data['start_date']);

        $found = Student::query()->with('enrollments.schoolClass.academicYear')->find($student) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found, $this->today(), history: true)], 201);
    }

    private function today(): string
    {
        return Carbon::now($this->tenant->school()->timezone)->toDateString();
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
    public static function present(Student $student, string $today, bool $history = false): array
    {
        $current = $student->enrollments->first(
            fn (ClassEnrollment $e) => $e->start_date->toDateString() <= $today
                && ($e->end_date === null || $e->end_date->toDateString() >= $today),
        );

        $data = [
            'id' => $student->id,
            'student_number' => $student->student_number,
            'nisn' => $student->nisn,
            'full_name' => $student->full_name,
            'date_of_birth' => $student->date_of_birth?->toDateString(),
            'status' => $student->status,
            'current_class' => $current === null ? null : ['id' => $current->schoolClass->id, 'name' => $current->schoolClass->name],
        ];

        if ($history) {
            $data['enrollments'] = $student->enrollments->map(fn (ClassEnrollment $e) => [
                'id' => $e->id,
                'class' => [
                    'id' => $e->schoolClass->id,
                    'name' => $e->schoolClass->name,
                    'academic_year' => $e->schoolClass->academicYear->name,
                ],
                'start_date' => $e->start_date->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
            ])->values()->all();
        }

        return $data;
    }
}
