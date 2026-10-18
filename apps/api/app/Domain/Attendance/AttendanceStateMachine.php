<?php

namespace App\Domain\Attendance;

/**
 * Mesin keadaan check-in/check-out (ARCHITECTURE §8.1, ADR 0005). Fungsi murni: tidak membaca database maupun
 * jam; satu-satunya tempat aturan ini ditulis (jalur scan, mode bantuan, dan penghitungan ulang memakainya).
 *
 * | Keadaan                         | Waktu scan                     | Hasil                                      |
 * | belum ada check-in              | < IN_START                     | rejected TOO_EARLY                         |
 * | belum ada check-in              | IN_START … < OUT_START         | accepted check_in (terlambat bila > batas+toleransi) |
 * | belum ada check-in              | ≥ OUT_START                    | needs_review (pulang tanpa catatan masuk)  |
 * | sudah check-in, belum check-out | < OUT_START atau < masuk+jeda  | duplicate ALREADY_CHECKED_IN               |
 * | sudah check-in, belum check-out | ≥ OUT_START                    | accepted check_out (pulang awal bila < OUT_SCHED) |
 * | sudah check-out                 | kapan pun                      | duplicate ALREADY_CHECKED_OUT              |
 *
 * Scan ulang tidak pernah menjadi check-out sebelum jendela pulang dibuka.
 */
final class AttendanceStateMachine
{
    /** Jeda minimum antara check-in dan check-out (detik). */
    public const MIN_GAP_SECONDS = 300;

    /**
     * @param  int  $now  detik sejak tengah malam, waktu lokal sekolah
     * @param  int|null  $checkInAt  detik sejak tengah malam saat check-in tercatat, null bila belum ada
     */
    public function decide(ScheduleWindow $window, int $now, ?int $checkInAt, bool $hasCheckOut): ScanDecision
    {
        if ($hasCheckOut) {
            return new ScanDecision('duplicate', 'ALREADY_CHECKED_OUT', 'check_out');
        }

        if ($checkInAt !== null) {
            if ($now < $window->checkOutStart || $now < $checkInAt + self::MIN_GAP_SECONDS) {
                return new ScanDecision('duplicate', 'ALREADY_CHECKED_IN', 'check_in');
            }
            $early = $now < $window->scheduledCheckOut;

            return new ScanDecision('accepted', $early ? 'CHECK_OUT_EARLY' : 'CHECK_OUT_OK', 'check_out', isEarlyLeave: $early);
        }

        if ($now < $window->checkInStart) {
            return new ScanDecision('rejected', 'TOO_EARLY', null);
        }
        if ($now >= $window->checkOutStart) {
            return new ScanDecision('needs_review', 'CHECKOUT_WITHOUT_CHECKIN', 'check_out');
        }

        $late = $now > $window->checkInDeadline + $window->graceSeconds;

        return new ScanDecision(
            'accepted',
            $late ? 'CHECK_IN_LATE' : 'CHECK_IN_OK',
            'check_in',
            isLate: $late,
            lateMinutes: $late ? intdiv($now - $window->checkInDeadline, 60) : 0,
        );
    }

    /**
     * Pencatatan oleh petugas (mode bantuan). Pulang boleh dicatat kapan pun setelah ada catatan masuk (pulang awal
     * sebelum jendela pulang dibuka, D-09) dan ditandai pulang awal bila sebelum jadwal pulang. Masuk mengikuti
     * aturan scan biasa. `$requested`: 'check_in' | 'check_out' | null (otomatis).
     */
    public function decideAssisted(ScheduleWindow $window, int $now, ?int $checkInAt, bool $hasCheckOut, ?string $requested): ScanDecision
    {
        if ($hasCheckOut) {
            return new ScanDecision('duplicate', 'ALREADY_CHECKED_OUT', 'check_out');
        }

        $wantsOut = $requested === 'check_out';
        if ($wantsOut) {
            if ($checkInAt === null) {
                return new ScanDecision('rejected', 'CHECKIN_REQUIRED', null);
            }
            $early = $now < $window->scheduledCheckOut;

            return new ScanDecision('accepted', $early ? 'CHECK_OUT_EARLY' : 'CHECK_OUT_OK', 'check_out', isEarlyLeave: $early);
        }

        if ($checkInAt !== null) {
            return new ScanDecision('duplicate', 'ALREADY_CHECKED_IN', 'check_in');
        }

        return $this->decide($window, $now, null, false);
    }
}
