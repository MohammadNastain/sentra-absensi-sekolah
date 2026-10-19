<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Koreksi absensi harian (Tahap 5C): perubahan status dan keputusan pulang awal. Admin mengoreksi langsung; wali
 * kelas mengajukan untuk kelasnya lalu admin memutuskan. Event tidak pernah diubah: setiap koreksi yang berlaku
 * menulis baris `attendance_adjustments` (append-only) dan audit, lalu memperbarui proyeksi harian.
 * Koreksi manual menandai dasar `manual` sehingga tidak ditimpa penerapan izin otomatis.
 */
final class ManageCorrections
{
    /** Status tujuan yang boleh dipilih manusia (alpa sementara hanya dihasilkan aturan). */
    public const STATUSES = ['hadir', 'terlambat', 'izin', 'sakit', 'dispensasi', 'alpa'];

    public const EARLY_LEAVE = ['approved', 'rejected'];

    /** Jenis koreksi jam; nilainya 'HH:MM' waktu lokal sekolah pada tanggal absensi. */
    public const TIMES = ['check_in_time', 'check_out_time'];

    /** Kunci nilai pada JSON `to_value` per jenis koreksi. */
    private const VALUE_KEYS = [
        'status_change' => 'attendance_status',
        'early_leave_decision' => 'early_leave_status',
        'check_in_time' => 'check_in_time',
        'check_out_time' => 'check_out_time',
    ];

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly CloseAttendanceDay $closer,
        private readonly ScheduleResolver $resolver,
    ) {}

    /**
     * Koreksi langsung oleh yang berwenang.
     *
     * @param  array{student_id: string, date: string, type: string, value: string, reason: string}  $data
     * @return array<string, mixed>
     */
    public function applyDirect(School $school, User $actor, array $data): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $data) {
            $daily = $this->dailyFor($school, $data['student_id'], $data['date'], null);

            return $this->perform($school, $actor, $actor, $daily, $data['type'], $data['value'], $data['reason'], null);
        }));
    }

    /**
     * Pengajuan oleh wali kelas.
     *
     * @param  array{student_id: string, date: string, type: string, value: string, reason: string}  $data
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function request(School $school, User $actor, array $data, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $data, $classScope) {
            $daily = $this->dailyFor($school, $data['student_id'], $data['date'], $classScope);
            $this->assertApplicable($school, $daily, $data['type'], $data['value']);

            try {
                $id = (string) DB::scalar(
                    'INSERT INTO correction_requests (school_id, daily_attendance_id, student_id, attendance_date, adjustment_type, to_value, reason, requested_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?) RETURNING id',
                    [$school->id, $daily->id, $data['student_id'], $data['date'], $data['type'], json_encode($this->toValue($data['type'], $data['value'])), trim($data['reason']), $actor->id],
                );
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'correction_requests_one_pending')) {
                    throw ApiProblem::duplicateValue('Sudah ada pengajuan koreksi yang menunggu untuk hari dan jenis ini.');
                }
                throw $e;
            }
            $this->audit->record('school.correction.requested', $school->id, 'attendance_correction', $id, null, $this->auditView($data), $data['reason'], $actor);

            return $this->find($school, $id);
        }));
    }

    /**
     * @param  list<string>|null  $classScope
     * @return list<array<string, mixed>>
     */
    public function list(School $school, ?string $status, ?array $classScope): array
    {
        $statusSql = $status === null ? '' : ' AND r.status = ?';
        $scopeSql = '';
        $scopeArgs = [];
        if ($classScope !== null) {
            $scopeSql = $classScope === [] ? ' AND false' : ' AND d.class_id IN ('.implode(',', array_fill(0, count($classScope), '?')).')';
            $scopeArgs = $classScope;
        }

        $rows = DB::select(
            "{$this->selectSql()} WHERE r.school_id = ?{$statusSql}{$scopeSql} ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT 200",
            [$school->id, ...($status === null ? [] : [$status]), ...$scopeArgs],
        );

        return array_values(array_map(fn ($r) => $this->present($r), $rows));
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(School $school, User $actor, string $id, string $decision, ?string $note): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $decision, $note) {
            $row = DB::selectOne('SELECT * FROM correction_requests WHERE school_id = ? AND id = ? FOR UPDATE', [$school->id, $id]) ?? throw new NotFoundHttpException;
            if ($row->status !== 'pending') {
                throw ApiProblem::invalidStateTransition('Pengajuan ini sudah diputuskan atau dibatalkan.');
            }

            if ($decision === 'approve') {
                $to = json_decode((string) $row->to_value, true);
                $value = (string) ($to[self::VALUE_KEYS[(string) $row->adjustment_type]] ?? '');
                $daily = $this->dailyFor($school, (string) $row->student_id, (string) $row->attendance_date, null);
                $this->perform($school, $actor, $this->userOf((string) $row->requested_by_user_id), $daily, (string) $row->adjustment_type, $value, (string) $row->reason, $id);
            }

            DB::update(
                'UPDATE correction_requests SET status = ?, decided_by_user_id = ?, decided_at = now(), decision_note = ?, updated_at = now() WHERE id = ? AND school_id = ?',
                [$decision === 'approve' ? 'approved' : 'rejected', $actor->id, $note, $id, $school->id],
            );
            $this->audit->record('school.correction.'.($decision === 'approve' ? 'approved' : 'rejected'), $school->id, 'attendance_correction', $id, null, null, $note, $actor);

            return $this->find($school, $id);
        }));
    }

    /**
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function cancel(School $school, User $actor, string $id, string $reason, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $reason, $classScope) {
            $row = DB::selectOne('SELECT * FROM correction_requests WHERE school_id = ? AND id = ? FOR UPDATE', [$school->id, $id]) ?? throw new NotFoundHttpException;
            if ($classScope !== null) {
                $this->dailyFor($school, (string) $row->student_id, (string) $row->attendance_date, $classScope, false);
            }
            if ($row->status !== 'pending') {
                throw ApiProblem::invalidStateTransition('Pengajuan ini sudah diputuskan atau dibatalkan.');
            }
            DB::update(
                "UPDATE correction_requests SET status = 'cancelled', decided_by_user_id = ?, decided_at = now(), decision_note = ?, updated_at = now() WHERE id = ? AND school_id = ?",
                [$actor->id, $reason, $id, $school->id],
            );
            $this->audit->record('school.correction.cancelled', $school->id, 'attendance_correction', $id, null, null, $reason, $actor);

            return $this->find($school, $id);
        }));
    }

    /**
     * Riwayat koreksi yang berlaku (attendance_adjustments) pada rentang tanggal.
     *
     * @return list<array<string, mixed>>
     */
    public function history(School $school, string $from, string $to, ?string $studentId): array
    {
        $rows = DB::select(
            'SELECT a.id, a.attendance_date, a.adjustment_type, a.from_value, a.to_value, a.reason, a.status, a.created_at, s.full_name, req.name AS requested_by, dec.name AS decided_by
             FROM attendance_adjustments a
             JOIN students s ON s.school_id = a.school_id AND s.id = a.student_id
             JOIN users req ON req.id = a.requested_by_user_id
             LEFT JOIN users dec ON dec.id = a.decided_by_user_id
             WHERE a.school_id = ? AND a.attendance_date BETWEEN ? AND ?'.($studentId === null ? '' : ' AND a.student_id = ?').'
             ORDER BY a.created_at DESC, a.id DESC LIMIT 500',
            [$school->id, $from, $to, ...($studentId === null ? [] : [$studentId])],
        );

        return array_values(array_map(fn ($r) => [
            'id' => (string) $r->id,
            'attendance_date' => (string) $r->attendance_date,
            'student_name' => (string) $r->full_name,
            'adjustment_type' => (string) $r->adjustment_type,
            'from' => json_decode((string) ($r->from_value ?? 'null'), true),
            'to' => json_decode((string) ($r->to_value ?? 'null'), true),
            'reason' => (string) $r->reason,
            'status' => (string) $r->status,
            'requested_by' => (string) $r->requested_by,
            'decided_by' => $r->decided_by === null ? null : (string) $r->decided_by,
            'created_at' => Carbon::parse((string) $r->created_at)->utc()->toIso8601String(),
        ], $rows));
    }

    /**
     * Menerapkan koreksi pada baris harian terkunci: update proyeksi, tulis adjustment approved, audit.
     *
     * @return array<string, mixed>
     */
    private function perform(School $school, User $decider, User $requester, stdClass $daily, string $type, string $value, string $reason, ?string $requestId): array
    {
        $this->assertApplicable($school, $daily, $type, $value);
        $reason = trim($reason);

        if ($type === 'status_change') {
            $from = ['attendance_status' => (string) $daily->attendance_status];
            $to = ['attendance_status' => $value];
            DB::update(
                "UPDATE daily_attendance SET attendance_status = ?, source = 'manual_adjustment', leave_request_id = NULL,
                        absence_basis = CASE WHEN ? = 'alpa' THEN 'manual' END,
                        verified_by_user_id = CASE WHEN ? = 'alpa' THEN ?::uuid END,
                        verified_at = CASE WHEN ? = 'alpa' THEN now() END,
                        late_minutes = CASE WHEN ? = 'terlambat' THEN COALESCE(late_minutes, 0) END,
                        version = version + 1, updated_at = now()
                 WHERE id = ? AND school_id = ?",
                [$value, $value, $value, $decider->id, $value, $value, $daily->id, $school->id],
            );
        } elseif (in_array($type, self::TIMES, true)) {
            [$from, $to] = $this->applyTime($school, $daily, $type, $value);
        } else {
            $from = ['early_leave_status' => $daily->early_leave_status];
            $to = ['early_leave_status' => $value];
            DB::update(
                'UPDATE daily_attendance SET early_leave_status = ?, version = version + 1, updated_at = now() WHERE id = ? AND school_id = ?',
                [$value, $daily->id, $school->id],
            );
        }

        $adjustmentId = (string) DB::scalar(
            "INSERT INTO attendance_adjustments (school_id, daily_attendance_id, student_id, attendance_date, adjustment_type, from_value, to_value, reason, status, requested_by_user_id, decided_by_user_id, decided_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, 'approved', ?, ?, now()) RETURNING id",
            [$school->id, $daily->id, $daily->student_id, $daily->attendance_date, $type, json_encode($from), json_encode($to), $reason, $requester->id, $decider->id],
        );
        $this->audit->record('school.correction.applied', $school->id, 'attendance_correction', $adjustmentId, $from + ['student_id' => (string) $daily->student_id, 'attendance_date' => (string) $daily->attendance_date, 'adjustment_type' => $type],
            $to + ['student_id' => (string) $daily->student_id, 'attendance_date' => (string) $daily->attendance_date, 'adjustment_type' => $type], $reason, $decider);

        return ['adjustment_id' => $adjustmentId, 'request_id' => $requestId, 'student_id' => (string) $daily->student_id, 'attendance_date' => (string) $daily->attendance_date, 'adjustment_type' => $type, 'from' => $from, 'to' => $to];
    }

    /**
     * Koreksi jam: menyimpan override pada proyeksi harian, lalu menurunkan ulang status/terlambat (masuk) atau
     * pulang awal (pulang) dari jadwal kelas pada tanggal itu.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyTime(School $school, stdClass $daily, string $type, string $value): array
    {
        $window = $this->windowFor($school, $daily);
        $seconds = ScheduleWindow::seconds($value);
        $at = Carbon::parse("{$daily->attendance_date} {$value}:00", $school->timezone)->utc()->toIso8601String();

        if ($type === 'check_in_time') {
            $from = ['check_in_time' => $this->effectiveTime($school, $daily, 'in'), 'attendance_status' => (string) $daily->attendance_status];
            $late = $seconds > $window->checkInDeadline + $window->graceSeconds;
            $status = $late ? 'terlambat' : 'hadir';
            DB::update(
                "UPDATE daily_attendance SET check_in_time_override = ?, attendance_status = ?, late_minutes = ?, source = 'manual_adjustment',
                        leave_request_id = NULL, absence_basis = NULL, version = version + 1, updated_at = now()
                 WHERE id = ? AND school_id = ?",
                [$at, $status, $late ? intdiv($seconds - $window->checkInDeadline, 60) : null, $daily->id, $school->id],
            );

            return [$from, ['check_in_time' => $value, 'attendance_status' => $status]];
        }

        $from = ['check_out_time' => $this->effectiveTime($school, $daily, 'out'), 'early_leave' => (bool) $daily->early_leave];
        $early = $seconds < $window->scheduledCheckOut;
        DB::update(
            "UPDATE daily_attendance SET check_out_time_override = ?, early_leave = ?,
                    early_leave_status = CASE WHEN ? THEN COALESCE(early_leave_status, 'pending') END,
                    source = 'manual_adjustment', version = version + 1, updated_at = now()
             WHERE id = ? AND school_id = ?",
            [$at, $early, $early, $daily->id, $school->id],
        );

        return [$from, ['check_out_time' => $value, 'early_leave' => $early]];
    }

    private function windowFor(School $school, stdClass $daily): ScheduleWindow
    {
        $class = $daily->class_id === null ? null : SchoolClass::query()->find((string) $daily->class_id);
        $schedule = $class === null ? null : $this->resolver->resolve($school->id, Carbon::parse((string) $daily->attendance_date, $school->timezone)->startOfDay(), $class)->schedule;
        if ($schedule === null) {
            throw ApiProblem::invalidStateTransition('Tidak ada jadwal pada tanggal itu untuk menghitung jam.');
        }

        return ScheduleWindow::fromSchedule($schedule);
    }

    /** Jam masuk/pulang berlaku ('HH:MM' lokal): override koreksi lebih dulu, lalu jam event; null bila belum ada. */
    private function effectiveTime(School $school, stdClass $daily, string $which): ?string
    {
        $override = $which === 'in' ? $daily->check_in_time_override : $daily->check_out_time_override;
        $at = $override ?? ($which === 'in' ? $daily->check_in_at : $daily->check_out_at);

        return $at === null ? null : Carbon::parse((string) $at)->setTimezone($school->timezone)->format('H:i');
    }

    private function assertTimeApplicable(School $school, stdClass $daily, string $type, string $value): void
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw ApiProblem::invalidStateTransition('Jam harus berformat HH:MM.');
        }
        $local = Carbon::now()->setTimezone($school->timezone);
        if ((string) $daily->attendance_date === $local->toDateString() && $value > $local->format('H:i')) {
            throw ApiProblem::invalidStateTransition('Jam koreksi tidak boleh di masa depan.');
        }

        $in = $this->effectiveTime($school, $daily, 'in');
        $out = $this->effectiveTime($school, $daily, 'out');
        if ($type === 'check_in_time') {
            if ($in === $value) {
                throw ApiProblem::invalidStateTransition('Jam masuk sudah sama dengan yang diminta.');
            }
            if ($out !== null && $value >= $out) {
                throw ApiProblem::invalidStateTransition('Jam masuk harus lebih awal daripada jam pulang.');
            }

            return;
        }

        if ($in === null) {
            throw ApiProblem::invalidStateTransition('Jam pulang tidak bisa dikoreksi sebelum ada jam masuk.');
        }
        if ($out === $value) {
            throw ApiProblem::invalidStateTransition('Jam pulang sudah sama dengan yang diminta.');
        }
        if ($value <= $in) {
            throw ApiProblem::invalidStateTransition('Jam pulang harus lebih akhir daripada jam masuk.');
        }
    }

    private function assertApplicable(School $school, stdClass $daily, string $type, string $value): void
    {
        if (in_array($type, self::TIMES, true)) {
            $this->assertTimeApplicable($school, $daily, $type, $value);

            return;
        }

        if ($type === 'status_change') {
            if (! in_array($value, self::STATUSES, true)) {
                throw ApiProblem::invalidStateTransition('Status tujuan tidak valid.');
            }
            if ($daily->attendance_status === $value) {
                throw ApiProblem::invalidStateTransition('Status sudah sama dengan yang diminta.');
            }

            return;
        }

        if (! $daily->early_leave) {
            throw ApiProblem::invalidStateTransition('Siswa tidak tercatat pulang awal pada hari itu.');
        }
        if (! in_array($value, self::EARLY_LEAVE, true) || $daily->early_leave_status === $value) {
            throw ApiProblem::invalidStateTransition('Keputusan pulang awal sudah sama atau tidak valid.');
        }
    }

    /**
     * Baris harian terkunci untuk siswa+tanggal (dibuat bila hari itu hari sekolah); 404 di luar jangkauan kelas.
     *
     * @param  list<string>|null  $classScope
     */
    private function dailyFor(School $school, string $studentId, string $date, ?array $classScope, bool $materialize = true): stdClass
    {
        $today = Carbon::now()->setTimezone($school->timezone)->toDateString();
        if ($date > $today) {
            throw ApiProblem::invalidStateTransition('Koreksi hanya untuk hari yang sudah berjalan.');
        }
        if ($materialize) {
            $this->closer->materialize($school, $date, [$studentId]);
        }

        $daily = DB::selectOne(
            'SELECT d.id, d.student_id, d.attendance_date, d.class_id, d.attendance_status, d.early_leave, d.early_leave_status, d.check_in_time_override, d.check_out_time_override,
                    (SELECT occurred_at_server FROM attendance_events WHERE school_id = d.school_id AND id = d.check_in_event_id) AS check_in_at,
                    (SELECT occurred_at_server FROM attendance_events WHERE school_id = d.school_id AND id = d.check_out_event_id) AS check_out_at
             FROM daily_attendance d WHERE d.school_id = ? AND d.student_id = ? AND d.attendance_date = ? FOR UPDATE OF d',
            [$school->id, $studentId, $date],
        );
        if ($daily === null) {
            // Siswa tidak ada, atau bukan hari sekolah bagi siswa itu (libur/tanpa jadwal/belum terdaftar).
            if (DB::selectOne('SELECT 1 AS x FROM students WHERE school_id = ? AND id = ?', [$school->id, $studentId]) === null) {
                throw new NotFoundHttpException;
            }
            throw ApiProblem::invalidStateTransition('Siswa tidak memiliki hari sekolah pada tanggal itu.');
        }
        if ($classScope !== null && ($daily->class_id === null || ! in_array((string) $daily->class_id, $classScope, true))) {
            throw new NotFoundHttpException;
        }

        return $daily;
    }

    private function userOf(string $id): User
    {
        return User::query()->findOrFail($id);
    }

    /** @return array<string, string> */
    private function toValue(string $type, string $value): array
    {
        return [self::VALUE_KEYS[$type] => $value];
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function auditView(array $data): array
    {
        return ['student_id' => $data['student_id'], 'attendance_date' => $data['date'], 'adjustment_type' => $data['type']]
            + [self::VALUE_KEYS[$data['type']] => $data['value']];
    }

    private function selectSql(): string
    {
        return 'SELECT r.*, s.full_name, s.student_number, c.name AS class_name, d.attendance_status AS current_status, d.early_leave_status AS current_early,
                       COALESCE(d.check_in_time_override, (SELECT occurred_at_server FROM attendance_events WHERE school_id = d.school_id AND id = d.check_in_event_id)) AS current_check_in,
                       COALESCE(d.check_out_time_override, (SELECT occurred_at_server FROM attendance_events WHERE school_id = d.school_id AND id = d.check_out_event_id)) AS current_check_out,
                       sch.timezone AS school_timezone, req.name AS requested_by_name
                FROM correction_requests r
                JOIN students s ON s.school_id = r.school_id AND s.id = r.student_id
                JOIN daily_attendance d ON d.school_id = r.school_id AND d.id = r.daily_attendance_id
                LEFT JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
                JOIN schools sch ON sch.id = r.school_id
                JOIN users req ON req.id = r.requested_by_user_id';
    }

    /** @return array<string, mixed> */
    private function find(School $school, string $id): array
    {
        $row = DB::selectOne("{$this->selectSql()} WHERE r.school_id = ? AND r.id = ?", [$school->id, $id]) ?? throw new NotFoundHttpException;

        return $this->present($row);
    }

    private function currentValue(stdClass $r): string
    {
        $time = fn (?string $at) => $at === null ? '' : Carbon::parse($at)->setTimezone((string) $r->school_timezone)->format('H:i');

        return match ((string) $r->adjustment_type) {
            'status_change' => (string) $r->current_status,
            'check_in_time' => $time($r->current_check_in),
            'check_out_time' => $time($r->current_check_out),
            default => (string) ($r->current_early ?? ''),
        };
    }

    /** @return array<string, mixed> */
    private function present(stdClass $r): array
    {
        $to = json_decode((string) $r->to_value, true);

        return [
            'id' => (string) $r->id,
            'student_id' => (string) $r->student_id,
            'student_name' => (string) $r->full_name,
            'class_name' => $r->class_name === null ? null : (string) $r->class_name,
            'attendance_date' => (string) $r->attendance_date,
            'adjustment_type' => (string) $r->adjustment_type,
            'current_value' => $this->currentValue($r),
            'requested_value' => (string) ($to[self::VALUE_KEYS[(string) $r->adjustment_type]] ?? ''),
            'reason' => (string) $r->reason,
            'status' => (string) $r->status,
            'requested_by' => (string) $r->requested_by_name,
            'decision_note' => $r->decision_note === null ? null : (string) $r->decision_note,
            'created_at' => Carbon::parse((string) $r->created_at)->utc()->toIso8601String(),
        ];
    }
}
