<?php

namespace App\Console\Commands;

use App\Domain\Attendance\CloseAttendanceDay;
use Illuminate\Console\Command;

final class CloseAttendanceDayCommand extends Command
{
    protected $signature = 'attendance:close-day {--date= : Tanggal Y-m-d (default: hari ini menurut zona waktu tiap sekolah)}';

    protected $description = 'Tutup hari absensi: materialisasi belum_tercatat dan terapkan aturan alpa sementara (ADR 0005 §5)';

    public function handle(CloseAttendanceDay $close): int
    {
        $date = $this->option('date');
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) !== 1) {
            $this->error('Format --date harus Y-m-d.');

            return self::INVALID;
        }

        foreach ($close->runForAllSchools($date === null ? null : (string) $date) as $code => $r) {
            $this->line("{$code}: wajib hadir {$r['required']}, ditandai alpa sementara {$r['marked_absent']}, kesehatan hari: ".($r['health'] ?? '-'));
        }

        return self::SUCCESS;
    }
}
