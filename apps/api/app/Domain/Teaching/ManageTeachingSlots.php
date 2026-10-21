<?php

namespace App\Domain\Teaching;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\TeachingSlot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Jam mengajar. Bentrok (guru atau kelas dobel pada jam beririsan) dijaga exclusion constraint database dan
 * diterjemahkan menjadi 409 SCHEDULE_CONFLICT oleh ConstraintViolations.
 */
final class ManageTeachingSlots
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{class_id: string, subject_id: string, teacher_id: string, day_of_week: int, start_time: string, end_time: string}  $data
     */
    public function create(School $school, User $actor, array $data): TeachingSlot
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $slot = TeachingSlot::query()->create([...$data, 'school_id' => $school->id, 'created_by_user_id' => $actor->id]);
            $slot->refresh();
            $this->audit->record('school.teaching_slot.created', $school->id, 'teaching_slot', $slot->id, null, $this->snapshot($slot), null, $actor);

            return $slot;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(School $school, User $actor, string $slotId, array $changes): TeachingSlot
    {
        return $this->run($school, function () use ($school, $actor, $slotId, $changes) {
            $slot = TeachingSlot::query()->where('school_id', $school->id)->lockForUpdate()->find($slotId) ?? throw new NotFoundHttpException;
            $before = $this->snapshot($slot);

            $slot->fill($changes);
            if (substr((string) $slot->end_time, 0, 5) <= substr((string) $slot->start_time, 0, 5)) {
                throw ValidationException::withMessages(['end_time' => 'Jam selesai harus setelah jam mulai.']);
            }
            $slot->save();
            $slot->refresh();

            $this->audit->record('school.teaching_slot.updated', $school->id, 'teaching_slot', $slot->id, $before, $this->snapshot($slot), null, $actor);

            return $slot;
        });
    }

    public function delete(School $school, User $actor, string $slotId): void
    {
        $this->run($school, function () use ($school, $actor, $slotId) {
            $slot = TeachingSlot::query()->where('school_id', $school->id)->lockForUpdate()->find($slotId) ?? throw new NotFoundHttpException;
            $slot->delete();
            $this->audit->record('school.teaching_slot.deleted', $school->id, 'teaching_slot', $slot->id, $this->snapshot($slot), null, null, $actor);
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
    private function snapshot(TeachingSlot $slot): array
    {
        return [
            'class_id' => $slot->class_id,
            'subject_id' => $slot->subject_id,
            'teacher_id' => $slot->teacher_id,
            'day_of_week' => $slot->day_of_week,
            'start_time' => substr((string) $slot->start_time, 0, 5),
            'end_time' => substr((string) $slot->end_time, 0, 5),
        ];
    }
}
