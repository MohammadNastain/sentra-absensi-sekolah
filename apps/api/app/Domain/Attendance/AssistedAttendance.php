<?php

namespace App\Domain\Attendance;

use App\Models\Device;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pencarian minimal dan pencatatan bantuan, dipakai bersama oleh terminal (sesi bantuan) dan dashboard
 * (akun petugas yang sudah login). Tidak ada jalur yang membuat absensi dari nama tanpa petugas terautentikasi.
 */
final class AssistedAttendance
{
    public const REASONS = ['card_forgotten', 'card_damaged', 'early_leave', 'other'];

    public function __construct(private readonly RecordScanAction $scans) {}

    /**
     * Hasil minimal untuk siswa aktif dengan penempatan berjalan hari ini.
     *
     * @return list<array{id: string, full_name: string, student_number: string, class_name: string}>
     */
    public function search(School $school, string $query): array
    {
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $today = now($school->timezone)->toDateString();

        $rows = DB::select(
            "SELECT s.id, s.full_name, s.student_number, c.name AS class_name
             FROM students s
             JOIN class_enrollments en ON en.school_id = s.school_id AND en.student_id = s.id AND en.start_date <= ? AND (en.end_date IS NULL OR en.end_date >= ?)
             JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE s.school_id = ? AND s.status = 'active' AND (s.full_name ILIKE ? OR s.student_number ILIKE ?)
             ORDER BY s.full_name, s.id LIMIT 10",
            [$today, $today, $school->id, $like, $like],
        );

        return array_values(array_map(fn ($r) => [
            'id' => (string) $r->id,
            'full_name' => (string) $r->full_name,
            'student_number' => (string) $r->student_number,
            'class_name' => (string) $r->class_name,
        ], $rows));
    }

    /**
     * @param  array{student_id: string, reason_code: string, note?: string|null, event_type?: string|null, idempotency_key: string}  $data
     * @return array<string, mixed>
     */
    public function record(School $school, ?Device $device, User $actor, array $data): array
    {
        $student = Student::query()->where('school_id', $school->id)->where('id', $data['student_id'])->first()
            ?? throw new NotFoundHttpException;

        $reason = $data['reason_code'];
        $eventType = $data['event_type'] ?? ($reason === 'early_leave' ? 'check_out' : null);

        return $this->scans->recordAssisted($school, $device, $actor, $student, $reason, isset($data['note']) ? trim($data['note']) : null, $eventType, $data['idempotency_key']);
    }
}
