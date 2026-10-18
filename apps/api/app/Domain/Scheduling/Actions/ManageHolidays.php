<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\Holiday;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hari libur sekolah. Berbeda dari data inti lain, hari libur boleh dihapus (salah input); penghapusan diaudit
 * lengkap dengan isinya.
 */
final class ManageHolidays
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, start_date: string, end_date: string}  $data
     */
    public function create(School $school, User $actor, array $data): Holiday
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $holiday = Holiday::query()->create([
                'school_id' => $school->id,
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'created_by_user_id' => $actor->id,
            ]);
            $holiday->refresh();

            $this->audit->record('school.holiday.created', $school->id, 'holiday', $holiday->id, null, $this->snapshot($holiday), null, $actor);

            return $holiday;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  subset dari name, start_date, end_date
     */
    public function update(School $school, User $actor, string $holidayId, array $changes): Holiday
    {
        return $this->run($school, function () use ($school, $actor, $holidayId, $changes) {
            $holiday = $this->lock($school, $holidayId);
            $before = $this->snapshot($holiday);

            $holiday->fill($changes)->save();
            $holiday->refresh();

            $this->audit->record('school.holiday.updated', $school->id, 'holiday', $holiday->id, $before, $this->snapshot($holiday), null, $actor);

            return $holiday;
        });
    }

    public function delete(School $school, User $actor, string $holidayId): void
    {
        $this->run($school, function () use ($school, $actor, $holidayId) {
            $holiday = $this->lock($school, $holidayId);
            $before = $this->snapshot($holiday);
            $holiday->delete();

            $this->audit->record('school.holiday.deleted', $school->id, 'holiday', $holidayId, $before, null, null, $actor);
        });
    }

    private function lock(School $school, string $id): Holiday
    {
        return Holiday::query()->where('school_id', $school->id)->lockForUpdate()->find($id) ?? throw new NotFoundHttpException;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function run(School $school, callable $work): mixed
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(fn () => $work()));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Holiday $holiday): array
    {
        return [
            'name' => $holiday->name,
            'start_date' => $holiday->start_date->toDateString(),
            'end_date' => $holiday->end_date->toDateString(),
        ];
    }
}
