<?php

namespace App\Http\Controllers\School;

use App\Domain\Lms\ManageSubmissions;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\LmsAttachment;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pengumpulan dan penilaian (ADR 0010). Siswa hanya melihat nilai setelah dinilai dan komentar setelah dinilai atau
 * dikembalikan; penilai (guru kelas-mapel/admin) melihat semuanya. Nilai dan komentar tidak masuk audit.
 */
final class LmsSubmissionController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageSubmissions $submissions) {}

    /** Pengumpulan milik siswa yang login untuk satu tugas. */
    public function showMine(Request $request, School $school, string $assignment): JsonResponse
    {
        $submission = $this->submissions->mine($this->actor($request), $assignment) ?? throw new NotFoundHttpException;

        return response()->json(['data' => $this->present($submission, false)]);
    }

    public function submit(Request $request, School $school, string $assignment): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:20000']]);
        $submission = $this->submissions->submit($this->tenant->school(), $this->actor($request), $assignment, (string) ($data['body'] ?? ''));

        return response()->json(['data' => $this->present($submission, false)]);
    }

    /** Daftar siswa kelas tugas beserta pengumpulannya, untuk penilai. */
    public function index(Request $request, School $school, string $assignment): JsonResponse
    {
        $rows = $this->submissions->rosterFor($this->actor($request), $assignment);

        return response()->json(['data' => array_map(fn (array $row) => [
            'student' => $this->student($row['student']),
            'submission' => $row['submission'] === null ? null : $this->present($row['submission'], true, false),
        ], $rows)]);
    }

    public function show(Request $request, School $school, string $submission): JsonResponse
    {
        $found = $this->submissions->findForGrader($this->actor($request), $submission);

        return response()->json(['data' => $this->present($found, true) + ['student' => $this->student(Student::query()->findOrFail($found->student_id))]]);
    }

    public function grade(Request $request, School $school, string $submission): JsonResponse
    {
        $data = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:10000'], 'feedback' => ['nullable', 'string', 'max:5000']]);
        $graded = $this->submissions->grade($this->tenant->school(), $this->actor($request), $submission, $data['score'], $data['feedback'] ?? null);

        return response()->json(['data' => $this->present($graded, true)]);
    }

    public function returnForRevision(Request $request, School $school, string $submission): JsonResponse
    {
        $data = $request->validate(['feedback' => ['required', 'string', 'max:5000', 'regex:/\S/u']]);
        $returned = $this->submissions->returnForRevision($this->tenant->school(), $this->actor($request), $submission, $data['feedback']);

        return response()->json(['data' => $this->present($returned, true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LmsSubmission $s, bool $asGrader, bool $withAttachments = true): array
    {
        $data = [
            'id' => $s->id,
            'assignment_id' => $s->assignment_id,
            'status' => $s->status,
            'body' => $s->body,
            'submitted_at' => $s->submitted_at->toIso8601String(),
            'is_late' => $s->is_late,
            // Siswa: nilai hanya setelah dinilai; komentar setelah dinilai atau dikembalikan.
            'score' => $asGrader || $s->status === LmsSubmission::STATUS_GRADED ? ($s->score === null ? null : (float) $s->score) : null,
            'feedback' => $asGrader || $s->status !== LmsSubmission::STATUS_SUBMITTED ? $s->feedback : null,
            'graded_at' => $asGrader || $s->status !== LmsSubmission::STATUS_SUBMITTED ? $s->graded_at?->toIso8601String() : null,
        ];

        if ($withAttachments) {
            $data['attachments'] = LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_SUBMISSION)->where('owner_id', $s->id)
                ->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (LmsAttachment $x) => ['id' => $x->id, 'name' => $x->original_name, 'mime' => $x->mime, 'size' => $x->size])->all();
        }

        return $data;
    }

    /**
     * @return array{id: string, full_name: string, student_number: string}
     */
    private function student(Student $student): array
    {
        return ['id' => $student->id, 'full_name' => $student->full_name, 'student_number' => $student->student_number];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
