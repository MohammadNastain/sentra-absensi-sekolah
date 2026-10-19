<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Jobs\GenerateReportExport;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ekspor laporan kehadiran (Tahap 5D). Permintaan dicatat dan diaudit, file dibuat job di antrean, hasil disimpan
 * privat dan diunduh lewat URL bertanda tangan berumur pendek oleh peminta saja. Otorisasi mengikuti laporan yang
 * diekspor: batas kelas wali kelas dibekukan saat diminta. Sel berawalan = + - @ diberi apostrof (injeksi CSV/XLSX).
 */
final class ManageExports
{
    public const REPORTS = ['summary', 'students', 'lateness', 'daily', 'monthly', 'events'];

    public const FORMATS = ['csv', 'xlsx'];

    public const RETENTION_DAYS = 7;

    public const URL_MINUTES = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttendanceReports $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  sudah divalidasi pemanggil
     * @param  list<string>|null  $classScope
     * @return array<string, mixed>
     */
    public function request(School $school, User $actor, string $report, string $format, array $filters, ?array $classScope): array
    {
        $id = (string) DB::scalar(
            'INSERT INTO export_jobs (school_id, requested_by_user_id, report, format, filters, class_scope, expires_at) VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?) RETURNING id',
            [$school->id, $actor->id, $report, $format, json_encode($filters), $classScope === null ? null : json_encode($classScope), now()->addDays(self::RETENTION_DAYS)],
        );
        $this->audit->record('school.export.requested', $school->id, 'export_job', $id, null, ['report' => $report, 'format' => $format], null, $actor);
        GenerateReportExport::dispatch($school->id, $id)->afterCommit();

        return $this->present($this->row($school, $actor, $id));
    }

    /** @return list<array<string, mixed>> */
    public function list(School $school, User $actor): array
    {
        $rows = DB::select('SELECT * FROM export_jobs WHERE school_id = ? AND requested_by_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50', [$school->id, $actor->id]);

        return array_values(array_map(fn ($r) => $this->present($r), $rows));
    }

    /** @return array<string, mixed> */
    public function show(School $school, User $actor, string $id): array
    {
        return $this->present($this->row($school, $actor, $id));
    }

    /**
     * Jalur file untuk diunduh; hanya peminta dan hanya sebelum kedaluwarsa.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function download(School $school, User $actor, string $id): array
    {
        $row = $this->row($school, $actor, $id);
        if ($row->status !== 'done' || $row->file_path === null || Carbon::parse((string) $row->expires_at)->isPast() || ! Storage::disk('local')->exists((string) $row->file_path)) {
            throw new NotFoundHttpException('Berkas ekspor tidak tersedia.');
        }
        $this->audit->record('school.export.downloaded', $school->id, 'export_job', $id, null, ['report' => $row->report, 'format' => $row->format], null, $actor);

        return [
            'path' => Storage::disk('local')->path((string) $row->file_path),
            'name' => "laporan-{$row->report}-".Carbon::parse((string) $row->created_at)->format('Ymd-His').".{$row->format}",
            'mime' => $row->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /** Dipanggil job: membuat file, mencatat hasil atau kegagalan. */
    public function generate(string $schoolId, string $id): void
    {
        $job = DB::selectOne('SELECT * FROM export_jobs WHERE school_id = ? AND id = ?', [$schoolId, $id]);
        if ($job === null || $job->status !== 'queued') {
            return;
        }
        DB::update("UPDATE export_jobs SET status = 'running', updated_at = now() WHERE school_id = ? AND id = ?", [$schoolId, $id]);

        $path = "exports/{$schoolId}/{$id}.{$job->format}";
        try {
            $school = School::query()->findOrFail($schoolId);
            $table = $this->table($school, (string) $job->report, json_decode((string) $job->filters, true) ?: [], $job->class_scope === null ? null : json_decode((string) $job->class_scope, true));
            Storage::disk('local')->makeDirectory("exports/{$schoolId}");
            $count = $this->write(Storage::disk('local')->path($path), (string) $job->format, $table);
            DB::update(
                "UPDATE export_jobs SET status = 'done', file_path = ?, file_size = ?, row_count = ?, finished_at = now(), updated_at = now() WHERE school_id = ? AND id = ?",
                [$path, Storage::disk('local')->size($path), $count, $schoolId, $id],
            );
        } catch (\Throwable $e) {
            report($e);
            Storage::disk('local')->delete($path);
            DB::update("UPDATE export_jobs SET status = 'failed', error_message = ?, finished_at = now(), updated_at = now() WHERE school_id = ? AND id = ?", ['Ekspor gagal dibuat. Coba lagi.', $schoolId, $id]);
        }
    }

    /** Menghapus berkas kedaluwarsa; baris tetap sebagai jejak. Mengembalikan jumlah berkas terhapus. */
    public function purgeExpired(string $schoolId): int
    {
        $rows = DB::select('SELECT id, file_path FROM export_jobs WHERE school_id = ? AND file_path IS NOT NULL AND expires_at < now()', [$schoolId]);
        foreach ($rows as $r) {
            Storage::disk('local')->delete((string) $r->file_path);
            DB::update('UPDATE export_jobs SET file_path = NULL, updated_at = now() WHERE school_id = ? AND id = ?', [$schoolId, $r->id]);
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<string>|null  $scope
     * @return array{headers: list<string>, rows: list<array<mixed>>}
     */
    private function table(School $school, string $report, array $f, ?array $scope): array
    {
        $class = $f['class_id'] ?? null;
        $grade = isset($f['grade_level']) ? (int) $f['grade_level'] : null;
        $metricHeaders = ['Hari efektif', 'Hadir', 'Terlambat', 'Izin', 'Sakit', 'Dispensasi', 'Alpa', 'Alpa belum diverifikasi', 'Belum tercatat', 'Persentase kehadiran (%)', 'Menit terlambat', 'Pulang awal disetujui', 'Pulang awal menunggu', 'Pulang awal ditolak'];
        $metrics = fn (array $m) => [$m['effective_days'], $m['hadir'], $m['terlambat'], $m['izin'], $m['sakit'], $m['dispensasi'], $m['alpa'], $m['alpa_belum_diverifikasi'], $m['belum_tercatat'], $m['attendance_percentage'], $m['late_minutes'], $m['early_leave']['approved'], $m['early_leave']['pending'], $m['early_leave']['rejected']];

        switch ($report) {
            case 'summary':
                $data = $this->reports->summary($school, $f['from'], $f['to'], $class, $grade, $scope);
                $rows = array_map(fn ($c) => [$c['class_name'], ...$metrics($c)], $data['classes']);
                $rows[] = ['Total', ...$metrics($data['totals'])];

                return ['headers' => ['Kelas', ...$metricHeaders], 'rows' => $rows];
            case 'students':
                $rows = array_map(fn ($s) => [$s['student_name'], $s['student_number'], ...$metrics($s)], $this->reports->students($school, $f['from'], $f['to'], $class, $grade, $scope));

                return ['headers' => ['Nama', 'Nomor induk', ...$metricHeaders], 'rows' => $rows];
            case 'lateness':
                $rows = array_map(fn ($r) => [$r['student_name'], $r['student_number'], $r['class_name'], $r['late_days'], $r['late_minutes']], $this->reports->lateness($school, $f['from'], $f['to'], $class, $grade, $scope));

                return ['headers' => ['Nama', 'Nomor induk', 'Kelas', 'Hari terlambat', 'Total menit terlambat'], 'rows' => $rows];
            case 'daily':
                $rows = array_map(fn ($r) => [$r['class_name'], $r['student_name'], $r['student_number'], $r['status'], $r['check_in'], $r['check_out'], $r['late_minutes'], $r['early_leave'] ? ($r['early_leave_status'] ?? 'ya') : '', $r['corrected'] ? 'ya' : ''], $this->reports->daily($school, $f['date'], $class, $grade, $scope));

                return ['headers' => ['Kelas', 'Nama', 'Nomor induk', 'Status', 'Jam masuk', 'Jam pulang', 'Menit terlambat', 'Pulang awal', 'Dikoreksi'], 'rows' => $rows];
            case 'monthly':
                $data = $this->reports->monthly($school, $f['month'], (string) $class, $scope);
                $rows = array_map(fn ($s) => [$s['student_name'], $s['student_number'], ...array_map(fn ($v) => $v ?? '', $s['statuses'])], $data['students']);

                return ['headers' => ['Nama', 'Nomor induk', ...$data['days']], 'rows' => $rows];
            default:
                $rows = array_map(fn ($r) => [$r['occurred_at'], $r['result'], $r['reason_code'], $r['method'], $r['event_type'], $r['student_name'], $r['device_name']], $this->reports->rejectedEvents($school, $f['from'], $f['to'], $f['result'] ?? null));

                return ['headers' => ['Waktu', 'Hasil', 'Kode alasan', 'Metode', 'Jenis', 'Siswa', 'Perangkat'], 'rows' => $rows];
        }
    }

    /**
     * @param  array{headers: list<string>, rows: list<array<mixed>>}  $table
     */
    private function write(string $path, string $format, array $table): int
    {
        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map($this->safe(...), $table['headers'])));
        foreach ($table['rows'] as $row) {
            $writer->addRow(Row::fromValues(array_map($this->safe(...), $row)));
        }
        $writer->close();

        return count($table['rows']);
    }

    private function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function row(School $school, User $actor, string $id): stdClass
    {
        if (! Str::isUuid($id)) {
            throw new NotFoundHttpException;
        }
        $row = DB::selectOne('SELECT * FROM export_jobs WHERE school_id = ? AND id = ? AND requested_by_user_id = ?', [$school->id, $id, $actor->id]);

        return $row ?? throw new NotFoundHttpException;
    }

    /** @return array<string, mixed> */
    private function present(stdClass $r): array
    {
        $ready = $r->status === 'done' && $r->file_path !== null && Carbon::parse((string) $r->expires_at)->isFuture();

        return [
            'id' => (string) $r->id,
            'report' => (string) $r->report,
            'format' => (string) $r->format,
            'status' => (string) $r->status,
            'row_count' => $r->row_count === null ? null : (int) $r->row_count,
            'file_size' => $r->file_size === null ? null : (int) $r->file_size,
            'error_message' => $r->error_message,
            'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
            'expires_at' => Carbon::parse((string) $r->expires_at)->toIso8601String(),
            'download_url' => $ready ? URL::temporarySignedRoute('schools.exports.download', now()->addMinutes(self::URL_MINUTES), ['school' => $this->schoolCode((string) $r->school_id), 'export' => (string) $r->id]) : null,
        ];
    }

    private function schoolCode(string $schoolId): string
    {
        return (string) DB::scalar('SELECT code FROM schools WHERE id = ?', [$schoolId]);
    }
}
