<?php

namespace App\Domain\Academic\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Siswa dan penempatan kelasnya. Pindah kelas menutup penempatan lama sehari sebelum tanggal mulai yang baru,
 * sehingga riwayat tidak terputus maupun tumpang tindih. Siswa yang keluar (nonaktif, lulus, pindah sekolah)
 * otomatis ditutup penempatannya; datanya tetap ada untuk riwayat absensi.
 */
final class ManageStudents
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{student_number: string, nisn?: string|null, full_name: string, date_of_birth?: string|null}  $data
     */
    public function create(School $school, User $actor, array $data): Student
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $student = Student::query()->create([
                'school_id' => $school->id,
                'student_number' => $data['student_number'],
                'nisn' => $data['nisn'] ?? null,
                'full_name' => $data['full_name'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'status' => Student::STATUS_ACTIVE,
            ]);
            $student->refresh();

            $this->audit->record('school.student.created', $school->id, 'student', $student->id, null, $this->snapshot($student), null, $actor);

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  subset dari student_number, nisn, full_name, date_of_birth, status
     */
    public function update(School $school, User $actor, string $studentId, array $changes): Student
    {
        return $this->run($school, function () use ($school, $actor, $studentId, $changes) {
            $student = $this->lock($school, $studentId);
            $before = $this->snapshot($student);
            $leaving = isset($changes['status'])
                && $changes['status'] !== Student::STATUS_ACTIVE
                && $student->status === Student::STATUS_ACTIVE;

            if (isset($changes['status']) && $changes['status'] !== $student->status) {
                $changes['status_changed_at'] = now();
            }
            $student->fill($changes)->save();
            $student->refresh();

            $this->audit->record('school.student.updated', $school->id, 'student', $student->id, $before, $this->snapshot($student), null, $actor);

            if ($leaving) {
                $this->closeEnrollments($school, $actor, $student);
            }

            return $student;
        });
    }

    /**
     * Menempatkan siswa di sebuah kelas mulai tanggal tertentu; bila sudah punya penempatan berjalan,
     * penempatan itu ditutup (pindah kelas). Kelas dan rentang tanggal sudah divalidasi oleh request.
     */
    public function enroll(School $school, User $actor, string $studentId, string $classId, string $startDate): ClassEnrollment
    {
        return $this->run($school, function () use ($school, $actor, $studentId, $classId, $startDate) {
            $student = $this->lock($school, $studentId);
            if ($student->status !== Student::STATUS_ACTIVE) {
                throw ApiProblem::invalidStateTransition('Siswa yang tidak aktif tidak dapat ditempatkan di kelas. Aktifkan siswa terlebih dahulu.');
            }

            // Penempatan yang masih berlaku pada atau setelah tanggal mulai baru.
            $open = ClassEnrollment::query()
                ->where('school_id', $school->id)
                ->where('student_id', $student->id)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate))
                ->orderBy('start_date')
                ->lockForUpdate()
                ->get();

            foreach ($open as $existing) {
                if ($existing->start_date->toDateString() >= $startDate) {
                    throw ApiProblem::enrollmentConflict('Tanggal mulai harus setelah tanggal mulai penempatan sebelumnya ('.$existing->start_date->toDateString().').');
                }
                if ($existing->class_id === $classId && $existing->end_date === null) {
                    throw ApiProblem::enrollmentConflict('Siswa sudah berada di kelas ini.');
                }
            }

            foreach ($open as $existing) {
                $before = $this->enrollmentSnapshot($existing);
                $existing->end_date = Carbon::parse($startDate)->subDay();
                $existing->save();
                $this->audit->record('school.enrollment.ended', $school->id, 'class_enrollment', $existing->id, $before, $this->enrollmentSnapshot($existing), null, $actor);
            }

            $enrollment = ClassEnrollment::query()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'class_id' => $classId,
                'start_date' => $startDate,
                'created_by_user_id' => $actor->id,
            ]);
            $enrollment->refresh();

            $this->audit->record('school.enrollment.created', $school->id, 'class_enrollment', $enrollment->id, null, $this->enrollmentSnapshot($enrollment), null, $actor);

            return $enrollment;
        });
    }

    private function closeEnrollments(School $school, User $actor, Student $student): void
    {
        $today = Carbon::now($school->timezone)->toDateString();

        $open = ClassEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $today))
            ->lockForUpdate()
            ->get();

        foreach ($open as $enrollment) {
            $before = $this->enrollmentSnapshot($enrollment);
            // Penempatan yang belum dimulai ditutup di hari mulainya agar tetap sah (end >= start).
            $enrollment->end_date = $enrollment->start_date->toDateString() > $today ? $enrollment->start_date : Carbon::parse($today);
            $enrollment->save();
            $this->audit->record('school.enrollment.ended', $school->id, 'class_enrollment', $enrollment->id, $before, $this->enrollmentSnapshot($enrollment), null, $actor);
        }
    }

    private function lock(School $school, string $studentId): Student
    {
        return Student::query()->where('school_id', $school->id)->lockForUpdate()->find($studentId)
            ?? throw new NotFoundHttpException;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function run(School $school, callable $work): mixed
    {
        try {
            return $this->database->runInSchool($school->id, fn () => DB::transaction(fn () => $work()));
        } catch (QueryException $e) {
            throw ConstraintViolations::translate($e) ?? $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Student $student): array
    {
        return [
            'student_number' => $student->student_number,
            'nisn' => $student->nisn,
            'full_name' => $student->full_name,
            'date_of_birth' => $student->date_of_birth?->toDateString(),
            'status' => $student->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentSnapshot(ClassEnrollment $enrollment): array
    {
        return [
            'student_id' => $enrollment->student_id,
            'class_id' => $enrollment->class_id,
            'start_date' => $enrollment->start_date->toDateString(),
            'end_date' => $enrollment->end_date?->toDateString(),
        ];
    }
}
