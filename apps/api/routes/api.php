<?php

use App\Domain\Identity\Permission;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Invitation\PublicInvitationController;
use App\Http\Controllers\Me\MyInvitationController;
use App\Http\Controllers\Me\MySchoolsController;
use App\Http\Controllers\Platform\PlatformSchoolController;
use App\Http\Controllers\School\AcademicYearController;
use App\Http\Controllers\School\AuditLogController;
use App\Http\Controllers\School\InvitationController;
use App\Http\Controllers\School\MemberController;
use App\Http\Controllers\School\SchoolClassController;
use App\Http\Controllers\School\SchoolProfileController;
use App\Http\Controllers\School\TeacherController;
use App\Http\Middleware\EnsureEmailTransportReady;
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
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/*
| Semua rute berprefiks /api/v1 dan memakai grup middleware `web` (sesi cookie + CSRF), lihat
| bootstrap/app.php. Setiap rute WAJIB terdaftar di tests/Feature/Authorization/RouteMatrix.php (T08).
*/

// --- Autentikasi (Fortify headless, TAHAP_1_FONDASI §6) ---
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware([RejectRememberMe::class, 'throttle:login'])->name('login');
    Route::post('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:two-factor')->name('two-factor.login');
    Route::post('forgot-password', ForgotPasswordController::class)
        ->middleware([EnsureEmailTransportReady::ALIAS, 'throttle:password-reset'])->name('password.email');
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

// --- Undangan lewat token (publik; pembatasan laju per IP) ---
Route::middleware('throttle:invitation-public')->prefix('invitations')->group(function () {
    Route::get('{token}', [PublicInvitationController::class, 'show'])->name('invitations.show');
    Route::post('{token}/accept', [PublicInvitationController::class, 'accept'])->name('invitations.accept');
});

// --- Milik user yang login (lintas sekolah, konteks RLS app.user_id) ---
Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function () {
    Route::get('schools', MySchoolsController::class)->name('schools');
    Route::get('invitations', [MyInvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations/{invitation}/accept', [MyInvitationController::class, 'accept'])->whereUuid('invitation')->name('invitations.accept');
    Route::post('invitations/{invitation}/decline', [MyInvitationController::class, 'decline'])->whereUuid('invitation')->name('invitations.decline');
});

// --- Platform (Super Admin + 2FA) ---
Route::middleware(['auth:sanctum', EnsurePlatformAdmin::class])->prefix('platform')->name('platform.')->group(function () {
    Route::get('schools', [PlatformSchoolController::class, 'index'])->name('schools.index');
    Route::post('schools', [PlatformSchoolController::class, 'store'])->name('schools.store');
    Route::get('schools/{school}', [PlatformSchoolController::class, 'show'])->name('schools.show');
    Route::patch('schools/{school}', [PlatformSchoolController::class, 'update'])->name('schools.update');
    Route::post('schools/{school}/suspend', [PlatformSchoolController::class, 'suspend'])->name('schools.suspend');
    Route::post('schools/{school}/activate', [PlatformSchoolController::class, 'activate'])->name('schools.activate');
    Route::post('schools/{school}/invitations', [PlatformSchoolController::class, 'inviteAdmin'])
        ->middleware(EnsureEmailTransportReady::ALIAS)->name('schools.invitations.store');
});

// --- Sekolah (keanggotaan divalidasi setiap request, D-34) ---
Route::middleware(['auth:sanctum', ResolveSchoolMembership::class])->prefix('schools/{school}')->name('schools.')->group(function () {
    Route::get('/', [SchoolProfileController::class, 'show'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileView))->name('show');
    Route::patch('/', [SchoolProfileController::class, 'update'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileUpdate))->name('update');

    Route::get('members', [MemberController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersView))->name('members.index');
    Route::get('members/{membership}', [MemberController::class, 'show'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersView))->name('members.show');
    Route::put('members/{membership}/roles', [MemberController::class, 'updateRoles'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.roles');
    Route::post('members/{membership}/suspend', [MemberController::class, 'suspend'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.suspend');
    Route::post('members/{membership}/reactivate', [MemberController::class, 'reactivate'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.reactivate');
    Route::post('members/{membership}/end', [MemberController::class, 'end'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.end');

    Route::get('invitations', [InvitationController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])
        ->middleware([EnsureEmailTransportReady::ALIAS, RequireSchoolPermission::using(Permission::SchoolMembersManage)])->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->whereUuid('invitation')
        ->middleware([EnsureEmailTransportReady::ALIAS, RequireSchoolPermission::using(Permission::SchoolMembersManage)])->name('invitations.resend');
    Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->whereUuid('invitation')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('invitations.revoke');

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAuditView))->name('audit-logs.index');

    // Data akademik (Tahap 2A). Tanpa DELETE: data inti dinonaktifkan/diarsipkan, tidak dihapus.
    Route::get('academic-years', [AcademicYearController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('academic-years.index');
    Route::post('academic-years', [AcademicYearController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('academic-years.store');
    Route::get('academic-years/{academicYear}', [AcademicYearController::class, 'show'])->whereUuid('academicYear')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('academic-years.show');
    Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update'])->whereUuid('academicYear')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('academic-years.update');
    Route::post('academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])->whereUuid('academicYear')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('academic-years.activate');

    Route::get('teachers', [TeacherController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('teachers.index');
    Route::post('teachers', [TeacherController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('teachers.store');
    Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->whereUuid('teacher')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('teachers.show');
    Route::patch('teachers/{teacher}', [TeacherController::class, 'update'])->whereUuid('teacher')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('teachers.update');

    Route::get('classes', [SchoolClassController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('classes.index');
    Route::post('classes', [SchoolClassController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('classes.store');
    Route::get('classes/{schoolClass}', [SchoolClassController::class, 'show'])->whereUuid('schoolClass')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicView))->name('classes.show');
    Route::patch('classes/{schoolClass}', [SchoolClassController::class, 'update'])->whereUuid('schoolClass')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAcademicManage))->name('classes.update');
});
