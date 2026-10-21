<?php

namespace App\Http\Controllers\School;

use App\Domain\Teaching\ManageSubjects;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SubjectController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageSubjects $subjects) {}

    /** Seluruh mapel sekolah (jumlahnya kecil), urut nama, beserta pemakaiannya di jadwal. */
    public function index(): JsonResponse
    {
        $usage = TeachingSlot::query()->selectRaw('subject_id, count(*) as total')->groupBy('subject_id')->pluck('total', 'subject_id');

        return response()->json([
            'data' => Subject::query()->orderByRaw('lower(name)')->orderBy('id')->get()
                ->map(fn (Subject $s) => self::present($s, (int) ($usage[$s->id] ?? 0)))->all(),
        ]);
    }

    public function store(SubjectRequest $request): JsonResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();
        $subject = $this->subjects->create($this->tenant->school(), $this->actor($request), $data['name']);

        return response()->json(['data' => self::present($subject, 0)], 201);
    }

    public function update(SubjectRequest $request, School $school, string $subject): JsonResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();
        $updated = $this->subjects->rename($this->tenant->school(), $this->actor($request), $subject, $data['name']);

        return response()->json(['data' => self::present($updated, TeachingSlot::query()->where('subject_id', $updated->id)->count())]);
    }

    public function destroy(Request $request, School $school, string $subject): Response
    {
        $this->subjects->delete($this->tenant->school(), $this->actor($request), $subject);

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
    public static function present(Subject $subject, int $slotCount): array
    {
        return ['id' => $subject->id, 'name' => $subject->name, 'slot_count' => $slotCount];
    }
}
