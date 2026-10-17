<?php

use App\Domain\Identity\Permission;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Me\MySchoolsController;
use App\Http\Controllers\Platform\PlatformSchoolController;
use App\Http\Controllers\School\SchoolProfileController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RejectRememberMe;
use App\Http\Middleware\RequireSchoolPermission;
use App\Http\Middleware\ResolveSchoolMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/*
| Semua rute berprefiks /api/v1 dan memakai grup middleware `web` (sesi cookie + CSRF), lihat
| bootstrap/app.php. Setiap rute WAJIB terdaftar di tests/Authorization/RouteMatrix.php (T08).
*/

// --- Autentikasi (Fortify headless, TAHAP_1_FONDASI §6) ---
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware([RejectRememberMe::class, 'throttle:login'])->name('login');
    Route::post('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:two-factor')->name('two-factor.login');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.update');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('me', MeController::class)->name('auth.me');
        Route::put('password', [PasswordController::class, 'update'])->name('user-password.update');
        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');
        Route::get('confirmed-password-status', [ConfirmedPasswordStatusController::class, 'show'])->name('password.confirmation');

        Route::middleware('password.confirm')->prefix('two-factor')->group(function () {
            Route::post('/', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
            Route::delete('/', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
            Route::post('confirm', [ConfirmedTwoFactorAuthenticationController::class, 'store'])->name('two-factor.confirm');
            Route::get('qr-code', [TwoFactorQrCodeController::class, 'show'])->name('two-factor.qr-code');
            Route::get('secret-key', [TwoFactorSecretKeyController::class, 'show'])->name('two-factor.secret-key');
            Route::get('recovery-codes', [RecoveryCodeController::class, 'index'])->name('two-factor.recovery-codes');
            Route::post('recovery-codes', [RecoveryCodeController::class, 'store'])->name('two-factor.regenerate-recovery-codes');
        });
    });
});

// --- Milik user yang login (lintas sekolah, konteks RLS app.user_id) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me/schools', MySchoolsController::class)->name('me.schools');
});

// --- Platform (Super Admin + 2FA) ---
Route::middleware(['auth:sanctum', EnsurePlatformAdmin::class])->prefix('platform')->name('platform.')->group(function () {
    Route::get('schools', [PlatformSchoolController::class, 'index'])->name('schools.index');
});

// --- Sekolah (keanggotaan divalidasi setiap request, D-34) ---
Route::middleware(['auth:sanctum', ResolveSchoolMembership::class])->prefix('schools/{school}')->name('schools.')->group(function () {
    Route::get('/', [SchoolProfileController::class, 'show'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileView))->name('show');
});
