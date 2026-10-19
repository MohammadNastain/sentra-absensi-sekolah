<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Antrean `needs_review` (scan pulang tanpa catatan masuk) dan laporan anomali mode bantuan.
 * Resolusi tidak mengubah event: ia menulis baris `attendance_adjustments` (append-only) dan menyesuaikan proyeksi harian.
 */
final class ReviewQueue
{
    public function __construct(private readonly DatabaseContext $database, private readonly AuditLogger $audit) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function pending(School $school): array
    {
        $rows = DB::select(
            "SELECT e.id, e.attendance_date, e.event_type, e.reason_code, e.occurred_at_server, s.full_name, c.name AS class_name
             FROM attendance_events e
             JOIN students s ON s.school_id = e.school_id AND s.id = e.student_id
             LEFT JOIN class_enrollments en ON en.school_id = e.school_id AND en.student_id = e.student_id
                 AND en.start_date <= e.attendance_date AND (en.end_date IS NULL OR en.end_date >= e.attendance_date)
             LEFT JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE e.school_id = ? AND e.result = 'needs_review'
               AND NOT EXISTS (SELECT 1 FROM attendance_adjustments a WHERE a.school_id = e.school_id AND a.source_event_id = e.id AND a.adjustment_type = 'review_resolution')
             ORDER BY e.occurred_at_server DESC LIMIT 200",
            [$school->id],
        );

        return array_values(array_map(fn ($r) => [
            'event_id' => (string) $r->id,
            'attendance_date' => (string) $r->attendance_date,
            'student_name' => (string) $r->full_name,
            'class_name' => $r->class_name === null ? null : (string) $r->class_name,
            'event_type' => $r->event_type,
            'reason_code' => $r->reason_code,
            'recorded_at_local' => Carbon::parse((string) $r->occurred_at_server)->setTimezone($school->timezone)->format('H.i'),
        ], $rows));
    }

    /**
     * @return array{event_id: string, decision: string, adjustment_id: string}
     */
    public function resolve(School $school, User $actor, string $eventId, string $decision, string $reason): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $eventId, $decision, $reason) {
            // attendance_events append-only (tanpa hak UPDATE): serialisasi lewat kunci baris harian.
            $event = DB::selectOne("SELECT id, student_id, attendance_date, event_type, is_late, late_minutes FROM attendance_events WHERE school_id = ? AND id = ? AND result = 'needs_review'", [$school->id, $eventId])
                ?? throw new NotFoundHttpException;

            $daily = DB::selectOne(
                'SELECT id, attendance_status, check_in_event_id, check_out_event_id FROM daily_attendance WHERE school_id = ? AND student_id = ? AND attendance_date = ? FOR UPDATE',
                [$school->id, $event->student_id, $event->attendance_date],
            ) ?? throw new NotFoundHttpException;

            if (DB::selectOne("SELECT 1 AS x FROM attendance_adjustments WHERE school_id = ? AND source_event_id = ? AND adjustment_type = 'review_resolution'", [$school->id, $eventId]) !== null) {
                throw ApiProblem::invalidStateTransition('Event ini sudah diselesaikan.');
            }

            $from = ['attendance_status' => $daily->attendance_status, 'check_in_event_id' => $daily->check_in_event_id, 'check_out_event_id' => $daily->check_out_event_id];
            $to = $from;

            if ($decision === 'accept' && $event->event_type === 'check_in') {
                // Scan masuk yang bentrok dengan alpa final: terima = status menjadi hadir/terlambat sesuai scan.
                if ($daily->check_in_event_id !== null) {
                    throw ApiProblem::invalidStateTransition('Catatan masuk hari itu sudah ada.');
                }
                $status = $event->is_late ? 'terlambat' : 'hadir';
                DB::update(
                    "UPDATE daily_attendance SET attendance_status = ?, absence_basis = NULL, check_in_event_id = ?, late_minutes = ?, source = 'manual_adjustment', version = version + 1, updated_at = now() WHERE id = ? AND school_id = ?",
                    [$status, $eventId, $event->is_late ? $event->late_minutes : null, $daily->id, $school->id],
                );
                $to = ['attendance_status' => $status, 'check_in_event_id' => $eventId];
            } elseif ($decision === 'accept') {
                if ($daily->check_out_event_id !== null) {
                    throw ApiProblem::invalidStateTransition('Catatan pulang hari itu sudah ada.');
                }
                $status = $daily->attendance_status === 'belum_tercatat' ? 'hadir' : $daily->attendance_status;
                DB::update(
                    "UPDATE daily_attendance SET attendance_status = ?, check_out_event_id = ?, source = 'manual_adjustment', version = version + 1, updated_at = now() WHERE id = ? AND school_id = ?",
                    [$status, $eventId, $daily->id, $school->id],
                );
                $to = ['attendance_status' => $status, 'check_out_event_id' => $eventId];
            }

            $adjustmentId = (string) DB::scalar(
                "INSERT INTO attendance_adjustments (school_id, daily_attendance_id, student_id, attendance_date, source_event_id, adjustment_type, from_value, to_value, reason, status, requested_by_user_id, decided_by_user_id, decided_at)
                 VALUES (?, ?, ?, ?, ?, 'review_resolution', ?::jsonb, ?::jsonb, ?, ?, ?, ?, now()) RETURNING id",
                [$school->id, $daily->id, $event->student_id, $event->attendance_date, $eventId, json_encode($from), json_encode($to), $reason,
                    $decision === 'accept' ? 'approved' : 'rejected', $actor->id, $actor->id],
            );

            $this->audit->record('school.attendance.review_resolved', $school->id, 'attendance_event', $eventId, null, [
                'student_id' => (string) $event->student_id, 'result' => $decision === 'accept' ? 'approved' : 'rejected',
            ], $reason, $actor);

            return ['event_id' => $eventId, 'decision' => $decision, 'adjustment_id' => $adjustmentId];
        }));
    }

    /**
     * Ringkasan pemakaian mode bantuan pada rentang tanggal (deteksi penyalahgunaan: petugas atau siswa yang berulang).
     *
     * @return array{from: string, to: string, total: int, by_officer: list<array<string, mixed>>, by_student: list<array<string, mixed>>, by_reason: list<array<string, mixed>>}
     */
    public function assistReport(School $school, string $from, string $to): array
    {
        $base = "FROM attendance_events e WHERE e.school_id = ? AND e.method = 'assisted_manual' AND e.result = 'accepted' AND e.attendance_date BETWEEN ? AND ?";
        $args = [$school->id, $from, $to];

        $officers = DB::select("SELECT u.name, count(*) AS total, count(*) FILTER (WHERE e.event_type = 'check_out') AS check_outs
            FROM attendance_events e JOIN users u ON u.id = e.created_by_user_id
            WHERE e.school_id = ? AND e.method = 'assisted_manual' AND e.result = 'accepted' AND e.attendance_date BETWEEN ? AND ?
            GROUP BY u.id, u.name ORDER BY total DESC, u.name LIMIT 50", $args);
        $students = DB::select("SELECT s.full_name, count(*) AS total
            FROM attendance_events e JOIN students s ON s.school_id = e.school_id AND s.id = e.student_id
            WHERE e.school_id = ? AND e.method = 'assisted_manual' AND e.result = 'accepted' AND e.attendance_date BETWEEN ? AND ?
            GROUP BY s.id, s.full_name ORDER BY total DESC, s.full_name LIMIT 50", $args);
        $reasons = DB::select("SELECT e.assist_reason_code AS reason_code, count(*) AS total {$base} GROUP BY e.assist_reason_code ORDER BY total DESC", $args);

        return [
            'from' => $from, 'to' => $to,
            'total' => array_sum(array_map(fn ($r) => (int) $r->total, $reasons)),
            'by_officer' => array_values(array_map(fn ($r) => ['officer_name' => (string) $r->name, 'total' => (int) $r->total, 'check_outs' => (int) $r->check_outs], $officers)),
            'by_student' => array_values(array_map(fn ($r) => ['student_name' => (string) $r->full_name, 'total' => (int) $r->total], $students)),
            'by_reason' => array_values(array_map(fn ($r) => ['reason_code' => (string) $r->reason_code, 'total' => (int) $r->total], $reasons)),
        ];
    }
}
