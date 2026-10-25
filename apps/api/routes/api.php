<?php

use App\Domain\Entitlement\Feature;
use App\Domain\Identity\Permission;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Invitation\PublicInvitationController;
use App\Http\Controllers\Me\MyInvitationController;
use App\Http\Controllers\Me\MySchoolsController;
use App\Http\Controllers\Platform\PlatformAuditLogController;
use App\Http\Controllers\Platform\PlatformEntitlementController;
use App\Http\Controllers\Platform\PlatformOverviewController;
use App\Http\Controllers\Platform\PlatformSchoolController;
use App\Http\Controllers\School\AbsenceController;
use App\Http\Controllers\School\AcademicYearController;
use App\Http\Controllers\School\AttendanceController;
use App\Http\Controllers\School\AuditLogController;
use App\Http\Controllers\School\CalendarController;
use App\Http\Controllers\School\CorrectionController;
use App\Http\Controllers\School\CredentialController;
use App\Http\Controllers\School\DeviceController;
use App\Http\Controllers\School\EntitlementController;
use App\Http\Controllers\School\ExportController;
use App\Http\Controllers\School\HolidayController;
use App\Http\Controllers\School\ImportController;
use App\Http\Controllers\School\InvitationController;
use App\Http\Controllers\School\LeaveRequestController;
use App\Http\Controllers\School\LmsAttachmentController;
use App\Http\Controllers\School\LmsMaterialController;
use App\Http\Controllers\School\MemberController;
use App\Http\Controllers\School\ReportController;
use App\Http\Controllers\School\ScheduleController;
use App\Http\Controllers\School\SchoolClassController;
use App\Http\Controllers\School\SchoolProfileController;
use App\Http\Controllers\School\StudentAccountController;
use App\Http\Controllers\School\StudentController;
use App\Http\Controllers\School\SubjectController;
use App\Http\Controllers\School\TeacherController;
use App\Http\Controllers\School\TeachingSlotController;
use App\Http\Middleware\EnsureEmailTransportReady;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RejectRememberMe;
use App\Http\Middleware\RequireSchoolFeature;
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
    Route::get('overview', [PlatformOverviewController::class, 'show'])->name('overview');
    Route::get('schools', [PlatformSchoolController::class, 'index'])->name('schools.index');
    Route::post('schools', [PlatformSchoolController::class, 'store'])->name('schools.store');
    Route::get('schools/{school}', [PlatformSchoolController::class, 'show'])->name('schools.show');
    Route::patch('schools/{school}', [PlatformSchoolController::class, 'update'])->name('schools.update');
    Route::post('schools/{school}/suspend', [PlatformSchoolController::class, 'suspend'])->name('schools.suspend');
    Route::post('schools/{school}/activate', [PlatformSchoolController::class, 'activate'])->name('schools.activate');
    Route::post('schools/{school}/invitations', [PlatformSchoolController::class, 'inviteAdmin'])
        ->middleware(EnsureEmailTransportReady::ALIAS)->name('schools.invitations.store');

    Route::get('audit-logs', [PlatformAuditLogController::class, 'index'])->name('audit-logs.index');

    // Paket, fitur, dan override per sekolah (ADR 0009 §6).
    Route::get('features', [PlatformEntitlementController::class, 'features'])->name('features.index');
    Route::get('plans', [PlatformEntitlementController::class, 'plans'])->name('plans.index');
    Route::get('schools/{school}/entitlements', [PlatformEntitlementController::class, 'show'])->name('schools.entitlements.show');
    Route::put('schools/{school}/subscription', [PlatformEntitlementController::class, 'changePlan'])->name('schools.subscription.update');
    Route::put('schools/{school}/entitlements/{feature}', [PlatformEntitlementController::class, 'setOverride'])->where('feature', '[a-z_.]+')->name('schools.entitlements.set');
    Route::delete('schools/{school}/entitlements/{feature}', [PlatformEntitlementController::class, 'removeOverride'])->where('feature', '[a-z_.]+')->name('schools.entitlements.remove');
});

// --- Sekolah (keanggotaan divalidasi setiap request, D-34) ---
Route::middleware(['auth:sanctum', ResolveSchoolMembership::class])->prefix('schools/{school}')->name('schools.')->group(function () {
    Route::get('/', [SchoolProfileController::class, 'show'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileView))->name('show');
    Route::patch('/', [SchoolProfileController::class, 'update'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileUpdate))->name('update');

    // Inti: fitur efektif sekolah ini (untuk menyaring menu); tidak digerbang fitur.
    Route::get('entitlements', [EntitlementController::class, 'show'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileView))->name('entitlements.show');

    Route::get('members', [MemberController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersView))->name('members.index');
    Route::post('members/accounts', [MemberController::class, 'storeAccount'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.accounts.store');
    Route::put('members/{membership}/password', [MemberController::class, 'setPassword'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.password');
    Route::get('members/{membership}', [MemberController::class, 'show'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersView))->name('members.show');
    Route::put('members/{membership}/roles', [MemberController::class, 'updateRoles'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.roles');
    Route::post('members/{membership}/suspend', [MemberController::class, 'suspend'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.suspend');
    Route::post('members/{membership}/reactivate', [MemberController::class, 'reactivate'])->whereUuid('membership')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolMembersManage))->name('members.reactivate');
    Route::post('members/{membership}/password-reset', [MemberController::class, 'requestPasswordReset'])->whereUuid('membership')
        ->middleware([EnsureEmailTransportReady::ALIAS, RequireSchoolPermission::using(Permission::SchoolMembersManage)])->name('members.password-reset');
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
        ->middleware([RequireSchoolFeature::using(Feature::AcademicYears), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('academic-years.index');
    Route::post('academic-years', [AcademicYearController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicYears), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('academic-years.store');
    Route::get('academic-years/{academicYear}', [AcademicYearController::class, 'show'])->whereUuid('academicYear')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicYears), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('academic-years.show');
    Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update'])->whereUuid('academicYear')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicYears), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('academic-years.update');
    Route::post('academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate'])->whereUuid('academicYear')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicYears), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('academic-years.activate');

    Route::get('teachers', [TeacherController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Teachers), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('teachers.index');
    Route::post('teachers', [TeacherController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Teachers), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('teachers.store');
    Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->whereUuid('teacher')
        ->middleware([RequireSchoolFeature::using(Feature::Teachers), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('teachers.show');
    Route::patch('teachers/{teacher}', [TeacherController::class, 'update'])->whereUuid('teacher')
        ->middleware([RequireSchoolFeature::using(Feature::Teachers), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('teachers.update');

    Route::get('subjects', [SubjectController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicSubjects), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('subjects.index');
    Route::post('subjects', [SubjectController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicSubjects), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('subjects.store');
    Route::patch('subjects/{subject}', [SubjectController::class, 'update'])->whereUuid('subject')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicSubjects), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('subjects.update');
    Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])->whereUuid('subject')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicSubjects), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('subjects.destroy');

    Route::get('teaching-slots', [TeachingSlotController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('teaching-slots.index');
    Route::post('teaching-slots', [TeachingSlotController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('teaching-slots.store');
    Route::patch('teaching-slots/{slot}', [TeachingSlotController::class, 'update'])->whereUuid('slot')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('teaching-slots.update');
    Route::delete('teaching-slots/{slot}', [TeachingSlotController::class, 'destroy'])->whereUuid('slot')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('teaching-slots.destroy');

    Route::get('classes', [SchoolClassController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicClasses), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('classes.index');
    Route::post('classes', [SchoolClassController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicClasses), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('classes.store');
    Route::get('classes/{schoolClass}', [SchoolClassController::class, 'show'])->whereUuid('schoolClass')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicClasses), RequireSchoolPermission::using(Permission::SchoolAcademicView)])->name('classes.show');
    Route::patch('classes/{schoolClass}', [SchoolClassController::class, 'update'])->whereUuid('schoolClass')
        ->middleware([RequireSchoolFeature::using(Feature::AcademicClasses), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('classes.update');

    // LMS (ADR 0010): materi dan lampiran. Lampiran digerbang fitur sendiri, termasuk saat mengunduh.
    Route::get('lms/materials', [LmsMaterialController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::LmsMaterials), RequireSchoolPermission::using(Permission::SchoolLmsView)])->name('lms.materials.index');
    Route::post('lms/materials', [LmsMaterialController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::LmsMaterials), RequireSchoolPermission::using(Permission::SchoolLmsManage)])->name('lms.materials.store');
    Route::get('lms/materials/{material}', [LmsMaterialController::class, 'show'])->whereUuid('material')
        ->middleware([RequireSchoolFeature::using(Feature::LmsMaterials), RequireSchoolPermission::using(Permission::SchoolLmsView)])->name('lms.materials.show');
    Route::patch('lms/materials/{material}', [LmsMaterialController::class, 'update'])->whereUuid('material')
        ->middleware([RequireSchoolFeature::using(Feature::LmsMaterials), RequireSchoolPermission::using(Permission::SchoolLmsManage)])->name('lms.materials.update');
    Route::delete('lms/materials/{material}', [LmsMaterialController::class, 'destroy'])->whereUuid('material')
        ->middleware([RequireSchoolFeature::using(Feature::LmsMaterials), RequireSchoolPermission::using(Permission::SchoolLmsManage)])->name('lms.materials.destroy');
    Route::post('lms/materials/{material}/attachments', [LmsAttachmentController::class, 'store'])->whereUuid('material')
        ->middleware([RequireSchoolFeature::using(Feature::LmsAttachments), RequireSchoolPermission::using(Permission::SchoolLmsManage)])->name('lms.attachments.store');
    Route::get('lms/attachments/{attachment}/download', [LmsAttachmentController::class, 'download'])->whereUuid('attachment')
        ->middleware([RequireSchoolFeature::using(Feature::LmsAttachments), RequireSchoolPermission::using(Permission::SchoolLmsView)])->name('lms.attachments.download');
    Route::delete('lms/attachments/{attachment}', [LmsAttachmentController::class, 'destroy'])->whereUuid('attachment')
        ->middleware([RequireSchoolFeature::using(Feature::LmsAttachments), RequireSchoolPermission::using(Permission::SchoolLmsManage)])->name('lms.attachments.destroy');

    Route::get('students', [StudentController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsView)])->name('students.index');
    Route::post('students', [StudentController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('students.store');
    Route::post('students/accounts', [StudentAccountController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('students.accounts.store');
    Route::post('students/{student}/account/password', [StudentAccountController::class, 'resetPassword'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('students.account.password');
    Route::get('students/{student}', [StudentController::class, 'show'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsView)])->name('students.show');
    Route::patch('students/{student}', [StudentController::class, 'update'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('students.update');
    Route::post('students/{student}/enrollments', [StudentController::class, 'enroll'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('students.enroll');

    Route::get('students/{student}/credentials', [CredentialController::class, 'index'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolCredentialsView)])->name('credentials.index');
    Route::post('students/{student}/credentials/qr', [CredentialController::class, 'issueForStudent'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceScanQr), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.issue');
    Route::post('students/{student}/credentials/rfid', [CredentialController::class, 'registerRfid'])->whereUuid('student')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceScanRfid), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.register-rfid');
    Route::post('classes/{schoolClass}/credentials/qr', [CredentialController::class, 'issueForClass'])->whereUuid('schoolClass')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceScanQr), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.issue-class');
    Route::post('credentials/{credential}/block', [CredentialController::class, 'block'])->whereUuid('credential')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.block');
    Route::post('credentials/{credential}/unblock', [CredentialController::class, 'unblock'])->whereUuid('credential')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.unblock');
    Route::post('credentials/{credential}/revoke', [CredentialController::class, 'revoke'])->whereUuid('credential')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolCredentialsManage)])->name('credentials.revoke');

    Route::get('devices', [DeviceController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesView)])->name('devices.index');
    Route::post('devices', [DeviceController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesManage)])->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->whereUuid('device')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesView)])->name('devices.show');
    Route::patch('devices/{device}', [DeviceController::class, 'update'])->whereUuid('device')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesManage)])->name('devices.update');
    Route::post('devices/{device}/pairing-code', [DeviceController::class, 'renewPairingCode'])->whereUuid('device')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesManage)])->name('devices.pairing-code');
    Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->whereUuid('device')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolDevicesManage)])->name('devices.revoke');

    Route::get('imports', [ImportController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.index');
    Route::post('imports/students', [ImportController::class, 'storeStudents'])
        ->middleware([RequireSchoolFeature::using(Feature::Students), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.store');
    Route::post('imports/teachers', [ImportController::class, 'storeTeachers'])
        ->middleware([RequireSchoolFeature::using(Feature::Teachers), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('imports.store-teachers');
    Route::post('imports/teaching-schedule', [ImportController::class, 'storeTeachingSchedule'])
        ->middleware([RequireSchoolFeature::using(Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolAcademicManage)])->name('imports.store-teaching-schedule');
    Route::get('imports/template', [ImportController::class, 'template'])
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.template');
    Route::get('imports/{import}', [ImportController::class, 'show'])->whereUuid('import')
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.show');
    Route::get('imports/{import}/rows', [ImportController::class, 'rows'])->whereUuid('import')
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.rows');
    Route::get('imports/{import}/failed-rows', [ImportController::class, 'failedRows'])->whereUuid('import')
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.failed-rows');
    Route::post('imports/{import}/commit', [ImportController::class, 'commit'])->whereUuid('import')
        ->middleware([RequireSchoolFeature::using(Feature::Students, Feature::Teachers, Feature::AcademicTeachingSchedule), RequireSchoolPermission::using(Permission::SchoolStudentsManage)])->name('imports.commit');

    Route::get('holidays', [HolidayController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleView)])->name('holidays.index');
    Route::post('holidays', [HolidayController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleManage)])->name('holidays.store');
    Route::get('holidays/{holiday}', [HolidayController::class, 'show'])->whereUuid('holiday')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleView)])->name('holidays.show');
    Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->whereUuid('holiday')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleManage)])->name('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->whereUuid('holiday')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleManage)])->name('holidays.destroy');
    Route::get('schedules', [ScheduleController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleView)])->name('schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleManage)])->name('schedules.store');
    Route::get('schedules/{schedule}', [ScheduleController::class, 'show'])->whereUuid('schedule')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleView)])->name('schedules.show');
    Route::patch('schedules/{schedule}', [ScheduleController::class, 'update'])->whereUuid('schedule')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleManage)])->name('schedules.update');
    Route::get('attendance/assist/students', [AttendanceController::class, 'assistStudents'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceAssist)])->name('attendance.assist.students');
    Route::post('attendance/assisted', [AttendanceController::class, 'assisted'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceAssist)])->name('attendance.assisted');
    Route::get('attendance/review-queue', [AttendanceController::class, 'reviewQueue'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.review-queue');
    Route::post('attendance/review-queue/{event}/resolve', [AttendanceController::class, 'resolveReview'])->whereUuid('event')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.review-queue.resolve');
    Route::get('attendance/assist-report', [AttendanceController::class, 'assistReport'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.assist-report');
    Route::get('attendance/absence-settings', [AbsenceController::class, 'settings'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('attendance.absence-settings');
    Route::put('attendance/absence-settings', [AbsenceController::class, 'updateSettings'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolProfileUpdate)])->name('attendance.absence-settings.update');
    Route::get('attendance/absences', [AbsenceController::class, 'pending'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceVerify)])->name('attendance.absences');
    Route::post('attendance/absences/verify', [AbsenceController::class, 'verify'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceVerify)])->name('attendance.absences.verify');
    Route::post('attendance/days/{date}/disruption', [AbsenceController::class, 'markDisruption'])->where('date', '\d{4}-\d{2}-\d{2}')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.days.disruption');
    Route::post('attendance/days/{date}/release', [AbsenceController::class, 'releaseHold'])->where('date', '\d{4}-\d{2}-\d{2}')
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.days.release');
    Route::post('attendance/corrections', [CorrectionController::class, 'apply'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceCorrect)])->name('attendance.corrections.apply');
    Route::get('attendance/corrections/history', [CorrectionController::class, 'history'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('attendance.corrections.history');
    Route::get('attendance/correction-requests', [CorrectionController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest)])->name('attendance.correction-requests.index');
    Route::post('attendance/correction-requests', [CorrectionController::class, 'request'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest)])->name('attendance.correction-requests.store');
    Route::post('attendance/correction-requests/{correction}/decision', [CorrectionController::class, 'decide'])->whereUuid('correction')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceCorrect)])->name('attendance.correction-requests.decide');
    Route::post('attendance/correction-requests/{correction}/cancel', [CorrectionController::class, 'cancel'])->whereUuid('correction')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceCorrections), RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest)])->name('attendance.correction-requests.cancel');
    Route::get('reports/summary', [ReportController::class, 'summary'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.summary');
    Route::get('reports/trend', [ReportController::class, 'trend'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.trend');
    Route::get('reports/students', [ReportController::class, 'students'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.students');
    Route::get('reports/lateness', [ReportController::class, 'lateness'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.lateness');
    Route::get('reports/daily', [ReportController::class, 'daily'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.daily');
    Route::get('reports/monthly', [ReportController::class, 'monthly'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('reports.monthly');
    Route::get('reports/events', [ReportController::class, 'events'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolAttendanceReview)])->name('reports.events');
    Route::get('exports', [ExportController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolReportsExport)])->name('exports.index');
    Route::post('exports', [ExportController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolReportsExport)])->name('exports.store');
    Route::get('exports/{export}', [ExportController::class, 'show'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolReportsExport)])->name('exports.show');
    Route::get('exports/{export}/download', [ExportController::class, 'download'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceReports), RequireSchoolPermission::using(Permission::SchoolReportsExport)])->name('exports.download');
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.index');
    Route::get('leave-requests/students', [LeaveRequestController::class, 'students'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.students');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.store');
    Route::post('leave-requests/{leave}/attachment', [LeaveRequestController::class, 'attach'])->whereUuid('leave')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.attach');
    Route::get('leave-requests/{leave}/attachment', [LeaveRequestController::class, 'attachment'])->whereUuid('leave')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.attachment');
    Route::post('leave-requests/{leave}/decision', [LeaveRequestController::class, 'decide'])->whereUuid('leave')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveApprove)])->name('leave-requests.decide');
    Route::post('leave-requests/{leave}/cancel', [LeaveRequestController::class, 'cancel'])->whereUuid('leave')
        ->middleware([RequireSchoolFeature::using(Feature::AttendanceLeave), RequireSchoolPermission::using(Permission::SchoolLeaveManage)])->name('leave-requests.cancel');
    Route::get('attendance/today', [AttendanceController::class, 'today'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolAttendanceView)])->name('attendance.today');
    Route::get('calendar', [CalendarController::class, 'show'])
        ->middleware([RequireSchoolFeature::using(Feature::Attendance), RequireSchoolPermission::using(Permission::SchoolScheduleView)])->name('calendar.show');
});
