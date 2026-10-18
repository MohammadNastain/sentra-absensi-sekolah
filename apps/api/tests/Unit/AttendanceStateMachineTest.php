<?php

use App\Domain\Attendance\AttendanceStateMachine;
use App\Domain\Attendance\ScheduleWindow;

// Tahap 3C — tabel keputusan mesin keadaan, termasuk batas ±1 menit.

function win(): ScheduleWindow
{
    // masuk 06:00, batas 07:00 + toleransi 5 menit, pulang dibuka 13:00, jadwal pulang 14:00
    return new ScheduleWindow(6 * 3600, 7 * 3600, 300, 13 * 3600, 14 * 3600);
}

function at(string $hm): int
{
    return ScheduleWindow::seconds($hm);
}

it('belum check-in: terlalu awal, tepat waktu, terlambat, dan batas toleransi', function (string $time, string $result, string $code) {
    $d = (new AttendanceStateMachine)->decide(win(), at($time), null, false);
    expect([$d->result, $d->code])->toBe([$result, $code]);
})->with([
    ['05:59', 'rejected', 'TOO_EARLY'],
    ['06:00', 'accepted', 'CHECK_IN_OK'],
    ['07:00', 'accepted', 'CHECK_IN_OK'],
    ['07:05', 'accepted', 'CHECK_IN_OK'],
    ['07:06', 'accepted', 'CHECK_IN_LATE'],
    ['12:59', 'accepted', 'CHECK_IN_LATE'],
    ['13:00', 'needs_review', 'CHECKOUT_WITHOUT_CHECKIN'],
    ['18:00', 'needs_review', 'CHECKOUT_WITHOUT_CHECKIN'],
]);

it('menghitung menit terlambat dari batas jadwal', function () {
    $d = (new AttendanceStateMachine)->decide(win(), at('07:20'), null, false);
    expect($d->isLate)->toBeTrue()->and($d->lateMinutes)->toBe(20);
    expect((new AttendanceStateMachine)->decide(win(), at('06:30'), null, false)->lateMinutes)->toBe(0);
});

it('sudah check-in: duplikat sebelum jendela pulang, pulang awal dan pulang normal', function (string $time, string $result, string $code) {
    $d = (new AttendanceStateMachine)->decide(win(), at($time), at('06:30'), false);
    expect([$d->result, $d->code])->toBe([$result, $code]);
})->with([
    ['06:31', 'duplicate', 'ALREADY_CHECKED_IN'],
    ['12:59', 'duplicate', 'ALREADY_CHECKED_IN'],
    ['13:00', 'accepted', 'CHECK_OUT_EARLY'],
    ['13:59', 'accepted', 'CHECK_OUT_EARLY'],
    ['14:00', 'accepted', 'CHECK_OUT_OK'],
    ['16:00', 'accepted', 'CHECK_OUT_OK'],
]);

it('check-in tepat sebelum jendela pulang tidak langsung menjadi check-out (jeda minimum)', function () {
    $d = (new AttendanceStateMachine)->decide(win(), at('13:01'), at('13:00') - 1, false);
    expect($d->code)->toBe('ALREADY_CHECKED_IN');
});

it('sudah check-out: selalu duplikat', function () {
    $d = (new AttendanceStateMachine)->decide(win(), at('15:00'), at('06:30'), true);
    expect([$d->result, $d->code])->toBe(['duplicate', 'ALREADY_CHECKED_OUT']);
});

it('hanya accepted yang menandai pulang awal', function () {
    expect((new AttendanceStateMachine)->decide(win(), at('13:30'), at('06:30'), false)->isEarlyLeave)->toBeTrue()
        ->and((new AttendanceStateMachine)->decide(win(), at('14:30'), at('06:30'), false)->isEarlyLeave)->toBeFalse();
});
