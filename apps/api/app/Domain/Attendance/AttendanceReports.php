<?php

namespace App\Domain\Attendance;

use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Laporan kehadiran dari proyeksi `daily_attendance` (Tahap 5D). Satu-satunya tempat rumus laporan ditulis:
 *
 * - Hari efektif = jumlah baris harian pada rentang (hari sekolah yang termaterialisasi).
 * - Persentase kehadiran (D-14) = (hadir + terlambat) / (hari efektif − izin − sakit − dispensasi), 2 desimal;
 *   penyebut nol → null. Komponen mentah selalu ikut ditampilkan.
 * - Hanya `alpa` final yang dihitung sebagai alpa resmi; `alpa_belum_diverifikasi` dan `belum_tercatat` dilaporkan
 *   terpisah dan tidak masuk persentase sebagai ketidakhadiran maupun pengecualian (ADR 0005 §5.7).
 * - Pengelompokan kelas memakai snapshot `daily_attendance.class_id`, sehingga siswa pindah kelas tetap benar.
 *
 * Wali kelas dibatasi lewat `$classScope` (null = semua kelas).
 */
final class AttendanceReports
{
    public const MAX_RANGE_DAYS = 366;

    private const COUNTS = "count(*) AS days,
        count(*) FILTER (WHERE d.attendance_status = 'hadir') AS hadir,
        count(*) FILTER (WHERE d.attendance_status = 'terlambat') AS terlambat,
        count(*) FILTER (WHERE d.attendance_status = 'izin') AS izin,
        count(*) FILTER (WHERE d.attendance_status = 'sakit') AS sakit,
        count(*) FILTER (WHERE d.attendance_status = 'dispensasi') AS dispensasi,
        count(*) FILTER (WHERE d.attendance_status = 'alpa') AS alpa,
        count(*) FILTER (WHERE d.attendance_status = 'alpa_belum_diverifikasi') AS alpa_belum_diverifikasi,
        count(*) FILTER (WHERE d.attendance_status = 'belum_tercatat') AS belum_tercatat,
        coalesce(sum(d.late_minutes) FILTER (WHERE d.attendance_status = 'terlambat'), 0) AS late_minutes,
        count(*) FILTER (WHERE d.early_leave) AS early_total,
        count(*) FILTER (WHERE d.early_leave AND d.early_leave_status = 'approved') AS early_approved,
        count(*) FILTER (WHERE d.early_leave AND d.early_leave_status = 'pending') AS early_pending,
        count(*) FILTER (WHERE d.early_leave AND d.early_leave_status = 'rejected') AS early_rejected";

    /**
     * Rekap per kelas dan total pada rentang.
     *
     * @param  list<string>|null  $classScope
     * @return array{from: string, to: string, totals: array<string, mixed>, classes: list<array<string, mixed>>}
     */
    public function summary(School $school, string $from, string $to, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        [$where, $args] = $this->filters($school, $from, $to, $classId, $gradeLevel, $classScope);

        $rows = DB::select(
            'SELECT c.id AS class_id, c.name AS class_name, c.grade_level, '.self::COUNTS."
             FROM daily_attendance d JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE {$where} GROUP BY c.id, c.name, c.grade_level ORDER BY c.grade_level, c.name",
            $args,
        );
        $total = DB::selectOne('SELECT '.self::COUNTS." FROM daily_attendance d JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id WHERE {$where}", $args);

        return [
            'from' => $from,
            'to' => $to,
            'totals' => $this->metrics($total),
            'classes' => array_values(array_map(fn ($r) => ['class_id' => (string) $r->class_id, 'class_name' => (string) $r->class_name, 'grade_level' => (int) $r->grade_level] + $this->metrics($r), $rows)),
        ];
    }

    /**
     * Tren harian: satu titik per tanggal sekolah pada rentang (untuk grafik dashboard).
     *
     * @param  list<string>|null  $classScope
     * @return list<array<string, mixed>>
     */
    public function trend(School $school, string $from, string $to, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        [$where, $args] = $this->filters($school, $from, $to, $classId, $gradeLevel, $classScope);

        $rows = DB::select(
            'SELECT d.attendance_date AS date, '.self::COUNTS."
             FROM daily_attendance d JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE {$where} GROUP BY d.attendance_date ORDER BY d.attendance_date",
            $args,
        );

        return array_values(array_map(fn ($r) => ['date' => (string) $r->date] + $this->metrics($r), $rows));
    }

    /**
     * Rekap per siswa (satu kelas atau semua kelas yang boleh dilihat), diurutkan nama.
     *
     * @param  list<string>|null  $classScope
     * @return list<array<string, mixed>>
     */
    public function students(School $school, string $from, string $to, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        [$where, $args] = $this->filters($school, $from, $to, $classId, $gradeLevel, $classScope);

        $rows = DB::select(
            'SELECT s.id AS student_id, s.full_name, s.student_number, '.self::COUNTS."
             FROM daily_attendance d
             JOIN students s ON s.school_id = d.school_id AND s.id = d.student_id
             JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE {$where} GROUP BY s.id, s.full_name, s.student_number ORDER BY s.full_name, s.id LIMIT 5000",
            $args,
        );

        return array_values(array_map(fn ($r) => ['student_id' => (string) $r->student_id, 'student_name' => (string) $r->full_name, 'student_number' => (string) $r->student_number] + $this->metrics($r), $rows));
    }

    /**
     * Keterlambatan: siswa dengan status terlambat pada rentang, terbanyak dulu.
     *
     * @param  list<string>|null  $classScope
     * @return list<array<string, mixed>>
     */
    public function lateness(School $school, string $from, string $to, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        [$where, $args] = $this->filters($school, $from, $to, $classId, $gradeLevel, $classScope);

        $rows = DB::select(
            "SELECT s.id AS student_id, s.full_name, s.student_number, string_agg(DISTINCT c.name, ', ') AS class_names,
                    count(*) AS late_days, coalesce(sum(d.late_minutes), 0) AS late_minutes
             FROM daily_attendance d
             JOIN students s ON s.school_id = d.school_id AND s.id = d.student_id
             JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE {$where} AND d.attendance_status = 'terlambat'
             GROUP BY s.id, s.full_name, s.student_number ORDER BY late_days DESC, late_minutes DESC, s.full_name LIMIT 500",
            $args,
        );

        return array_values(array_map(fn ($r) => [
            'student_id' => (string) $r->student_id, 'student_name' => (string) $r->full_name, 'student_number' => (string) $r->student_number,
            'class_name' => (string) $r->class_names, 'late_days' => (int) $r->late_days, 'late_minutes' => (int) $r->late_minutes,
        ], $rows));
    }

    /**
     * Laporan harian: satu tanggal, satu baris per siswa wajib hadir.
     *
     * @param  list<string>|null  $classScope
     * @return list<array<string, mixed>>
     */
    public function daily(School $school, string $date, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        [$where, $args] = $this->filters($school, $date, $date, $classId, $gradeLevel, $classScope);

        $rows = DB::select(
            "SELECT s.full_name, s.student_number, c.name AS class_name, d.attendance_status, d.late_minutes, d.early_leave, d.early_leave_status, d.source,
                    COALESCE(d.check_in_time_override, ci.occurred_at_server) AS check_in_at, COALESCE(d.check_out_time_override, co.occurred_at_server) AS check_out_at
             FROM daily_attendance d
             JOIN students s ON s.school_id = d.school_id AND s.id = d.student_id
             JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             LEFT JOIN attendance_events ci ON ci.school_id = d.school_id AND ci.id = d.check_in_event_id
             LEFT JOIN attendance_events co ON co.school_id = d.school_id AND co.id = d.check_out_event_id
             WHERE {$where} ORDER BY c.grade_level, c.name, s.full_name, s.id LIMIT 5000",
            $args,
        );

        return array_values(array_map(fn ($r) => [
            'student_name' => (string) $r->full_name,
            'student_number' => (string) $r->student_number,
            'class_name' => (string) $r->class_name,
            'status' => (string) $r->attendance_status,
            'check_in' => $this->local($r->check_in_at, $school),
            'check_out' => $this->local($r->check_out_at, $school),
            'late_minutes' => $r->late_minutes === null ? null : (int) $r->late_minutes,
            'early_leave' => (bool) $r->early_leave,
            'early_leave_status' => $r->early_leave_status,
            'corrected' => $r->source === 'manual_adjustment',
        ], $rows));
    }

    /**
     * Laporan bulanan satu kelas: matriks siswa x tanggal berisi status (null = bukan hari sekolah bagi kelas itu).
     *
     * @param  list<string>|null  $classScope
     * @return array{month: string, days: list<string>, students: list<array<string, mixed>>}
     */
    public function monthly(School $school, string $month, string $classId, ?array $classScope): array
    {
        $first = Carbon::parse("{$month}-01");
        $from = $first->toDateString();
        $to = $first->copy()->endOfMonth()->toDateString();
        [$where, $args] = $this->filters($school, $from, $to, $classId, null, $classScope);

        $rows = DB::select(
            "SELECT s.id AS student_id, s.full_name, s.student_number, d.attendance_date, d.attendance_status
             FROM daily_attendance d
             JOIN students s ON s.school_id = d.school_id AND s.id = d.student_id
             JOIN classes c ON c.school_id = d.school_id AND c.id = d.class_id
             WHERE {$where} ORDER BY s.full_name, s.id, d.attendance_date LIMIT 20000",
            $args,
        );

        $days = [];
        $students = [];
        foreach ($rows as $r) {
            $days[(string) $r->attendance_date] = true;
            $students[(string) $r->student_id] ??= ['student_id' => (string) $r->student_id, 'student_name' => (string) $r->full_name, 'student_number' => (string) $r->student_number, 'statuses' => []];
            $students[(string) $r->student_id]['statuses'][(string) $r->attendance_date] = (string) $r->attendance_status;
        }
        $dayList = array_keys($days);
        sort($dayList);

        return [
            'month' => $month,
            'days' => $dayList,
            'students' => array_values(array_map(function (array $s) use ($dayList) {
                $s['statuses'] = array_map(fn (string $day) => $s['statuses'][$day] ?? null, $dayList);

                return $s;
            }, $students)),
        ];
    }

    /**
     * Event yang tidak diterima (duplikat, ditolak, perlu tinjauan) pada rentang tanggal.
     *
     * @return list<array<string, mixed>>
     */
    public function rejectedEvents(School $school, string $from, string $to, ?string $result): array
    {
        $rows = DB::select(
            "SELECT e.occurred_at_server, e.result, e.reason_code, e.method, e.event_type, s.full_name, d.name AS device_name
             FROM attendance_events e
             LEFT JOIN students s ON s.school_id = e.school_id AND s.id = e.student_id
             LEFT JOIN devices d ON d.school_id = e.school_id AND d.id = e.device_id
             WHERE e.school_id = ? AND e.attendance_date BETWEEN ? AND ? AND e.result <> 'accepted'".($result === null ? '' : ' AND e.result = ?').'
             ORDER BY e.occurred_at_server DESC, e.id DESC LIMIT 500',
            [$school->id, $from, $to, ...($result === null ? [] : [$result])],
        );

        return array_values(array_map(fn ($r) => [
            'occurred_at' => $this->local($r->occurred_at_server, $school, 'Y-m-d H:i'),
            'result' => (string) $r->result,
            'reason_code' => $r->reason_code,
            'method' => (string) $r->method,
            'event_type' => $r->event_type,
            'student_name' => $r->full_name,
            'device_name' => $r->device_name,
        ], $rows));
    }

    /**
     * @param  list<string>|null  $classScope
     * @return array{string, list<mixed>}
     */
    private function filters(School $school, string $from, string $to, ?string $classId, ?int $gradeLevel, ?array $classScope): array
    {
        $where = 'd.school_id = ? AND d.attendance_date BETWEEN ? AND ?';
        $args = [$school->id, $from, $to];
        if ($classId !== null) {
            $where .= ' AND d.class_id = ?';
            $args[] = $classId;
        }
        if ($gradeLevel !== null) {
            $where .= ' AND c.grade_level = ?';
            $args[] = $gradeLevel;
        }
        if ($classScope !== null) {
            $where .= $classScope === [] ? ' AND false' : ' AND d.class_id IN ('.implode(',', array_fill(0, count($classScope), '?')).')';
            array_push($args, ...$classScope);
        }

        return [$where, $args];
    }

    /** @return array<string, mixed> */
    private function metrics(?stdClass $r): array
    {
        $hadir = (int) ($r->hadir ?? 0);
        $terlambat = (int) ($r->terlambat ?? 0);
        $izin = (int) ($r->izin ?? 0);
        $sakit = (int) ($r->sakit ?? 0);
        $dispensasi = (int) ($r->dispensasi ?? 0);
        $days = (int) ($r->days ?? 0);
        $present = $hadir + $terlambat;
        $excluded = $izin + $sakit + $dispensasi;
        $denominator = $days - $excluded;

        return [
            'effective_days' => $days,
            'hadir' => $hadir,
            'terlambat' => $terlambat,
            'izin' => $izin,
            'sakit' => $sakit,
            'dispensasi' => $dispensasi,
            'alpa' => (int) ($r->alpa ?? 0),
            'alpa_belum_diverifikasi' => (int) ($r->alpa_belum_diverifikasi ?? 0),
            'belum_tercatat' => (int) ($r->belum_tercatat ?? 0),
            'present' => $present,
            'excluded' => $excluded,
            'denominator' => $denominator,
            'attendance_percentage' => $denominator > 0 ? round($present * 100 / $denominator, 2) : null,
            'late_minutes' => (int) ($r->late_minutes ?? 0),
            'early_leave' => [
                'total' => (int) ($r->early_total ?? 0),
                'approved' => (int) ($r->early_approved ?? 0),
                'pending' => (int) ($r->early_pending ?? 0),
                'rejected' => (int) ($r->early_rejected ?? 0),
            ],
        ];
    }

    private function local(mixed $value, School $school, string $format = 'H.i'): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->setTimezone($school->timezone)->format($format);
    }
}
