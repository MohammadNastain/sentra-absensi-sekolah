<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Verifikasi alpa sementara menjadi alpa final oleh manusia berwenang (ADR 0005 §5, langkah 5). Semua-atau-tidak-sama-sekali:
 * satu siswa di luar jangkauan (kelas lain bagi wali kelas) atau bukan siswa hari itu membatalkan seluruh permintaan.
 * Setiap perubahan menulis `attendance_adjustments` (append-only) dan satu entri audit ringkas.
 */
final class VerifyAbsences
{
    public function __construct(private readonly DatabaseContext $database, private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $studentIds
     * @param  list<string>|null  $classScope  null = tanpa batas kelas
     * @return array{verified: int}
     */
    public function verify(School $school, User $actor, string $date, array $studentIds, string $reason, ?array $classScope): array
    {
        $studentIds = array_values(array_unique($studentIds));

        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $date, $studentIds, $reason, $classScope) {
            $marks = implode(',', array_fill(0, count($studentIds), '?'));
            $scopeSql = '';
            $scopeArgs = [];
            if ($classScope !== null) {
                $scopeSql = $classScope === [] ? ' AND false' : ' AND class_id IN ('.implode(',', array_fill(0, count($classScope), '?')).')';
                $scopeArgs = $classScope;
            }

            $rows = DB::select(
                "SELECT id, student_id, attendance_status FROM daily_attendance
                 WHERE school_id = ? AND attendance_date = ? AND student_id IN ({$marks}){$scopeSql} ORDER BY id FOR UPDATE",
                [$school->id, $date, ...$studentIds, ...$scopeArgs],
            );
            if (count($rows) !== count($studentIds)) {
                throw new NotFoundHttpException;
            }
            foreach ($rows as $row) {
                if ($row->attendance_status !== 'alpa_belum_diverifikasi') {
                    throw ApiProblem::invalidStateTransition('Hanya status alpa sementara yang dapat diverifikasi.');
                }
            }

            foreach ($rows as $row) {
                DB::update(
                    "UPDATE daily_attendance SET attendance_status = 'alpa', verified_by_user_id = ?, verified_at = now(), source = 'manual_adjustment', version = version + 1, updated_at = now()
                     WHERE id = ? AND school_id = ?",
                    [$actor->id, $row->id, $school->id],
                );
                DB::insert(
                    "INSERT INTO attendance_adjustments (school_id, daily_attendance_id, student_id, attendance_date, adjustment_type, from_value, to_value, reason, status, requested_by_user_id, decided_by_user_id, decided_at)
                     VALUES (?, ?, ?, ?, 'status_change', ?::jsonb, ?::jsonb, ?, 'approved', ?, ?, now())",
                    [$school->id, $row->id, $row->student_id, $date, json_encode(['attendance_status' => 'alpa_belum_diverifikasi']), json_encode(['attendance_status' => 'alpa']), $reason, $actor->id, $actor->id],
                );
            }

            $this->audit->record('school.attendance.absence_verified', $school->id, 'attendance_day', DB::scalar(
                'SELECT id FROM attendance_day_health WHERE school_id = ? AND attendance_date = ?', [$school->id, $date],
            ), null, ['date' => $date, 'count' => count($rows)], $reason, $actor);

            return ['verified' => count($rows)];
        }));
    }
}
