<?php

namespace App\Domain\Scheduling;

use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\SchoolClass;
use Illuminate\Support\Carbon;

/**
 * Menentukan jadwal yang berlaku bagi sebuah kelas pada sebuah tanggal (DATABASE_DESIGN §3.3):
 * hari libur → tidak ada sesi; selain itu jadwal kelas > jenjang > sekolah yang aktif dan efektif
 * pada tanggal itu untuk hari dalam seminggunya. Satu-satunya tempat aturan ini ditulis; jalur scan,
 * kalender, dan laporan memakainya.
 *
 * Data dimuat sekali untuk seluruh rentang lalu diselesaikan di memori, jadi satu bulan kalender
 * cukup dua query. Konteks sekolah (RLS) harus sudah aktif.
 */
final class ScheduleResolver
{
    /**
     * @return array<string, ResolvedDay> kunci = tanggal Y-m-d, berurutan
     */
    public function resolveRange(string $schoolId, Carbon $from, Carbon $to, ?SchoolClass $class = null): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        $holidays = Holiday::query()
            ->where('school_id', $schoolId)
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->get();

        $schedules = Schedule::query()
            ->with('schoolClass')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $start->toDateString()))
            ->where(fn ($q) => $q->where('scope_type', Schedule::SCOPE_SCHOOL)
                ->when($class !== null, fn ($w) => $w
                    ->orWhere(fn ($g) => $g->where('scope_type', Schedule::SCOPE_GRADE)->where('grade_level', $class?->grade_level))
                    ->orWhere(fn ($c) => $c->where('scope_type', Schedule::SCOPE_CLASS)->where('class_id', $class?->id))))
            ->get();

        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $holiday = $holidays->first(fn (Holiday $h) => $h->start_date->toDateString() <= $date && $h->end_date->toDateString() >= $date);

            $days[$date] = new ResolvedDay(
                $date,
                $day->dayOfWeekIso,
                $holiday,
                $holiday === null ? $this->pick(array_values($schedules->all()), $date, $day->dayOfWeekIso) : null,
            );
        }

        return $days;
    }

    public function resolve(string $schoolId, Carbon $date, ?SchoolClass $class = null): ResolvedDay
    {
        return $this->resolveRange($schoolId, $date, $date, $class)[$date->toDateString()];
    }

    /**
     * @param  list<Schedule>  $schedules
     */
    private function pick(array $schedules, string $date, int $dayOfWeek): ?Schedule
    {
        $applicable = array_filter($schedules, fn (Schedule $s) => $s->day_of_week === $dayOfWeek
            && $s->effective_from->toDateString() <= $date
            && ($s->effective_until === null || $s->effective_until->toDateString() >= $date));

        foreach ([Schedule::SCOPE_CLASS, Schedule::SCOPE_GRADE, Schedule::SCOPE_SCHOOL] as $scope) {
            foreach ($applicable as $schedule) {
                if ($schedule->scope_type === $scope) {
                    return $schedule;
                }
            }
        }

        return null;
    }
}
