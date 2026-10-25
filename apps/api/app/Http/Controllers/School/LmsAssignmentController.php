<?php

namespace App\Http\Controllers\School;

use App\Domain\Lms\ManageAssignments;
use App\Domain\Tenancy\LmsScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\LmsAssignment;
use App\Models\LmsAttachment;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Tugas LMS (ADR 0010). Instruksi teks polos; draf hanya terlihat penulis dan admin. */
final class LmsAssignmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ManageAssignments $assignments,
        private readonly LmsScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');

        $page = $this->assignments->visible($this->actor($request))
            ->when(is_string($classId) && Str::isUuid($classId), fn ($q) => $q->where('class_id', $classId))
            ->when(is_string($subjectId) && Str::isUuid($subjectId), fn ($q) => $q->where('subject_id', $subjectId))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25);

        /** @var list<LmsAssignment> $items */
        $items = array_values($page->items());
        $ids = array_map(fn (LmsAssignment $a) => $a->id, $items);
        $counts = LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_ASSIGNMENT)->whereIn('owner_id', $ids)
            ->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');
        $mine = $this->myStatuses($ids);

        return response()->json([
            'data' => array_map(fn (LmsAssignment $a) => $this->present($a, null, (int) ($counts[$a->id] ?? 0), $mine[$a->id] ?? null), $items),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_at' => ['nullable', 'date'],
            'max_score' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'accept_late' => ['nullable', 'boolean'],
        ]);
        $assignment = $this->assignments->create($this->tenant->school(), $this->actor($request), $data['class_id'], $data['subject_id'], [
            'title' => $data['title'],
            'instructions' => (string) ($data['instructions'] ?? ''),
            'due_at' => $data['due_at'] ?? null,
            'max_score' => $data['max_score'] ?? 100,
            'accept_late' => (bool) ($data['accept_late'] ?? false),
        ]);

        return response()->json(['data' => $this->present($assignment, [], 0, null)], 201);
    }

    public function show(Request $request, School $school, string $assignment): JsonResponse
    {
        $found = $this->assignments->find($this->actor($request), $assignment);

        return response()->json(['data' => $this->detail($found)]);
    }

    public function update(Request $request, School $school, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200', 'regex:/\S/u'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'max_score' => ['sometimes', 'numeric', 'min:0.01', 'max:10000'],
            'accept_late' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([LmsAssignment::STATUS_DRAFT, LmsAssignment::STATUS_PUBLISHED, LmsAssignment::STATUS_CLOSED])],
        ]);
        if (array_key_exists('instructions', $data)) {
            $data['instructions'] = (string) $data['instructions'];
        }
        $updated = $this->assignments->update($this->tenant->school(), $this->actor($request), $assignment, $data);

        return response()->json(['data' => $this->detail($updated)]);
    }

    public function destroy(Request $request, School $school, string $assignment): Response
    {
        $this->assignments->delete($this->tenant->school(), $this->actor($request), $assignment);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(LmsAssignment $assignment): array
    {
        $attachments = array_values(LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_ASSIGNMENT)->where('owner_id', $assignment->id)->orderBy('created_at')->orderBy('id')->get()->all());

        return $this->present($assignment, $attachments, count($attachments), $this->myStatuses([$assignment->id])[$assignment->id] ?? null);
    }

    /**
     * Status pengumpulan milik siswa ini per tugas; kosong bila bukan siswa.
     *
     * @param  list<string>  $assignmentIds
     * @return array<string, array{status: string, is_late: bool}>
     */
    private function myStatuses(array $assignmentIds): array
    {
        $studentId = $this->scope->studentId();
        if ($studentId === null || $assignmentIds === []) {
            return [];
        }

        $out = [];
        foreach (LmsSubmission::query()->where('student_id', $studentId)->whereIn('assignment_id', $assignmentIds)->get() as $s) {
            $out[$s->assignment_id] = ['status' => $s->status, 'is_late' => $s->is_late];
        }

        return $out;
    }

    /**
     * @param  list<LmsAttachment>|null  $attachments  null = daftar tanpa rincian
     * @param  array{status: string, is_late: bool}|null  $mine
     * @return array<string, mixed>
     */
    private function present(LmsAssignment $a, ?array $attachments, int $attachmentCount, ?array $mine): array
    {
        $data = [
            'id' => $a->id,
            'class' => ['id' => $a->class_id, 'name' => SchoolClass::query()->whereKey($a->class_id)->value('name')],
            'subject' => ['id' => $a->subject_id, 'name' => Subject::query()->whereKey($a->subject_id)->value('name')],
            'title' => $a->title,
            'status' => $a->status,
            'due_at' => $a->due_at?->toIso8601String(),
            'max_score' => (float) $a->max_score,
            'accept_late' => $a->accept_late,
            'published_at' => $a->published_at?->toIso8601String(),
            'created_at' => $a->created_at->toIso8601String(),
            'author_name' => User::query()->whereKey($a->author_user_id)->value('name'),
            'attachment_count' => $attachmentCount,
            'can_manage' => $this->assignments->canManage($a),
            'my_submission' => $mine,
        ];

        if ($attachments !== null) {
            $data['instructions'] = $a->instructions;
            $data['attachments'] = array_map(fn (LmsAttachment $x) => ['id' => $x->id, 'name' => $x->original_name, 'mime' => $x->mime, 'size' => $x->size], $attachments);
        }

        return $data;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
