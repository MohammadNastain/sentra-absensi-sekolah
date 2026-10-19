<?php

use App\Domain\Identity\Permission;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Invitation\PublicInvitationController;
use App\Http\Controllers\Me\MyInvitationController;
use App\Http\Controllers\Me\MySchoolsController;
use App\Http\Controllers\Platform\PlatformSchoolController;
use App\Http\Controllers\School\AbsenceController;
use App\Http\Controllers\School\AcademicYearController;
use App\Http\Controllers\School\AttendanceController;
use App\Http\Controllers\School\AuditLogController;
use App\Http\Controllers\School\CalendarController;
use App\Http\Controllers\School\CorrectionController;
use App\Http\Controllers\School\CredentialController;
use App\Http\Controllers\School\DeviceController;
use App\Http\Controllers\School\HolidayController;
use App\Http\Controllers\School\InvitationController;
use App\Http\Controllers\School\LeaveRequestController;
use App\Http\Controllers\School\MemberController;
use App\Http\Controllers\School\ReportController;
use App\Http\Controllers\School\ScheduleController;
use App\Http\Controllers\School\SchoolClassController;
use App\Http\Controllers\School\SchoolProfileController;
use App\Http\Controllers\School\StudentController;
use App\Http\Controllers\School\StudentImportController;
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

    Route::get('students', [StudentController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsView))->name('students.index');
    Route::post('students', [StudentController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('students.store');
    Route::get('students/{student}', [StudentController::class, 'show'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsView))->name('students.show');
    Route::patch('students/{student}', [StudentController::class, 'update'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('students.update');
    Route::post('students/{student}/enrollments', [StudentController::class, 'enroll'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('students.enroll');

    Route::get('students/{student}/credentials', [CredentialController::class, 'index'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsView))->name('credentials.index');
    Route::post('students/{student}/credentials/qr', [CredentialController::class, 'issueForStudent'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.issue');
    Route::post('students/{student}/credentials/rfid', [CredentialController::class, 'registerRfid'])->whereUuid('student')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.register-rfid');
    Route::post('classes/{schoolClass}/credentials/qr', [CredentialController::class, 'issueForClass'])->whereUuid('schoolClass')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.issue-class');
    Route::post('credentials/{credential}/block', [CredentialController::class, 'block'])->whereUuid('credential')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.block');
    Route::post('credentials/{credential}/unblock', [CredentialController::class, 'unblock'])->whereUuid('credential')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.unblock');
    Route::post('credentials/{credential}/revoke', [CredentialController::class, 'revoke'])->whereUuid('credential')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolCredentialsManage))->name('credentials.revoke');

    Route::get('devices', [DeviceController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesView))->name('devices.index');
    Route::post('devices', [DeviceController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesManage))->name('devices.store');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->whereUuid('device')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesView))->name('devices.show');
    Route::patch('devices/{device}', [DeviceController::class, 'update'])->whereUuid('device')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesManage))->name('devices.update');
    Route::post('devices/{device}/pairing-code', [DeviceController::class, 'renewPairingCode'])->whereUuid('device')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesManage))->name('devices.pairing-code');
    Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->whereUuid('device')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolDevicesManage))->name('devices.revoke');

    Route::get('imports', [StudentImportController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.index');
    Route::post('imports/students', [StudentImportController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.store');
    Route::get('imports/template', [StudentImportController::class, 'template'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.template');
    Route::get('imports/{import}', [StudentImportController::class, 'show'])->whereUuid('import')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.show');
    Route::get('imports/{import}/rows', [StudentImportController::class, 'rows'])->whereUuid('import')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.rows');
    Route::get('imports/{import}/failed-rows', [StudentImportController::class, 'failedRows'])->whereUuid('import')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.failed-rows');
    Route::post('imports/{import}/commit', [StudentImportController::class, 'commit'])->whereUuid('import')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolStudentsManage))->name('imports.commit');

    Route::get('holidays', [HolidayController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleView))->name('holidays.index');
    Route::post('holidays', [HolidayController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleManage))->name('holidays.store');
    Route::get('holidays/{holiday}', [HolidayController::class, 'show'])->whereUuid('holiday')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleView))->name('holidays.show');
    Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->whereUuid('holiday')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleManage))->name('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->whereUuid('holiday')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleManage))->name('holidays.destroy');
    Route::get('schedules', [ScheduleController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleView))->name('schedules.index');
    Route::post('schedules', [ScheduleController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleManage))->name('schedules.store');
    Route::get('schedules/{schedule}', [ScheduleController::class, 'show'])->whereUuid('schedule')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleView))->name('schedules.show');
    Route::patch('schedules/{schedule}', [ScheduleController::class, 'update'])->whereUuid('schedule')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleManage))->name('schedules.update');
    Route::get('attendance/assist/students', [AttendanceController::class, 'assistStudents'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceAssist))->name('attendance.assist.students');
    Route::post('attendance/assisted', [AttendanceController::class, 'assisted'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceAssist))->name('attendance.assisted');
    Route::get('attendance/review-queue', [AttendanceController::class, 'reviewQueue'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.review-queue');
    Route::post('attendance/review-queue/{event}/resolve', [AttendanceController::class, 'resolveReview'])->whereUuid('event')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.review-queue.resolve');
    Route::get('attendance/assist-report', [AttendanceController::class, 'assistReport'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.assist-report');
    Route::get('attendance/absence-settings', [AbsenceController::class, 'settings'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('attendance.absence-settings');
    Route::put('attendance/absence-settings', [AbsenceController::class, 'updateSettings'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolProfileUpdate))->name('attendance.absence-settings.update');
    Route::get('attendance/absences', [AbsenceController::class, 'pending'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceVerify))->name('attendance.absences');
    Route::post('attendance/absences/verify', [AbsenceController::class, 'verify'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceVerify))->name('attendance.absences.verify');
    Route::post('attendance/days/{date}/disruption', [AbsenceController::class, 'markDisruption'])->where('date', '\d{4}-\d{2}-\d{2}')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.days.disruption');
    Route::post('attendance/days/{date}/release', [AbsenceController::class, 'releaseHold'])->where('date', '\d{4}-\d{2}-\d{2}')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.days.release');
    Route::post('attendance/corrections', [CorrectionController::class, 'apply'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceCorrect))->name('attendance.corrections.apply');
    Route::get('attendance/corrections/history', [CorrectionController::class, 'history'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('attendance.corrections.history');
    Route::get('attendance/correction-requests', [CorrectionController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest))->name('attendance.correction-requests.index');
    Route::post('attendance/correction-requests', [CorrectionController::class, 'request'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest))->name('attendance.correction-requests.store');
    Route::post('attendance/correction-requests/{correction}/decision', [CorrectionController::class, 'decide'])->whereUuid('correction')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceCorrect))->name('attendance.correction-requests.decide');
    Route::post('attendance/correction-requests/{correction}/cancel', [CorrectionController::class, 'cancel'])->whereUuid('correction')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceCorrectionRequest))->name('attendance.correction-requests.cancel');
    Route::get('reports/summary', [ReportController::class, 'summary'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('reports.summary');
    Route::get('reports/students', [ReportController::class, 'students'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('reports.students');
    Route::get('reports/lateness', [ReportController::class, 'lateness'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('reports.lateness');
    Route::get('reports/daily', [ReportController::class, 'daily'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('reports.daily');
    Route::get('reports/monthly', [ReportController::class, 'monthly'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('reports.monthly');
    Route::get('reports/events', [ReportController::class, 'events'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceReview))->name('reports.events');
    Route::get('leave-requests', [LeaveRequestController::class, 'index'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.index');
    Route::get('leave-requests/students', [LeaveRequestController::class, 'students'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.students');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.store');
    Route::post('leave-requests/{leave}/attachment', [LeaveRequestController::class, 'attach'])->whereUuid('leave')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.attach');
    Route::get('leave-requests/{leave}/attachment', [LeaveRequestController::class, 'attachment'])->whereUuid('leave')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.attachment');
    Route::post('leave-requests/{leave}/decision', [LeaveRequestController::class, 'decide'])->whereUuid('leave')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveApprove))->name('leave-requests.decide');
    Route::post('leave-requests/{leave}/cancel', [LeaveRequestController::class, 'cancel'])->whereUuid('leave')
        ->middleware(RequireSchoolPermission::using(Permission::SchoolLeaveManage))->name('leave-requests.cancel');
    Route::get('attendance/today', [AttendanceController::class, 'today'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolAttendanceView))->name('attendance.today');
    Route::get('calendar', [CalendarController::class, 'show'])
        ->middleware(RequireSchoolPermission::using(Permission::SchoolScheduleView))->name('calendar.show');
});
