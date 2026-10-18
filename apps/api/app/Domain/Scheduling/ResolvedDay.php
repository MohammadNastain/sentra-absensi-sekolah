<?php

namespace App\Domain\Scheduling;

use App\Models\Holiday;
use App\Models\Schedule;

/** Hasil resolusi satu tanggal: libur (tanpa sesi), jadwal yang berlaku, atau tidak ada keduanya. */
final readonly class ResolvedDay
{
    public function __construct(
        public string $date,
        public int $dayOfWeek,
        public ?Holiday $holiday,
        public ?Schedule $schedule,
    ) {}
}
