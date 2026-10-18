<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Izin/sakit/dispensasi (Tahap 5B). Staf mengajukan, admin/petugas piket memutuskan. Persetujuan memproyeksikan
 * status ke `daily_attendance` untuk tanggal yang sudah berjalan (CloseAttendanceDay::applyLeave); tanggal mendatang
 * diterapkan saat penutupan hari. Scan menang atas izin (D-13). Setiap transisi teraudit.
 */
final class ManageLeaveRequests
{
    public const TYPES = ['izin', 'sakit', 'dispensasi'];

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly CloseAttendanceDay $closer,
    ) {}

    /**
     * @param  list<string>|null  $classScope  null = tanpa batas kelas
     * @return list<array<string, mixed>>
     */
    public function list(School $school, ?string $status, ?array $classScope): array
    {
        [$scopeSql, $scopeArgs] = $this->scopeSql($classScope);
        $statusSql = $status === null ? '' : ' AND l.status = ?';

        $rows = DB::select(
            "SELECT l.*, s.full_name, s.student_number, c.name AS class_name
             FROM leave_requests l
             JOIN students s ON s.school_id = l.school_id AND s.id = l.student_id
             LEFT JOIN class_enrollments en ON en.school_id = l.school_id AND en.student_id = l.student_id AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?)
             LEFT JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE l.school_id = ?{$statusSql}{$scopeSql}
             ORDER BY (l.status = 'pending') DESC, l.created_at DESC LIMIT 200",
            [$this->today($school), $this->today($school), $school->id, ...($status === null ? [] : [$status]), ...$scopeArgs],
        );

        return array_values(array_map(fn ($r) => $this->present($r), $rows));
    }

    /**
     * @param  array{student_id: string, leave_type: string, start_date: string, end_date: string, reason: string}  $data
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function create(School $school, User $actor, array $data, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $data, $classScope) {
            $this->assertStudentInScope($school, $data['student_id'], $classScope);

            try {
                $id = (string) DB::scalar(
                    'INSERT INTO leave_requests (school_id, student_id, leave_type, start_date, end_date, reason, requested_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [$school->id, $data['student_id'], $data['leave_type'], $data['start_date'], $data['end_date'], trim($data['reason']), $actor->id],
                );
            } catch (QueryException $e) {
                // Savepoint tidak dipakai: transaksi luar dibatalkan oleh pelempar ulang, jadi tidak ada baris setengah jadi.
                if (str_contains($e->getMessage(), 'leave_requests_no_overlap')) {
                    throw ApiProblem::duplicateValue('Siswa sudah memiliki izin yang menunggu atau disetujui pada rentang tanggal itu.');
                }
                throw $e;
            }

            $this->audit->record('school.leave.requested', $school->id, 'leave_request', $id, null, $this->auditView($id), $data['reason'], $actor);

            return $this->find($school, $id);
        }));
    }

    /**
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function attach(School $school, User $actor, string $id, UploadedFile $file, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $file, $classScope) {
            $row = $this->lock($school, $id, $classScope);
            if (! in_array($row->status, ['pending', 'approved'], true)) {
                throw ApiProblem::invalidStateTransition('Lampiran hanya dapat ditambahkan pada izin yang menunggu atau disetujui.');
            }

            $extension = strtolower($file->guessExtension() ?? 'bin');
            $path = "leave/{$school->id}/{$id}.{$extension}";
            if ($row->attachment_path !== null && $row->attachment_path !== $path) {
                Storage::disk('local')->delete($row->attachment_path);
            }
            Storage::disk('local')->putFileAs("leave/{$school->id}", $file, "{$id}.{$extension}");

            DB::update(
                'UPDATE leave_requests SET attachment_path = ?, attachment_name = ?, attachment_mime = ?, updated_at = now() WHERE id = ? AND school_id = ?',
                [$path, mb_substr($file->getClientOriginalName(), 0, 200), $file->getMimeType(), $id, $school->id],
            );
            $this->audit->record('school.leave.attachment_added', $school->id, 'leave_request', $id, null, null, null, $actor);

            return $this->find($school, $id);
        }));
    }

    /**
     * @param  list<string>|null  $classScope
     * @return array{path: string, name: string, mime: string}
     */
    public function attachment(School $school, string $id, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, function () use ($school, $id, $classScope) {
            $row = $this->visible($school, $id, $classScope);
            if ($row->attachment_path === null || ! Storage::disk('local')->exists($row->attachment_path)) {
                throw new NotFoundHttpException;
            }

            return ['path' => (string) $row->attachment_path, 'name' => (string) $row->attachment_name, 'mime' => (string) $row->attachment_mime];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(School $school, User $actor, string $id, string $decision, ?string $note): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $decision, $note) {
            $row = $this->lock($school, $id, null);
            if ($row->status !== 'pending') {
                throw ApiProblem::invalidStateTransition('Izin ini sudah diputuskan atau dibatalkan.');
            }

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            DB::update(
                'UPDATE leave_requests SET status = ?, decided_by_user_id = ?, decided_at = now(), decision_note = ?, updated_at = now() WHERE id = ? AND school_id = ?',
                [$status, $actor->id, $note, $id, $school->id],
            );

            $changed = $status === 'approved' ? $this->project($school, $row) : 0;
            $this->audit->record("school.leave.{$status}", $school->id, 'leave_request', $id, ['status' => 'pending'], $this->auditView($id) + ['status' => $status], $note, $actor);

            return $this->find($school, $id) + ['projected_days' => $changed];
        }));
    }

    /**
     * Pembatalan: izin menunggu boleh dibatalkan siapa pun yang mengelola; izin disetujui hanya oleh yang berhak
     * menyetujui. Pembatalan izin disetujui mengembalikan hari-harinya ke belum_tercatat.
     *
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function cancel(School $school, User $actor, string $id, string $reason, bool $canApprove, ?array $classScope): array
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $id, $reason, $canApprove, $classScope) {
            $row = $this->lock($school, $id, $classScope);
            if (! in_array($row->status, ['pending', 'approved'], true)) {
                throw ApiProblem::invalidStateTransition('Izin ini sudah diputuskan atau dibatalkan.');
            }
            if ($row->status === 'approved' && ! $canApprove) {
                throw ApiProblem::permissionDenied();
            }

            DB::update(
                "UPDATE leave_requests SET status = 'cancelled', decided_by_user_id = ?, decided_at = now(), decision_note = ?, updated_at = now() WHERE id = ? AND school_id = ?",
                [$actor->id, $reason, $id, $school->id],
            );
            $reverted = $row->status === 'approved' ? DB::update(
                "UPDATE daily_attendance SET attendance_status = 'belum_tercatat', leave_request_id = NULL, version = version + 1, updated_at = now()
                 WHERE school_id = ? AND leave_request_id = ? AND check_in_event_id IS NULL",
                [$school->id, $id],
            ) : 0;
            $this->audit->record('school.leave.cancelled', $school->id, 'leave_request', $id, ['status' => $row->status], ['status' => 'cancelled'], $reason, $actor);

            return $this->find($school, $id) + ['reverted_days' => $reverted];
        }));
    }

    /** Menerapkan izin yang baru disetujui pada setiap tanggal yang sudah berjalan dalam rentangnya. */
    private function project(School $school, stdClass $row): int
    {
        $today = $this->today($school);
        $changed = 0;
        for ($day = Carbon::parse((string) $row->start_date); $day->toDateString() <= min((string) $row->end_date, $today); $day->addDay()) {
            $date = $day->toDateString();
            $this->closer->materialize($school, $date, [(string) $row->student_id]);
            $changed += $this->closer->applyLeave($school, $date, [(string) $row->student_id]);
        }

        return $changed;
    }

    private function today(School $school): string
    {
        return Carbon::now()->setTimezone($school->timezone)->toDateString();
    }

    /**
     * @param  list<string>|null  $classScope
     * @return array{string, list<string>}
     */
    private function scopeSql(?array $classScope): array
    {
        if ($classScope === null) {
            return ['', []];
        }

        return $classScope === [] ? [' AND false', []] : [' AND en.class_id IN ('.implode(',', array_fill(0, count($classScope), '?')).')', $classScope];
    }

    /** @param  list<string>|null  $classScope */
    private function assertStudentInScope(School $school, string $studentId, ?array $classScope): void
    {
        $exists = DB::selectOne("SELECT 1 AS x FROM students WHERE school_id = ? AND id = ? AND status = 'active'", [$school->id, $studentId]);
        if ($exists === null) {
            throw new NotFoundHttpException;
        }
        if ($classScope === null) {
            return;
        }
        $today = $this->today($school);
        [$scopeSql, $scopeArgs] = $this->scopeSql($classScope);
        $inScope = DB::selectOne(
            "SELECT 1 AS x FROM class_enrollments en WHERE en.school_id = ? AND en.student_id = ? AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?){$scopeSql}",
            [$school->id, $studentId, $today, $today, ...$scopeArgs],
        );
        if ($inScope === null) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Baris terkunci; 404 bila tidak ada atau di luar jangkauan kelas.
     *
     * @param  list<string>|null  $classScope
     */
    private function lock(School $school, string $id, ?array $classScope): stdClass
    {
        $row = DB::selectOne('SELECT * FROM leave_requests WHERE school_id = ? AND id = ? FOR UPDATE', [$school->id, $id]) ?? throw new NotFoundHttpException;
        if ($classScope !== null) {
            $this->assertStudentInScope($school, (string) $row->student_id, $classScope);
        }

        return $row;
    }

    /** @param  list<string>|null  $classScope */
    private function visible(School $school, string $id, ?array $classScope): stdClass
    {
        $row = DB::selectOne('SELECT * FROM leave_requests WHERE school_id = ? AND id = ?', [$school->id, $id]) ?? throw new NotFoundHttpException;
        if ($classScope !== null) {
            $this->assertStudentInScope($school, (string) $row->student_id, $classScope);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function find(School $school, string $id): array
    {
        $today = $this->today($school);
        $row = DB::selectOne(
            'SELECT l.*, s.full_name, s.student_number, c.name AS class_name
             FROM leave_requests l
             JOIN students s ON s.school_id = l.school_id AND s.id = l.student_id
             LEFT JOIN class_enrollments en ON en.school_id = l.school_id AND en.student_id = l.student_id AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?)
             LEFT JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE l.school_id = ? AND l.id = ?',
            [$today, $today, $school->id, $id],
        ) ?? throw new NotFoundHttpException;

        return $this->present($row);
    }

    /** @return array<string, mixed> */
    private function auditView(string $id): array
    {
        $r = DB::selectOne('SELECT student_id, leave_type, start_date, end_date, status FROM leave_requests WHERE id = ?', [$id]);

        return (array) $r;
    }

    /** @return array<string, mixed> */
    private function present(stdClass $r): array
    {
        return [
            'id' => (string) $r->id,
            'student_id' => (string) $r->student_id,
            'student_name' => (string) $r->full_name,
            'student_number' => (string) $r->student_number,
            'class_name' => $r->class_name === null ? null : (string) $r->class_name,
            'leave_type' => (string) $r->leave_type,
            'start_date' => (string) $r->start_date,
            'end_date' => (string) $r->end_date,
            'reason' => (string) $r->reason,
            'status' => (string) $r->status,
            'has_attachment' => $r->attachment_path !== null,
            'attachment_name' => $r->attachment_name === null ? null : (string) $r->attachment_name,
            'decision_note' => $r->decision_note === null ? null : (string) $r->decision_note,
            'created_at' => Carbon::parse((string) $r->created_at)->utc()->toIso8601String(),
        ];
    }
}
