<?php

namespace App\Domain\Scheduling\Actions;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Jadwal berversi. Membuat jadwal = menerbitkan versi baru yang berlaku mulai tanggal tertentu untuk satu
 * scope dan beberapa hari: versi sebelumnya yang masih berlaku pada tanggal itu ditutup sehari sebelumnya.
 * Jam sebuah versi hanya boleh dikoreksi selama belum dipakai sesi absensi; sesudahnya yang bisa diubah hanya
 * penutupannya (effective_until) dan status aktif, dan perubahan jam harus berupa versi baru.
 */
final class ManageSchedules
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{scope_type: string, grade_level?: int|null, class_id?: string|null, days: list<int>, check_in_start: string, check_in_deadline: string, late_grace_minutes: int, check_out_start: string, scheduled_check_out: string, effective_from: string}  $data
     * @return Collection<int, Schedule> versi baru, satu per hari, urut hari
     */
    public function publish(School $school, User $actor, array $data): Collection
    {
        return $this->run($school, function () use ($school, $actor, $data) {
            $created = new Collection;
            $days = array_values(array_unique($data['days']));
            sort($days);

            foreach ($days as $day) {
                $scope = fn () => Schedule::query()
                    ->where('school_id', $school->id)
                    ->where('scope_type', $data['scope_type'])
                    ->where('grade_level', $data['grade_level'] ?? null)
                    ->where('class_id', $data['class_id'] ?? null)
                    ->where('day_of_week', $day)
                    ->where('is_active', true);

                $later = $scope()->where('effective_from', '>=', $data['effective_from'])->orderBy('effective_from')->first();
                if ($later !== null) {
                    throw ApiProblem::scheduleConflict(sprintf(
                        'Sudah ada jadwal untuk hari ke-%d yang berlaku mulai %s. Tanggal berlaku baru harus setelah versi terakhir.',
                        $day,
                        $later->effective_from->toDateString(),
                    ));
                }

                foreach ($scope()->where('effective_from', '<', $data['effective_from'])
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $data['effective_from']))
                    ->lockForUpdate()->get() as $previous) {
                    $before = $this->snapshot($previous);
                    $previous->effective_until = Carbon::parse($data['effective_from'])->subDay();
                    $previous->save();
                    $this->audit->record('school.schedule.updated', $school->id, 'schedule', $previous->id, $before, $this->snapshot($previous->refresh()), null, $actor);
                }

                $schedule = Schedule::query()->create([
                    'school_id' => $school->id,
                    'scope_type' => $data['scope_type'],
                    'grade_level' => $data['grade_level'] ?? null,
                    'class_id' => $data['class_id'] ?? null,
                    'day_of_week' => $day,
                    'check_in_start' => $data['check_in_start'],
                    'check_in_deadline' => $data['check_in_deadline'],
                    'late_grace_minutes' => $data['late_grace_minutes'],
                    'check_out_start' => $data['check_out_start'],
                    'scheduled_check_out' => $data['scheduled_check_out'],
                    'effective_from' => $data['effective_from'],
                    'is_active' => true,
                    'created_by_user_id' => $actor->id,
                ]);
                $schedule->refresh();
                $this->audit->record('school.schedule.created', $school->id, 'schedule', $schedule->id, null, $this->snapshot($schedule), null, $actor);
                $created->push($schedule);
            }

            return $created;
        });
    }

    /**
     * @param  array{effective_until?: string|null, is_active?: bool, check_in_start?: string, check_in_deadline?: string, late_grace_minutes?: int, check_out_start?: string, scheduled_check_out?: string}  $changes
     */
    public function update(School $school, User $actor, string $scheduleId, array $changes): Schedule
    {
        return $this->run($school, function () use ($school, $actor, $scheduleId, $changes) {
            $schedule = Schedule::query()->where('school_id', $school->id)->lockForUpdate()->find($scheduleId)
                ?? throw new NotFoundHttpException;
            $before = $this->snapshot($schedule);

            if (array_key_exists('effective_until', $changes) && $changes['effective_until'] !== null
                && $changes['effective_until'] < $schedule->effective_from->toDateString()) {
                throw ApiProblem::scheduleConflict('Tanggal berakhir tidak boleh sebelum tanggal mulai berlaku ('.$schedule->effective_from->toDateString().').');
            }

            $hours = ['check_in_start', 'check_in_deadline', 'late_grace_minutes', 'check_out_start', 'scheduled_check_out'];
            $hoursChanged = false;
            foreach ($hours as $field) {
                if (array_key_exists($field, $changes) && (string) $changes[$field] !== substr((string) $schedule->{$field}, 0, str_contains($field, 'minutes') ? 99 : 5)) {
                    $hoursChanged = true;
                }
            }
            if ($hoursChanged && DB::table('attendance_sessions')->where('school_id', $school->id)->where('schedule_id', $schedule->id)->exists()) {
                throw ApiProblem::scheduleConflict('Jadwal ini sudah dipakai absensi, jamnya tidak bisa diubah. Terbitkan versi baru yang berlaku mulai tanggal yang diinginkan.');
            }

            $schedule->fill($changes)->save();
            $schedule->refresh();

            $this->audit->record('school.schedule.updated', $school->id, 'schedule', $schedule->id, $before, $this->snapshot($schedule), null, $actor);

            return $schedule;
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
    private function snapshot(Schedule $s): array
    {
        return [
            'scope_type' => $s->scope_type,
            'grade_level' => $s->grade_level,
            'class_id' => $s->class_id,
            'day_of_week' => $s->day_of_week,
            'check_in_start' => substr($s->check_in_start, 0, 5),
            'check_in_deadline' => substr($s->check_in_deadline, 0, 5),
            'late_grace_minutes' => $s->late_grace_minutes,
            'check_out_start' => substr($s->check_out_start, 0, 5),
            'scheduled_check_out' => substr($s->scheduled_check_out, 0, 5),
            'effective_from' => $s->effective_from->toDateString(),
            'effective_until' => $s->effective_until?->toDateString(),
            'is_active' => $s->is_active,
        ];
    }
}
