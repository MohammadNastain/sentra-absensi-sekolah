<?php

namespace App\Http\Controllers\School;

use App\Domain\Lms\ManageMaterials;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\LmsAttachment;
use App\Models\LmsMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Materi LMS (ADR 0010). Isi teks polos; draf hanya terlihat penulis dan admin. */
final class LmsMaterialController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ManageMaterials $materials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');

        $page = $this->materials->visible($user)
            ->when(is_string($classId) && Str::isUuid($classId), fn ($q) => $q->where('class_id', $classId))
            ->when(is_string($subjectId) && Str::isUuid($subjectId), fn ($q) => $q->where('subject_id', $subjectId))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25);

        /** @var list<LmsMaterial> $items */
        $items = $page->items();
        $counts = LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_MATERIAL)->whereIn('owner_id', array_map(fn (LmsMaterial $m) => $m->id, $items))
            ->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');

        return response()->json([
            'data' => array_map(fn (LmsMaterial $m) => $this->present($m, null, (int) ($counts[$m->id] ?? 0), false), $items),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);
        $material = $this->materials->create($this->tenant->school(), $this->actor($request), $data['class_id'], $data['subject_id'], $data['title'], (string) ($data['body'] ?? ''));

        return response()->json(['data' => $this->present($material, [], 0, true)], 201);
    }

    public function show(Request $request, School $school, string $material): JsonResponse
    {
        $found = $this->materials->find($this->actor($request), $material);
        $attachments = $this->attachmentsOf($found);

        return response()->json(['data' => $this->present($found, $attachments, count($attachments), true)]);
    }

    public function update(Request $request, School $school, string $material): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200', 'regex:/\S/u'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::in([LmsMaterial::STATUS_DRAFT, LmsMaterial::STATUS_PUBLISHED])],
        ]);
        if (array_key_exists('body', $data)) {
            $data['body'] = (string) $data['body'];
        }
        $updated = $this->materials->update($this->tenant->school(), $this->actor($request), $material, $data);
        $attachments = $this->attachmentsOf($updated);

        return response()->json(['data' => $this->present($updated, $attachments, count($attachments), true)]);
    }

    public function destroy(Request $request, School $school, string $material): Response
    {
        $this->materials->delete($this->tenant->school(), $this->actor($request), $material);

        return response()->noContent();
    }

    /**
     * @return list<LmsAttachment>
     */
    private function attachmentsOf(LmsMaterial $material): array
    {
        return array_values(LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_MATERIAL)->where('owner_id', $material->id)->orderBy('created_at')->orderBy('id')->get()->all());
    }

    /**
     * @param  list<LmsAttachment>|null  $attachments  null = daftar tanpa rincian lampiran
     * @return array<string, mixed>
     */
    private function present(LmsMaterial $m, ?array $attachments, int $attachmentCount, bool $withBody): array
    {
        $data = [
            'id' => $m->id,
            'class' => ['id' => $m->class_id, 'name' => SchoolClass::query()->whereKey($m->class_id)->value('name')],
            'subject' => ['id' => $m->subject_id, 'name' => Subject::query()->whereKey($m->subject_id)->value('name')],
            'title' => $m->title,
            'status' => $m->status,
            'published_at' => $m->published_at?->toIso8601String(),
            'created_at' => $m->created_at->toIso8601String(),
            'author_name' => User::query()->whereKey($m->author_user_id)->value('name'),
            'attachment_count' => $attachmentCount,
            'can_manage' => $this->materials->canManage($m),
        ];

        if ($withBody) {
            $data['body'] = $m->body;
            $data['attachments'] = array_map(fn (LmsAttachment $a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
            ], $attachments ?? []);
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
