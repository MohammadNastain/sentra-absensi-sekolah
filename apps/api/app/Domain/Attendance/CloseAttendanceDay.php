<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Tenancy\DatabaseContext;
use App\Http\Middleware\CountScanOutcomes;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penutupan hari per sekolah (ADR 0005 §5): memastikan setiap siswa wajib hadir punya baris harian
 * (`belum_tercatat`), lalu, bila aturan alpa aktif dan cutoff terlewati dan hari dinilai sehat, menandai siswa
 * tanpa catatan sebagai `alpa_belum_diverifikasi`. Status itu tidak pernah dihitung sebagai alpa resmi; alpa final
 * hanya lewat verifikasi manusia (VerifyAbsences). Idempoten: dijalankan ulang menghasilkan keadaan yang sama.
 */
final class CloseAttendanceDay
{
    public const HEALTH_NO_SCANS = 'NO_SCANS';

    public const HEALTH_MANUAL = 'MANUAL';

    public const HEALTH_DEVICE_OFFLINE = 'DEVICE_OFFLINE';

    public const HEALTH_ERROR_RATE = 'SCAN_ERROR_RATE';

    /** Terminal baru dinilai bila hari itu meninggalkan cukup jejak (klien yang mengirim heartbeat tiap 30 detik); klien yang hanya sesekali scan tidak dinilai. */
    private const HEARTBEATING_MIN_MINUTES = 5;

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly ScheduleResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Menjalankan penutupan untuk semua sekolah (job terjadwal) pada tanggal lokal masing-masing. Tanpa `$date`,
     * `$backfillDays` hari sebelumnya ikut dijalankan agar hari yang terlewat pulih sendiri.
     *
     * @return array<string, array{required: int, marked_absent: int, health: string|null}> per kode sekolah
     */
    public function runForAllSchools(?string $date = null, int $backfillDays = 0): array
    {
        /** @var list<School> $schools */
        $schools = $this->database->runAsPlatform(fn () => School::query()->get()->all());

        $results = [];
        foreach ($schools as $school) {
            try {
                $local = $date ?? Carbon::now()->setTimezone($school->timezone)->toDateString();
                // Pemulihan: hari-hari sebelumnya yang terlewat (server mati) dijalankan lagi; run() idempoten dan
                // menghormati keputusan manusia (penahanan/pelepasan, alpa terverifikasi).
                if ($date === null) {
                    for ($back = $backfillDays; $back >= 1; $back--) {
                        $past = Carbon::parse($local)->subDays($back)->toDateString();
                        try {
                            $this->database->runInSchool($school->id, fn () => $this->run($school, $past));
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
                $results[$school->code] = $this->database->runInSchool($school->id, fn () => $this->run($school, $local));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $results;
    }

    /**
     * Konteks sekolah (RLS) harus sudah aktif.
     *
     * @return array{required: int, marked_absent: int, health: string|null}
     */
    public function run(School $school, string $date): array
    {
        return DB::transaction(function () use ($school, $date) {
            $required = $this->materialize($school, $date);
            $this->applyLeave($school, $date);
            $markedAbsent = 0;
            $health = null;

            if ($required !== [] && $this->ruleDue($school, $date)) {
                $health = $this->evaluateHealth($school, $date, count($required));
                if (in_array($health, ['ok', 'dilepas'], true)) {
                    foreach (array_chunk($required, 500) as $chunk) {
                        $markedAbsent += DB::update(
                            "UPDATE daily_attendance SET attendance_status = 'alpa_belum_diverifikasi', absence_basis = 'rule', version = version + 1, updated_at = now()
                             WHERE school_id = ? AND attendance_date = ? AND attendance_status = 'belum_tercatat' AND check_in_event_id IS NULL
                               AND student_id IN (".implode(',', array_fill(0, count($chunk), '?')).')',
                            [$school->id, $date, ...$chunk],
                        );
                    }
                }
            }

            return ['required' => count($required), 'marked_absent' => $markedAbsent, 'health' => $health];
        });
    }

    /**
     * Penahanan manual oleh petugas: aturan tidak berjalan pada hari itu, dan penanda alpa otomatis ditarik kembali.
     */
    public function markDisruption(School $school, string $date, User $actor, string $reason): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $date, $actor, $reason) {
            $healthId = (string) DB::scalar(
                "INSERT INTO attendance_day_health (school_id, attendance_date, status, reasons, marked_by_user_id)
                 VALUES (?, ?, 'perlu_pemeriksaan', ?::jsonb, ?)
                 ON CONFLICT (school_id, attendance_date) DO UPDATE
                 SET status = 'perlu_pemeriksaan', reasons = EXCLUDED.reasons, marked_by_user_id = EXCLUDED.marked_by_user_id,
                     released_by_user_id = NULL, release_reason = NULL, evaluated_at = now()
                 RETURNING id",
                [$school->id, $date, json_encode([self::HEALTH_MANUAL]), $actor->id],
            );
            $reverted = DB::update(
                "UPDATE daily_attendance SET attendance_status = 'belum_tercatat', absence_basis = NULL, version = version + 1, updated_at = now()
                 WHERE school_id = ? AND attendance_date = ? AND attendance_status = 'alpa_belum_diverifikasi' AND absence_basis = 'rule'",
                [$school->id, $date],
            );
            $this->audit->record('school.attendance.day_marked_disrupted', $school->id, 'attendance_day', $healthId, null, ['date' => $date, 'reverted' => $reverted], $reason, $actor);
        }));
    }

    /**
     * Melepas penahanan setelah pemeriksaan: aturan alpa dijalankan untuk hari itu.
     *
     * @return array{required: int, marked_absent: int, health: string|null}
     */
    public function releaseHold(School $school, string $date, User $actor, string $reason): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $date, $actor, $reason) {
            $healthId = DB::scalar(
                "UPDATE attendance_day_health SET status = 'dilepas', released_by_user_id = ?, release_reason = ?, evaluated_at = now()
                 WHERE school_id = ? AND attendance_date = ? AND status = 'perlu_pemeriksaan' RETURNING id",
                [$actor->id, $reason, $school->id, $date],
            );
            if ($healthId === null) {
                throw ApiProblem::invalidStateTransition('Hari ini tidak sedang ditahan.');
            }
            $this->audit->record('school.attendance.day_hold_released', $school->id, 'attendance_day', (string) $healthId, null, ['date' => $date], $reason, $actor);

            return $this->run($school, $date);
        }));
    }

    /**
     * Menerapkan izin/sakit/dispensasi yang disetujui pada baris harian satu tanggal. Hanya baris tanpa check-in yang
     * berstatus belum_tercatat, alpa sementara, atau alpa final yang berubah (scan menang, D-13); alpa final yang
     * digantikan dicatat di `attendance_adjustments`. Idempoten. Konteks sekolah (RLS) harus sudah aktif.
     *
     * @param  list<string>|null  $studentIds  batasi pada siswa tertentu; null = semua
     * @return int jumlah baris yang berubah
     */
    public function applyLeave(School $school, string $date, ?array $studentIds = null): int
    {
        $only = '';
        $args = [$school->id, $date];
        if ($studentIds !== null) {
            if ($studentIds === []) {
                return 0;
            }
            $only = ' AND d.student_id IN ('.implode(',', array_fill(0, count($studentIds), '?')).')';
            array_push($args, ...$studentIds);
        }
        $from = "FROM daily_attendance d
             JOIN leave_requests l ON l.school_id = d.school_id AND l.student_id = d.student_id AND l.status = 'approved'
                 AND d.attendance_date BETWEEN l.start_date AND l.end_date
             WHERE d.school_id = ? AND d.attendance_date = ? AND d.check_in_event_id IS NULL
               AND d.attendance_status IN ('belum_tercatat', 'alpa_belum_diverifikasi', 'alpa')
               AND (d.attendance_status <> 'alpa' OR d.absence_basis = 'rule'){$only}";

        DB::insert(
            "INSERT INTO attendance_adjustments (school_id, daily_attendance_id, student_id, attendance_date, adjustment_type, from_value, to_value, reason, status, requested_by_user_id, decided_by_user_id, decided_at)
             SELECT d.school_id, d.id, d.student_id, d.attendance_date, 'status_change', jsonb_build_object('attendance_status', 'alpa'),
                    jsonb_build_object('attendance_status', l.leave_type, 'leave_request_id', l.id), 'Izin yang disetujui menggantikan alpa final', 'approved', l.decided_by_user_id, l.decided_by_user_id, now()
             {$from} AND d.attendance_status = 'alpa'",
            $args,
        );

        return DB::update(
            "UPDATE daily_attendance u SET attendance_status = x.leave_type, leave_request_id = x.leave_id, absence_basis = NULL, version = u.version + 1, updated_at = now()
             FROM (SELECT d.id, l.leave_type, l.id AS leave_id {$from}) x WHERE u.id = x.id AND u.school_id = ?",
            [...$args, $school->id],
        );
    }

    /**
     * Membuat baris harian untuk siswa aktif yang wajib hadir (kelas berjalan, ada jadwal, bukan libur).
     *
     * @param  list<string>|null  $only  batasi pada siswa tertentu; null = semua
     * @return list<string> id siswa wajib hadir
     */
    public function materialize(School $school, string $date, ?array $only = null): array
    {
        $day = Carbon::parse($date);
        $enrollments = DB::select(
            "SELECT en.student_id, en.class_id FROM class_enrollments en
             JOIN students s ON s.school_id = en.school_id AND s.id = en.student_id
             WHERE en.school_id = ? AND s.status = 'active' AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?)",
            [$school->id, $date, $date],
        );

        $byClass = [];
        foreach ($enrollments as $row) {
            if ($only !== null && ! in_array((string) $row->student_id, $only, true)) {
                continue;
            }
            $byClass[(string) $row->class_id][] = (string) $row->student_id;
        }

        $required = [];
        foreach ($byClass as $classId => $studentIds) {
            $class = SchoolClass::query()->find($classId);
            $schedule = $class === null ? null : $this->resolver->resolve($school->id, $day, $class)->schedule;
            if ($schedule === null) {
                continue;
            }

            DB::insert(
                'INSERT INTO attendance_sessions (school_id, session_date, schedule_id, timezone) VALUES (?, ?, ?, ?) ON CONFLICT (school_id, session_date, schedule_id) DO NOTHING',
                [$school->id, $date, $schedule->id, $school->timezone],
            );
            $sessionId = (string) DB::scalar(
                'SELECT id FROM attendance_sessions WHERE school_id = ? AND session_date = ? AND schedule_id = ?',
                [$school->id, $date, $schedule->id],
            );

            foreach (array_chunk($studentIds, 500) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                DB::insert(
                    "INSERT INTO daily_attendance (school_id, student_id, attendance_date, attendance_session_id, class_id)
                     SELECT ?::uuid, s, ?::date, ?::uuid, ?::uuid FROM unnest(ARRAY[{$marks}]::uuid[]) AS s
                     ON CONFLICT (school_id, student_id, attendance_date) DO NOTHING",
                    [$school->id, $date, $sessionId, $classId, ...$chunk],
                );
                DB::update(
                    "UPDATE daily_attendance SET attendance_session_id = COALESCE(attendance_session_id, ?), class_id = COALESCE(class_id, ?)
                     WHERE school_id = ? AND attendance_date = ? AND student_id IN ({$marks}) AND (attendance_session_id IS NULL OR class_id IS NULL)",
                    [$sessionId, $classId, $school->id, $date, ...$chunk],
                );
            }
            array_push($required, ...$studentIds);
        }

        return $required;
    }

    private function ruleDue(School $school, string $date): bool
    {
        $cutoff = DB::scalar('SELECT absence_cutoff_time FROM schools WHERE id = ? AND absence_rule_enabled', [$school->id]);
        if (! is_string($cutoff)) {
            return false;
        }

        $local = Carbon::now()->setTimezone($school->timezone);
        if ($date !== $local->toDateString()) {
            return $date < $local->toDateString();
        }

        return $local->format('H:i:s') >= substr($cutoff, 0, 8);
    }

    /** @return string 'ok' | 'perlu_pemeriksaan' | 'dilepas' */
    private function evaluateHealth(School $school, string $date, int $required): string
    {
        $existing = DB::selectOne('SELECT status, marked_by_user_id FROM attendance_day_health WHERE school_id = ? AND attendance_date = ? FOR UPDATE', [$school->id, $date]);
        if ($existing !== null && ($existing->status === 'dilepas' || $existing->marked_by_user_id !== null)) {
            return (string) $existing->status;
        }

        $reasons = [];
        $scans = (int) DB::scalar("SELECT count(*) FROM attendance_events WHERE school_id = ? AND attendance_date = ? AND result = 'accepted'", [$school->id, $date]);
        if ($required > 0 && $scans === 0) {
            $reasons[] = self::HEALTH_NO_SCANS;
        }
        if ($this->deviceOfflineTooLong($school, $date)) {
            $reasons[] = self::HEALTH_DEVICE_OFFLINE;
        }
        $outcomes = CountScanOutcomes::read($school->id, $date);
        if ($outcomes['total'] >= (int) config('sentra.health_error_min_requests') && (float) config('sentra.health_error_rate') <= $outcomes['errors'] / $outcomes['total']) {
            $reasons[] = self::HEALTH_ERROR_RATE;
        }

        $status = $reasons === [] ? 'ok' : 'perlu_pemeriksaan';
        DB::insert(
            'INSERT INTO attendance_day_health (school_id, attendance_date, status, reasons) VALUES (?, ?, ?, ?::jsonb)
             ON CONFLICT (school_id, attendance_date) DO UPDATE SET status = EXCLUDED.status, reasons = EXCLUDED.reasons, evaluated_at = now()',
            [$school->id, $date, $status, json_encode($reasons)],
        );

        return $status;
    }

    /**
     * Terminal aktif yang tampak hari itu (ada jejak keberadaan) tetapi tidak terlihat lebih dari ambang menit
     * kumulatif selama jendela masuk (dari batas masuk awal sampai tenggat akhir jadwal yang dipakai hari itu).
     * Satu jejak menutupi menit itu dan dua menit sesudahnya (jendela online terminal).
     */
    private function deviceOfflineTooLong(School $school, string $date): bool
    {
        $window = DB::selectOne(
            'SELECT min(sc.check_in_start) AS opens, max(sc.check_in_deadline) AS closes
             FROM attendance_sessions ss JOIN schedules sc ON sc.school_id = ss.school_id AND sc.id = ss.schedule_id
             WHERE ss.school_id = ? AND ss.session_date = ?',
            [$school->id, $date],
        );
        if ($window === null || $window->opens === null) {
            return false;
        }

        $start = Carbon::parse("{$date} {$window->opens}", $school->timezone)->utc()->startOfMinute();
        $end = Carbon::parse("{$date} {$window->closes}", $school->timezone)->utc()->startOfMinute();
        $minutes = (int) $start->diffInMinutes($end);
        if ($minutes <= 0) {
            return false;
        }

        $dayStart = Carbon::parse($date, $school->timezone)->startOfDay()->utc();
        $devices = DB::select(
            "SELECT p.device_id FROM device_presence p JOIN devices d ON d.id = p.device_id
             WHERE p.school_id = ? AND d.status = 'active' AND p.minute_at >= ? AND p.minute_at < ?
             GROUP BY p.device_id HAVING count(*) >= ?",
            [$school->id, $dayStart->toDateTimeString(), $dayStart->copy()->addDay()->toDateTimeString(), self::HEARTBEATING_MIN_MINUTES],
        );
        $threshold = (int) config('sentra.health_offline_minutes');
        foreach ($devices as $device) {
            $seen = DB::select(
                'SELECT minute_at FROM device_presence WHERE device_id = ? AND minute_at >= ? AND minute_at < ?',
                [$device->device_id, $start->copy()->subMinutes(2)->toDateTimeString(), $end->toDateTimeString()],
            );
            $covered = [];
            foreach ($seen as $row) {
                $at = (int) $start->diffInMinutes(Carbon::parse((string) $row->minute_at)->utc()->startOfMinute(), false);
                for ($m = max(0, $at); $m <= $at + 2 && $m < $minutes; $m++) {
                    $covered[$m] = true;
                }
            }
            if ($minutes - count($covered) > $threshold) {
                return true;
            }
        }

        return false;
    }
}
