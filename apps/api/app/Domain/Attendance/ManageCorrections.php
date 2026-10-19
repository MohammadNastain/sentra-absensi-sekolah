<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
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

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly CloseAttendanceDay $closer,
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
            $this->assertApplicable($daily, $data['type'], $data['value']);

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
                $value = (string) ($to['attendance_status'] ?? $to['early_leave_status'] ?? '');
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
        $this->assertApplicable($daily, $type, $value);
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

    private function assertApplicable(stdClass $daily, string $type, string $value): void
    {
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
            'SELECT id, student_id, attendance_date, class_id, attendance_status, early_leave, early_leave_status FROM daily_attendance WHERE school_id = ? AND student_id = ? AND attendance_date = ? FOR UPDATE',
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
        return $type === 'status_change' ? ['attendance_status' => $value] : ['early_leave_status' => $value];
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function auditView(array $data): array
    {
        return ['student_id' => $data['student_id'], 'attendance_date' => $data['date'], 'adjustment_type' => $data['type']]
            + ($data['type'] === 'status_change' ? ['attendance_status' => $data['value']] : ['early_leave_status' => $data['value']]);
    }

    private function selectSql(): string
    {
        return 'SELECT r.*, s.full_name, s.student_number, c.name AS class_name, d.attendance_status AS current_status, d.early_leave_status AS current_early,
                       req.name AS requested_by_name
                FROM correction_requests r
                JOIN students s ON s.school_id = r.school_id AND s.id = r.student_id
                JOIN daily_attendance d ON d.school_id = r.school_id AND d.id = r.daily_attendance_id
                LEFT JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
                JOIN users req ON req.id = r.requested_by_user_id';
    }

    /** @return array<string, mixed> */
    private function find(School $school, string $id): array
    {
        $row = DB::selectOne("{$this->selectSql()} WHERE r.school_id = ? AND r.id = ?", [$school->id, $id]) ?? throw new NotFoundHttpException;

        return $this->present($row);
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
            'current_value' => (string) ($r->adjustment_type === 'status_change' ? $r->current_status : ($r->current_early ?? '')),
            'requested_value' => (string) ($to['attendance_status'] ?? $to['early_leave_status'] ?? ''),
            'reason' => (string) $r->reason,
            'status' => (string) $r->status,
            'requested_by' => (string) $r->requested_by_name,
            'decision_note' => $r->decision_note === null ? null : (string) $r->decision_note,
            'created_at' => Carbon::parse((string) $r->created_at)->utc()->toIso8601String(),
        ];
    }
}
