<?php

namespace App\Http\Controllers\School;

use App\Domain\Academic\Actions\ManageAcademicYears;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicYearRequest;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcademicYearController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageAcademicYears $years) {}

    public function index(): JsonResponse
    {
        $page = AcademicYear::query()->orderByDesc('start_date')->orderBy('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (AcademicYear $y) => self::present($y))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $academicYear): JsonResponse
    {
        $found = AcademicYear::query()->find($academicYear) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found)]);
    }

    public function store(AcademicYearRequest $request): JsonResponse
    {
        /** @var array{name: string, start_date: string, end_date: string} $data */
        $data = $request->validated();

        return response()->json(['data' => self::present($this->years->create($this->tenant->school(), $this->actor($request), $data))], 201);
    }

    public function update(AcademicYearRequest $request, School $school, string $academicYear): JsonResponse
    {
        return response()->json(['data' => self::present($this->years->update($this->tenant->school(), $this->actor($request), $academicYear, $request->validated()))]);
    }

    public function activate(Request $request, School $school, string $academicYear): JsonResponse
    {
        return response()->json(['data' => self::present($this->years->activate($this->tenant->school(), $this->actor($request), $academicYear))]);
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
    public static function present(AcademicYear $year): array
    {
        return [
            'id' => $year->id,
            'name' => $year->name,
            'start_date' => $year->start_date->toDateString(),
            'end_date' => $year->end_date->toDateString(),
            'is_active' => $year->is_active,
        ];
    }
}
