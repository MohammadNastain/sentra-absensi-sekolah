<?php

namespace App\Domain\Teaching;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeachingSlot;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mata pelajaran. Mapel yang masih dipakai di jadwal mengajar tidak boleh dihapus.
 */
final class ManageSubjects
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    public function create(School $school, User $actor, string $name): Subject
    {
        return $this->run($school, function () use ($school, $actor, $name) {
            $subject = Subject::query()->create(['school_id' => $school->id, 'name' => self::clean($name)]);
            $this->audit->record('school.subject.created', $school->id, 'subject', $subject->id, null, ['name' => $subject->name], null, $actor);

            return $subject;
        });
    }

    public function rename(School $school, User $actor, string $subjectId, string $name): Subject
    {
        return $this->run($school, function () use ($school, $actor, $subjectId, $name) {
            $subject = Subject::query()->where('school_id', $school->id)->lockForUpdate()->find($subjectId) ?? throw new NotFoundHttpException;
            $before = ['name' => $subject->name];
            $subject->update(['name' => self::clean($name)]);
            $this->audit->record('school.subject.updated', $school->id, 'subject', $subject->id, $before, ['name' => $subject->name], null, $actor);

            return $subject;
        });
    }

    public function delete(School $school, User $actor, string $subjectId): void
    {
        $this->run($school, function () use ($school, $actor, $subjectId) {
            $subject = Subject::query()->where('school_id', $school->id)->lockForUpdate()->find($subjectId) ?? throw new NotFoundHttpException;
            if (TeachingSlot::query()->where('subject_id', $subject->id)->exists()) {
                throw ApiProblem::invalidStateTransition('Mapel ini masih dipakai di jadwal mengajar. Hapus atau ganti jadwalnya terlebih dahulu.');
            }
            $subject->delete();
            $this->audit->record('school.subject.deleted', $school->id, 'subject', $subject->id, ['name' => $subject->name], null, null, $actor);
        });
    }

    private static function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
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
}
