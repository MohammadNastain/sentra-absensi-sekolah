<?php

namespace App\Http\Controllers\School;

use App\Domain\Scheduling\Actions\ManageHolidays;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\HolidayRequest;
use App\Models\Holiday;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HolidayController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageHolidays $holidays) {}

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $date = '/^\d{4}-\d{2}-\d{2}$/';

        // Libur yang beririsan dengan rentang from..to (keduanya opsional).
        $page = Holiday::query()
            ->when(is_string($from) && preg_match($date, $from) === 1, fn ($q) => $q->where('end_date', '>=', $from))
            ->when(is_string($to) && preg_match($date, $to) === 1, fn ($q) => $q->where('start_date', '<=', $to))
            ->orderBy('start_date')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Holiday $h) => self::present($h))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(School $school, string $holiday): JsonResponse
    {
        return response()->json(['data' => self::present(Holiday::query()->find($holiday) ?? throw new NotFoundHttpException)]);
    }

    public function store(HolidayRequest $request): JsonResponse
    {
        /** @var array{name: string, start_date: string, end_date: string} $data */
        $data = $request->validated();

        return response()->json(['data' => self::present($this->holidays->create($this->tenant->school(), $this->actor($request), $data))], 201);
    }

    public function update(HolidayRequest $request, School $school, string $holiday): JsonResponse
    {
        return response()->json(['data' => self::present($this->holidays->update($this->tenant->school(), $this->actor($request), $holiday, $request->validated()))]);
    }

    public function destroy(Request $request, School $school, string $holiday): Response
    {
        $this->holidays->delete($this->tenant->school(), $this->actor($request), $holiday);

        return response()->noContent();
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
    public static function present(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'start_date' => $holiday->start_date->toDateString(),
            'end_date' => $holiday->end_date->toDateString(),
        ];
    }
}
