<?php

namespace App\Domain\Attendance;

/**
 * Keputusan mesin keadaan untuk satu scan. `result` salah satu accepted|duplicate|rejected|needs_review;
 * `code` stabil (reason_code). Hanya `accepted` yang mengubah proyeksi harian.
 */
final readonly class ScanDecision
{
    public function __construct(
        public string $result,
        public string $code,
        public ?string $eventType,
        public ?bool $isLate = null,
        public ?int $lateMinutes = null,
        public ?bool $isEarlyLeave = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->result === 'accepted';
    }
}
