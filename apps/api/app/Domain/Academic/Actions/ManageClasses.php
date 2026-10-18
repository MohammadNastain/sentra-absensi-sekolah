<?php

namespace App\Domain\Academic\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Kelas (rombel). Tahun ajaran sebuah kelas tidak bisa diganti setelah dibuat: penempatan siswa dan riwayat
 * absensi menempel padanya. Kelas yang tidak terpakai lagi diarsipkan, bukan dihapus.
 */
final class ManageClasses
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{academic_year_id: string, name: string, grade_level: int, homeroom_teacher_id?: string|null}  $data
     */
    public function create(School $school, User $actor, array $data): SchoolClass
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $class = SchoolClass::query()->create([
                'school_id' => $school->id,
                'academic_year_id' => $data['academic_year_id'],
                'name' => $data['name'],
                'grade_level' => $data['grade_level'],
                'homeroom_teacher_id' => $data['homeroom_teacher_id'] ?? null,
                'status' => SchoolClass::STATUS_ACTIVE,
            ]);
            $class->refresh();

            $this->audit->record('school.class.created', $school->id, 'school_class', $class->id, null, $this->snapshot($class), null, $actor);

            return $class;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  subset dari name, grade_level, homeroom_teacher_id, status
     */
    public function update(School $school, User $actor, string $classId, array $changes): SchoolClass
    {
        return $this->run($school, function () use ($school, $actor, $classId, $changes) {
            $class = SchoolClass::query()->where('school_id', $school->id)->lockForUpdate()->find($classId)
                ?? throw new NotFoundHttpException;
            $before = $this->snapshot($class);

            $class->fill($changes)->save();
            $class->refresh();

            $this->audit->record('school.class.updated', $school->id, 'school_class', $class->id, $before, $this->snapshot($class), null, $actor);

            return $class;
        });
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
    private function snapshot(SchoolClass $class): array
    {
        return [
            'name' => $class->name,
            'grade_level' => $class->grade_level,
            'academic_year_id' => $class->academic_year_id,
            'homeroom_teacher_id' => $class->homeroom_teacher_id,
            'status' => $class->status,
        ];
    }
}
