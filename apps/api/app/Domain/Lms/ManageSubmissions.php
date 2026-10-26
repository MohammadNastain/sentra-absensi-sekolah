<?php

namespace App\Domain\Lms;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\LmsScope;
use App\Models\ClassEnrollment;
use App\Models\LmsAssignment;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pengumpulan dan penilaian (ADR 0010 §4). Waktu kirim dan `is_late` selalu dari jam server.
 *
 * Aturan kirim: tugas harus terbit; sebelum tenggat, atau sesudahnya bila `accept_late`; pengumpulan yang sudah dinilai
 * terkunci. Siswa yang diminta merevisi boleh mengirim ulang kapan pun selama tugas belum ditutup, dan `is_late` tidak
 * berubah oleh revisi (ia melekat pada pengiriman pertama yang terlambat, tidak dihitung ulang).
 */
final class ManageSubmissions
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly LmsScope $scope,
        private readonly ManageAssignments $assignments,
        private readonly LmsNotifier $notifier,
    ) {}

    /** Id siswa pemilik akun ini, atau 403 bila akun ini bukan siswa aktif. */
    private function requireStudentId(): string
    {
        return $this->scope->studentId() ?? throw ApiProblem::permissionDenied();
    }

    /** Pengumpulan milik siswa ini untuk tugas tersebut (null bila belum ada); 404 bila tugasnya tak terlihat. */
    public function mine(User $actor, string $assignmentId): ?LmsSubmission
    {
        $assignment = $this->assignments->find($actor, $assignmentId);

        return LmsSubmission::query()->where('assignment_id', $assignment->id)->where('student_id', $this->requireStudentId())->first();
    }

    public function submit(School $school, User $actor, string $assignmentId, string $body): LmsSubmission
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $assignmentId, $body) {
            $assignment = $this->assignments->find($actor, $assignmentId);
            $studentId = $this->requireStudentId();

            // Kunci baris tugas: menutup tugas dan mengirim tidak boleh saling menyalip.
            $assignment = LmsAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $existing = LmsSubmission::query()->where('assignment_id', $assignment->id)->where('student_id', $studentId)->lockForUpdate()->first();
            $late = $this->assertOpen($assignment, $existing);
            // Revisi atas permintaan guru tidak menambah keterlambatan; yang sudah terlambat tetap terlambat.
            if ($existing?->status === LmsSubmission::STATUS_RETURNED) {
                $late = false;
            }

            $submission = $existing ?? new LmsSubmission(['school_id' => $school->id, 'assignment_id' => $assignment->id, 'student_id' => $studentId, 'is_late' => false]);
            $submission->body = $body;
            $submission->status = LmsSubmission::STATUS_SUBMITTED;
            $submission->submitted_at = now();
            $submission->is_late = $submission->is_late || $late;
            // Revisi menghapus nilai lama; komentar guru tetap sampai dinilai lagi agar siswa masih dapat membacanya.
            $submission->score = null;
            $submission->graded_by_user_id = null;
            $submission->graded_at = null;
            $submission->save();

            return $submission;
        }));
    }

    /**
     * Pengumpulan yang boleh diubah siswa pemiliknya (untuk lampiran): 404 bila bukan miliknya, 409 bila terkunci.
     */
    public function findEditable(User $actor, string $submissionId): LmsSubmission
    {
        $submission = LmsSubmission::query()->where('student_id', $this->requireStudentId())->find($submissionId) ?? throw new NotFoundHttpException;
        $this->assertOpen($this->assignments->find($actor, $submission->assignment_id), $submission);

        return $submission;
    }

    /** Pengumpulan yang boleh dibaca: pemiliknya, atau penilai tugasnya (guru kelas-mapel / admin). 404 selain itu. */
    public function findReadable(User $actor, string $submissionId): LmsSubmission
    {
        $submission = LmsSubmission::query()->find($submissionId) ?? throw new NotFoundHttpException;
        $assignment = $this->assignments->find($actor, $submission->assignment_id);

        if ($this->assignments->canManage($assignment) || $submission->student_id === $this->scope->studentId()) {
            return $submission;
        }

        throw new NotFoundHttpException;
    }

    /** Pengumpulan untuk penilai; 404 bila bukan penilai kelas-mapelnya. */
    public function findForGrader(User $actor, string $submissionId): LmsSubmission
    {
        $submission = LmsSubmission::query()->find($submissionId) ?? throw new NotFoundHttpException;
        $assignment = $this->assignments->find($actor, $submission->assignment_id);
        if (! $this->assignments->canManage($assignment)) {
            throw new NotFoundHttpException;
        }

        return $submission;
    }

    /**
     * Seluruh siswa yang terdaftar di kelas tugas hari ini, masing-masing dengan pengumpulannya (null bila belum).
     *
     * @return list<array{student: Student, submission: ?LmsSubmission}>
     */
    public function rosterFor(User $actor, string $assignmentId): array
    {
        $assignment = $this->assignments->find($actor, $assignmentId);
        if (! $this->assignments->canManage($assignment)) {
            throw new NotFoundHttpException;
        }

        $today = now($this->scope->schoolTimezone())->toDateString();
        $studentIds = ClassEnrollment::query()->where('class_id', $assignment->class_id)
            ->where('start_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->pluck('student_id');
        $submissions = LmsSubmission::query()->where('assignment_id', $assignment->id)->get()->keyBy('student_id');

        // Siswa yang sudah mengumpulkan lalu pindah kelas tetap tampil agar pekerjaannya tidak hilang dari penilaian.
        $students = Student::query()->whereIn('id', $studentIds->merge($submissions->keys())->unique()->values())->orderBy('full_name')->orderBy('id')->get();

        return array_values($students->map(fn (Student $s) => ['student' => $s, 'submission' => $submissions->get($s->id)])->all());
    }

    public function grade(School $school, User $actor, string $submissionId, float|int|string $score, ?string $feedback): LmsSubmission
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $submissionId, $score, $feedback) {
            $submission = $this->lockedForGrader($actor, $submissionId);
            $assignment = LmsAssignment::query()->findOrFail($submission->assignment_id);

            if ($submission->status === LmsSubmission::STATUS_RETURNED) {
                throw ApiProblem::invalidStateTransition('Pengumpulan ini sedang menunggu revisi siswa; nilai setelah dikirim ulang.');
            }
            if ((float) $score > (float) $assignment->max_score) {
                throw ValidationException::withMessages(['score' => ['Nilai tidak boleh melebihi '.rtrim(rtrim((string) $assignment->max_score, '0'), '.').'.']]);
            }

            $before = self::audited($submission);
            $submission->status = LmsSubmission::STATUS_GRADED;
            $submission->score = number_format((float) $score, 2, '.', '');
            $submission->feedback = $feedback !== null && trim($feedback) !== '' ? $feedback : null;
            $submission->graded_by_user_id = $actor->id;
            $submission->graded_at = now();
            $submission->save();
            $this->audit->record('school.lms_submission.graded', $school->id, 'lms_submission', $submission->id, $before, self::audited($submission), null, $actor);
            $this->notifier->submissionGraded($school, $assignment, $submission);

            return $submission;
        }));
    }

    public function returnForRevision(School $school, User $actor, string $submissionId, string $feedback): LmsSubmission
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $submissionId, $feedback) {
            $submission = $this->lockedForGrader($actor, $submissionId);
            $assignment = LmsAssignment::query()->findOrFail($submission->assignment_id);

            if ($assignment->status === LmsAssignment::STATUS_CLOSED) {
                throw ApiProblem::invalidStateTransition('Tugas sudah ditutup; buka kembali sebelum meminta revisi.');
            }
            if ($submission->status === LmsSubmission::STATUS_RETURNED) {
                throw ApiProblem::invalidStateTransition('Pengumpulan ini sudah diminta revisi.');
            }

            $before = self::audited($submission);
            $submission->status = LmsSubmission::STATUS_RETURNED;
            $submission->feedback = $feedback;
            $submission->score = null;
            $submission->graded_by_user_id = $actor->id;
            $submission->graded_at = now();
            $submission->save();
            $this->audit->record('school.lms_submission.returned', $school->id, 'lms_submission', $submission->id, $before, self::audited($submission), null, $actor);
            $this->notifier->submissionReturned($school, $assignment, $submission);

            return $submission;
        }));
    }

    private function lockedForGrader(User $actor, string $submissionId): LmsSubmission
    {
        $found = $this->findForGrader($actor, $submissionId);

        return LmsSubmission::query()->lockForUpdate()->findOrFail($found->id);
    }

    /**
     * Memastikan pengumpulan boleh dibuat/diubah sekarang; mengembalikan apakah pengiriman ini terlambat.
     */
    private function assertOpen(LmsAssignment $assignment, ?LmsSubmission $existing): bool
    {
        if ($assignment->status !== LmsAssignment::STATUS_PUBLISHED) {
            throw ApiProblem::invalidStateTransition('Tugas ini tidak menerima pengumpulan.');
        }
        if ($existing?->status === LmsSubmission::STATUS_GRADED) {
            throw ApiProblem::invalidStateTransition('Pengumpulan ini sudah dinilai dan tidak dapat diubah.');
        }

        $late = $assignment->due_at !== null && now()->greaterThan($assignment->due_at);
        // Revisi atas permintaan guru tidak dibatasi tenggat.
        if ($late && ! $assignment->accept_late && $existing?->status !== LmsSubmission::STATUS_RETURNED) {
            throw ApiProblem::invalidStateTransition('Batas waktu pengumpulan sudah lewat.');
        }

        return $late;
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(LmsSubmission $submission): array
    {
        // Nilai dan komentar sengaja tidak masuk audit (ADR 0010 §7); yang tercatat siapa, kapan, dan perubahan status.
        return ['assignment_id' => $submission->assignment_id, 'student_id' => $submission->student_id, 'status' => $submission->status, 'is_late' => $submission->is_late, 'graded_by_user_id' => $submission->graded_by_user_id];
    }
}
