<?php

namespace App\Domain\Academic\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\TeachingSlot;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Guru. Guru yang masih menjadi wali kelas aktif tidak boleh dinonaktifkan: kelasnya akan kehilangan
 * wali tanpa disadari. Kelas harus dialihkan atau diarsipkan dulu.
 */
final class ManageTeachers
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{full_name: string, employee_number?: string|null, email?: string|null, membership_id?: string|null, subject_ids?: list<string>}  $data
     */
    public function create(School $school, User $actor, array $data): Teacher
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $teacher = Teacher::query()->create([
                'school_id' => $school->id,
                'full_name' => $data['full_name'],
                'employee_number' => $data['employee_number'] ?? null,
                'email' => $data['email'] ?? null,
                'membership_id' => $data['membership_id'] ?? null,
                'status' => Teacher::STATUS_ACTIVE,
            ]);
            $teacher->refresh();
            $this->syncSubjects($school, $teacher, $data['subject_ids'] ?? []);

            $this->audit->record('school.teacher.created', $school->id, 'teacher', $teacher->id, null, $this->snapshot($teacher), null, $actor);

            return $teacher;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  subset dari full_name, employee_number, email, subject_ids, membership_id, status
     */
    public function update(School $school, User $actor, string $teacherId, array $changes): Teacher
    {
        return $this->run($school, function () use ($school, $actor, $teacherId, $changes) {
            $teacher = Teacher::query()->where('school_id', $school->id)->lockForUpdate()->find($teacherId)
                ?? throw new NotFoundHttpException;
            $before = $this->snapshot($teacher);

            if (($changes['status'] ?? null) === Teacher::STATUS_INACTIVE && $teacher->status === Teacher::STATUS_ACTIVE) {
                $this->assertNotActiveHomeroom($school, $teacher);
                $this->assertNoTeachingSlots($teacher);
            }

            $subjectIds = $changes['subject_ids'] ?? null;
            unset($changes['subject_ids']);
            $teacher->fill($changes)->save();
            $teacher->refresh();
            if (is_array($subjectIds)) {
                $this->syncSubjects($school, $teacher, array_values(array_map(strval(...), $subjectIds)));
            }

            $this->audit->record('school.teacher.updated', $school->id, 'teacher', $teacher->id, $before, $this->snapshot($teacher), null, $actor);

            return $teacher;
        });
    }

    /**
     * @param  list<string>  $subjectIds
     */
    private function syncSubjects(School $school, Teacher $teacher, array $subjectIds): void
    {
        $teacher->subjects()->syncWithPivotValues($subjectIds, ['school_id' => $school->id]);
        $teacher->unsetRelation('subjects');
    }

    private function assertNoTeachingSlots(Teacher $teacher): void
    {
        if (TeachingSlot::query()->where('teacher_id', $teacher->id)->exists()) {
            throw ApiProblem::invalidStateTransition('Guru ini masih punya jadwal mengajar. Hapus atau alihkan jadwalnya terlebih dahulu.');
        }
    }

    private function assertNotActiveHomeroom(School $school, Teacher $teacher): void
    {
        $isHomeroom = SchoolClass::query()
            ->where('school_id', $school->id)
            ->where('homeroom_teacher_id', $teacher->id)
            ->where('status', SchoolClass::STATUS_ACTIVE)
            ->exists();

        if ($isHomeroom) {
            throw ApiProblem::invalidStateTransition('Guru ini masih menjadi wali kelas aktif. Ganti wali kelas atau arsipkan kelasnya terlebih dahulu.');
        }
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
    private function snapshot(Teacher $teacher): array
    {
        return [
            'full_name' => $teacher->full_name,
            'employee_number' => $teacher->employee_number,
            'subject_ids' => $teacher->subjects()->pluck('subjects.id')->sort()->values()->all(),
            'membership_id' => $teacher->membership_id,
            'status' => $teacher->status,
        ];
    }
}
