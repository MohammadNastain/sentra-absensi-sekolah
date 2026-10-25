<?php

use Illuminate\Support\Carbon;

require_once __DIR__.'/../Attendance/ScanHelpers.php';

// ADR 0009 §5 — penegakan entitlement di terminal (guard device).

beforeEach(fn () => scanSetUp()->call($this));

afterEach(fn () => Carbon::setTestNow());

it('terminal: metode scan yang tidak dimiliki ditolak, QR tetap jalan, config hanya menyebut metode yang dimiliki', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 06:30:00', 'Asia/Jakarta'));
    setFeatureOverride($this->school->id, 'attendance.scan_rfid', 'revoke');

    scanCall('GET', '/config', [], $this->deviceToken)->assertOk()->assertJsonPath('data.methods', ['qr']);
    scan('04A1B2C3')->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED')->assertJsonPath('feature', 'attendance.scan_rfid');
    scan($this->token)->assertOk()->assertJsonPath('data.result', 'accepted');

    setFeatureOverride($this->school->id, 'attendance.scan_qr', 'revoke');
    scan($this->token)->assertForbidden()->assertJsonPath('feature', 'attendance.scan_qr');
    // Nilai yang bukan QR maupun RFID tetap ditolak sebagai kredensial tak dikenal, bukan sebagai fitur.
    scan('bukan-kredensial!')->assertOk()->assertJsonPath('data.code', 'UNKNOWN_CREDENTIAL');
});

it('terminal: tanpa modul absensi seluruh terminal ditolak, termasuk heartbeat dan mode bantuan', function () {
    setFeatureOverride($this->school->id, 'attendance', 'revoke');

    scanCall('GET', '/config', [], $this->deviceToken)->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
    scanCall('POST', '/heartbeat', [], $this->deviceToken)->assertForbidden();
    scanCall('GET', '/assist/students', [], $this->deviceToken)->assertForbidden();
    scan($this->token)->assertForbidden();
});
