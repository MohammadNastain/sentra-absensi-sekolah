<?php

use App\Http\Controllers\Terminal\TerminalController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

// --- Terminal (guard `device`, tanpa sesi/CSRF cookie; lihat AuthenticateDevice) ---
// Pairing butuh header terminal tetapi belum punya token; sisanya wajib token perangkat.
Route::post('pair', [TerminalController::class, 'pair'])
    ->middleware(['throttle:terminal-pair', 'terminal.header'])->name('terminal.pair');

Route::middleware([AuthenticateDevice::class, 'throttle:terminal'])->group(function () {
    Route::get('config', [TerminalController::class, 'config'])->name('terminal.config');
    Route::post('scans', [TerminalController::class, 'scan'])->name('terminal.scan');
    Route::get('recent', [TerminalController::class, 'recent'])->name('terminal.recent');
    Route::post('heartbeat', [TerminalController::class, 'heartbeat'])->name('terminal.heartbeat');
});
