<?php

namespace App\Http\Controllers\School;

use App\Domain\Academic\Actions\ManageTeachers;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherRequest;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TeacherController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageTeachers $teachers, private readonly ClassScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $search = $request->query('q');

        $page = Teacher::query()
            ->with('membership.user')
            ->when($this->scope->isRestricted(), fn ($q) => $q->where('membership_id', $this->tenant->membership()->id))
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->when(is_string($search) && trim($search) !== '', function ($q) use ($search) {
                $like = '%'.addcslashes(trim($search), '\\%_').'%';

                return $q->where(fn ($w) => $w->where('full_name', 'ilike', $like)->orWhere('employee_number', 'ilike', $like));
            })
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Teacher $t) => self::present($t))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $teacher): JsonResponse
    {
        $found = Teacher::query()->with('membership.user')->find($teacher) ?? throw new NotFoundHttpException;
        // Pengguna yang dibatasi hanya melihat data gurunya sendiri.
        if ($this->scope->isRestricted() && $found->membership_id !== $this->tenant->membership()->id) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => self::present($found)]);
    }

    public function store(TeacherRequest $request): JsonResponse
    {
        /** @var array{full_name: string, employee_number?: string|null, membership_id?: string|null} $data */
        $data = $request->validated();
        $created = $this->teachers->create($this->tenant->school(), $this->actor($request), $data);

        return response()->json(['data' => self::present($created->load('membership.user'))], 201);
    }

    public function update(TeacherRequest $request, School $school, string $teacher): JsonResponse
    {
        $updated = $this->teachers->update($this->tenant->school(), $this->actor($request), $teacher, $request->validated());

        return response()->json(['data' => self::present($updated->load('membership.user'))]);
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
    public static function present(Teacher $teacher): array
    {
        $membership = $teacher->membership;

        return [
            'id' => $teacher->id,
            'full_name' => $teacher->full_name,
            'employee_number' => $teacher->employee_number,
            'status' => $teacher->status,
            'account' => $membership === null ? null : [
                'membership_id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'status' => $membership->status,
            ],
        ];
    }
}
