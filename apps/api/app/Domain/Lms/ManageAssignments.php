<?php

namespace App\Domain\Lms;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\LmsScope;
use App\Models\LmsAssignment;
use App\Models\LmsAttachment;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tugas LMS per kelas-mapel (ADR 0010 §3–§4). Hak lihat/kelola dari `LmsScope`; siswa melihat tugas terbit dan tertutup
 * (tertutup tetap terbaca agar nilai dan komentarnya bisa dilihat), draf hanya penulis dan admin.
 *
 * Alur status: draft → published ⇄ closed. Kembali ke draft hanya selama belum ada pengumpulan.
 */
final class ManageAssignments
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly LmsScope $scope,
        private readonly AttachmentStorage $files,
        private readonly LmsNotifier $notifier,
    ) {}

    /** @return Builder<LmsAssignment> */
    public function visible(User $viewer): Builder
    {
        return $this->scope->restrict(LmsAssignment::query(), $viewer->id, [LmsAssignment::STATUS_PUBLISHED, LmsAssignment::STATUS_CLOSED]);
    }

    public function find(User $viewer, string $id): LmsAssignment
    {
        return $this->visible($viewer)->find($id) ?? throw new NotFoundHttpException;
    }

    public function canManage(LmsAssignment $assignment): bool
    {
        return $this->scope->canManage($assignment->class_id, $assignment->subject_id);
    }

    public function assertCanManage(LmsAssignment $assignment): void
    {
        if (! $this->canManage($assignment)) {
            throw ApiProblem::permissionDenied();
        }
    }

    /**
     * @param  array{title: string, instructions: string, due_at: ?string, max_score: float|int|string, accept_late: bool}  $fields
     */
    public function create(School $school, User $actor, string $classId, string $subjectId, array $fields): LmsAssignment
    {
        if (! $this->scope->canManage($classId, $subjectId)) {
            throw ApiProblem::permissionDenied();
        }

        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $classId, $subjectId, $fields) {
            $errors = [];
            if (! SchoolClass::query()->whereKey($classId)->exists()) {
                $errors['class_id'] = ['Kelas tidak ditemukan.'];
            }
            if (! Subject::query()->whereKey($subjectId)->exists()) {
                $errors['subject_id'] = ['Mata pelajaran tidak ditemukan.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $assignment = LmsAssignment::query()->create([
                'school_id' => $school->id, 'class_id' => $classId, 'subject_id' => $subjectId, 'author_user_id' => $actor->id,
                'title' => trim(preg_replace('/\s+/u', ' ', $fields['title']) ?? $fields['title']),
                'instructions' => $fields['instructions'], 'due_at' => $fields['due_at'], 'max_score' => $fields['max_score'],
                'accept_late' => $fields['accept_late'], 'status' => LmsAssignment::STATUS_DRAFT,
            ]);
            $this->audit->record('school.lms_assignment.created', $school->id, 'lms_assignment', $assignment->id, null, self::audited($assignment), null, $actor);

            return $assignment;
        }));
    }

    /**
     * @param  array<string, mixed>  $changes  title, instructions, due_at, max_score, accept_late, status
     */
    public function update(School $school, User $actor, string $id, array $changes): LmsAssignment
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $changes) {
            $found = $this->find($actor, $id);
            $this->assertCanManage($found);
            $assignment = LmsAssignment::query()->lockForUpdate()->findOrFail($found->id);
            $before = self::audited($assignment);

            foreach (['instructions', 'due_at', 'accept_late'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $assignment->{$field} = $changes[$field];
                }
            }
            if (isset($changes['title'])) {
                $assignment->title = trim(preg_replace('/\s+/u', ' ', (string) $changes['title']) ?? (string) $changes['title']);
            }
            if (isset($changes['max_score'])) {
                $assignment->max_score = $changes['max_score'];
                $this->assertScoresWithinMax($assignment);
            }
            if (isset($changes['status']) && $changes['status'] !== $assignment->status) {
                $this->transition($assignment, (string) $changes['status']);
            }
            $assignment->save();

            if ($before['status'] !== $assignment->status) {
                $this->audit->record('school.lms_assignment.'.$assignment->status, $school->id, 'lms_assignment', $assignment->id, $before, self::audited($assignment), null, $actor);
            }
            // Hanya terbit pertama dari draf yang memberi tahu siswa; membuka kembali tugas yang ditutup tidak mengulang.
            if ($before['status'] === LmsAssignment::STATUS_DRAFT && $assignment->status === LmsAssignment::STATUS_PUBLISHED) {
                $this->notifier->assignmentPublished($school, $assignment);
            }

            return $assignment;
        }));
    }

    public function delete(School $school, User $actor, string $id): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id) {
            $assignment = $this->find($actor, $id);
            $this->assertCanManage($assignment);

            if (LmsSubmission::query()->where('assignment_id', $assignment->id)->exists()) {
                throw ApiProblem::invalidStateTransition('Tugas ini sudah memiliki pengumpulan. Tutup tugasnya, jangan dihapus.');
            }

            $keys = array_values(array_map('strval', LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_ASSIGNMENT)->where('owner_id', $assignment->id)->pluck('storage_key')->all()));
            $assignment->delete();
            $this->audit->record('school.lms_assignment.deleted', $school->id, 'lms_assignment', $assignment->id, self::audited($assignment), null, null, $actor);
            DB::afterCommit(fn () => $this->files->forget($keys));
        }));
    }

    private function transition(LmsAssignment $assignment, string $to): void
    {
        $from = $assignment->status;

        if ($to === LmsAssignment::STATUS_DRAFT) {
            if (LmsSubmission::query()->where('assignment_id', $assignment->id)->exists()) {
                throw ApiProblem::invalidStateTransition('Tugas yang sudah dikumpulkan tidak dapat dikembalikan menjadi draf. Tutup saja.');
            }
            $assignment->published_at = null;
        } elseif ($from === LmsAssignment::STATUS_DRAFT) {
            $assignment->published_at = now();
        }
        $assignment->status = $to;
    }

    /** Menurunkan nilai maksimum tidak boleh membuat nilai yang sudah diberikan melampauinya. */
    private function assertScoresWithinMax(LmsAssignment $assignment): void
    {
        if (LmsSubmission::query()->where('assignment_id', $assignment->id)->where('score', '>', $assignment->max_score)->exists()) {
            throw ValidationException::withMessages(['max_score' => ['Sudah ada nilai yang lebih besar dari batas ini.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(LmsAssignment $assignment): array
    {
        return [
            'class_id' => $assignment->class_id, 'subject_id' => $assignment->subject_id, 'title' => $assignment->title,
            'status' => $assignment->status, 'due_at' => $assignment->due_at?->toIso8601String(), 'max_score' => $assignment->max_score,
        ];
    }
}
