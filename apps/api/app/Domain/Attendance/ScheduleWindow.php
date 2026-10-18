<?php

namespace App\Domain\Attendance;

use App\Models\Schedule;

/**
 * Jam-jam jadwal sebagai detik sejak tengah malam (waktu lokal sekolah), agar mesin keadaan murni dan mudah
 * diuji dengan tabel batas ±1 menit.
 */
final readonly class ScheduleWindow
{
    public function __construct(
        public int $checkInStart,
        public int $checkInDeadline,
        public int $graceSeconds,
        public int $checkOutStart,
        public int $scheduledCheckOut,
    ) {}

    public static function fromSchedule(Schedule $schedule): self
    {
        return new self(
            self::seconds((string) $schedule->check_in_start),
            self::seconds((string) $schedule->check_in_deadline),
            $schedule->late_grace_minutes * 60,
            self::seconds((string) $schedule->check_out_start),
            self::seconds((string) $schedule->scheduled_check_out),
        );
    }

    /** 'HH:MM' atau 'HH:MM:SS' → detik sejak tengah malam. */
    public static function seconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return ($parts[0] * 3600) + (($parts[1] ?? 0) * 60) + ($parts[2] ?? 0);
    }
}
