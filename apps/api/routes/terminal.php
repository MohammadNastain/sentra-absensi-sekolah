<?php

use App\Domain\Entitlement\Feature;
use App\Http\Controllers\Terminal\AssistController;
use App\Http\Controllers\Terminal\TerminalController;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\CountScanOutcomes;
use App\Http\Middleware\RequireSchoolFeature;
use Illuminate\Support\Facades\Route;

// --- Terminal (guard `device`, tanpa sesi/CSRF cookie; lihat AuthenticateDevice) ---
// Pairing butuh header terminal tetapi belum punya token; sisanya wajib token perangkat.
Route::post('pair', [TerminalController::class, 'pair'])
    ->middleware(['throttle:terminal-pair', 'terminal.header'])->name('terminal.pair');

// Terminal memakai modul absensi; metode scan (QR/RFID) diperiksa lagi per scan di TerminalController (ADR 0009 §5).
Route::middleware([AuthenticateDevice::class, RequireSchoolFeature::using(Feature::Attendance), 'throttle:terminal'])->group(function () {
    Route::get('config', [TerminalController::class, 'config'])->name('terminal.config');
    Route::post('scans', [TerminalController::class, 'scan'])->middleware(CountScanOutcomes::class)->name('terminal.scan');
    Route::get('recent', [TerminalController::class, 'recent'])->name('terminal.recent');
    Route::post('heartbeat', [TerminalController::class, 'heartbeat'])->name('terminal.heartbeat');

    // Mode bantuan petugas: login membuka sesi pendek; sisanya wajib sesi itu (header X-Assist-Session).
    Route::post('assist/session', [AssistController::class, 'start'])->middleware('throttle:terminal-assist')->name('terminal.assist.start');
    Route::middleware('assist.session')->group(function () {
        Route::delete('assist/session', [AssistController::class, 'end'])->name('terminal.assist.end');
        Route::get('assist/students', [AssistController::class, 'students'])->name('terminal.assist.students');
        Route::post('assist/attendance', [AssistController::class, 'attendance'])->name('terminal.assist.attendance');
    });
});
