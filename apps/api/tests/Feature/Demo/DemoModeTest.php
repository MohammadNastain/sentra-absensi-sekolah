<?php

use App\Domain\Demo\DemoMode;
use App\Domain\Demo\DemoSchoolBuilder;
use App\Domain\Entitlement\EntitlementResolver;
use App\Domain\Identity\Role;
use App\Domain\Membership\InvitationToken;
use App\Domain\Tenancy\DatabaseContext;
use App\Jobs\SendInvitationEmail;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// Mode demo: masuk satu klik, sekolah contoh lengkap, LMS tampil khusus demo, akun bersama tidak bisa diubah, tanpa surel keluar.

afterEach(fn () => DemoMode::flush());

function buildDemo(): void
{
    config(['sentra.demo.enabled' => true]);
    app(DemoSchoolBuilder::class)->build();
}

it('endpoint demo tidak ada saat mode demo mati', function () {
    config(['sentra.demo.enabled' => false]);

    $this->postJson('/api/v1/auth/demo')->assertNotFound();
});

it('masuk satu klik sebagai admin sekolah demo', function () {
    buildDemo();

    $this->postJson('/api/v1/auth/demo')->assertOk()->assertJsonPath('data.demo', true);

    $this->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.email', 'admin@demo.sentra.test')
        ->assertJsonPath('data.is_demo', true)
        ->assertJsonPath('data.two_factor_enabled', false);

    $schools = $this->getJson('/api/v1/me/schools')->assertOk()->json('data');
    expect(collect($schools)->pluck('school.code')->all())->toContain('demo-sentra');
});

it('sekolah demo berisi data lengkap dan LMS aktif, sekolah lain tidak', function () {
    buildDemo();
    $demo = $this->dbContext()->runAsPlatform(fn () => School::query()->where('code', 'demo-sentra')->firstOrFail());

    $counts = $this->inSchool($demo, fn () => [
        'siswa' => DB::table('students')->count(),
        'guru' => DB::table('teachers')->count(),
        'kelas' => DB::table('classes')->count(),
        'absensi' => DB::table('daily_attendance')->count(),
        'kartu' => DB::table('student_credentials')->count(),
        'tugas' => DB::table('lms_assignments')->count(),
        'nilai' => DB::table('lms_submissions')->where('status', 'graded')->count(),
        'jadwal' => DB::table('teaching_slots')->count(),
    ]);
    foreach ($counts as $label => $n) {
        expect($n)->toBeGreaterThan(0, $label);
    }
    expect($counts['siswa'])->toBe(252)->and($counts['kelas'])->toBe(9);

    $resolver = app(EntitlementResolver::class);
    $enabled = fn (string $id) => $this->dbContext()->runAsPlatform(fn () => $resolver->enabled($id));
    expect($enabled($demo->id))->toContain('lms.grading', 'attendance.reports', 'academic.teaching_schedule');

    $other = School::factory()->create(['code' => 'sekolah-b']);
    $this->dbContext()->runAsPlatform(fn () => DB::table('school_subscriptions')->where('school_id', $other->id)->update(['plan_code' => 'premium']));
    expect($enabled($other->id))->toContain('attendance')->not->toContain('lms');
});

it('membangun ulang menonaktifkan sekolah demo lama dan memakai kode yang sama', function () {
    buildDemo();
    buildDemo();

    $rows = $this->dbContext()->runAsPlatform(fn () => DB::table('schools')->where('code', 'like', 'demo-%')->orderBy('code')->get(['code', 'status']));
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('code', 'demo-sentra')->status)->toBe('active')
        ->and($rows->first(fn ($r) => str_starts_with($r->code, 'demo-arsip-'))->status)->toBe('terminated');
});

it('akun demo tidak bisa login dengan sandi dan tidak bisa mengubah sandi atau 2FA', function () {
    buildDemo();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@demo.sentra.test', 'password' => 'demo-password-123'])->assertUnprocessable();

    $this->postJson('/api/v1/auth/demo')->assertOk();
    $this->putJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => 'Baru-Sandi-12345!', 'password_confirmation' => 'Baru-Sandi-12345!'])
        ->assertForbidden()->assertJsonPath('code', 'DEMO_RESTRICTED');
    $this->postJson('/api/v1/auth/two-factor')->assertForbidden();
});

it('sekolah demo tidak mengirim surel undangan maupun reset sandi', function () {
    buildDemo();
    Mail::fake();
    Notification::fake();
    $demo = $this->dbContext()->runAsPlatform(fn () => School::query()->where('code', 'demo-sentra')->firstOrFail());

    $invitationId = $this->inSchool($demo, fn () => DB::table('membership_invitations')->insertGetId([
        'school_id' => $demo->id, 'email' => 'korban@example.com', 'role_codes' => '{teacher}', 'token_hash' => InvitationToken::hash('token-rahasia'),
        'expires_at' => now()->addDay(), 'invited_by_user_id' => User::query()->where('email', 'admin@demo.sentra.test')->value('id'),
    ], 'id'));

    (new SendInvitationEmail($demo->id, $invitationId, 'token-rahasia'))->handle(app(DatabaseContext::class));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'admin@demo.sentra.test'])->assertAccepted();
    Notification::assertNothingSent();
});

it('sekolah demo menolak unggah berkas', function () {
    buildDemo();
    $this->postJson('/api/v1/auth/demo')->assertOk();

    $this->post('/api/v1/schools/demo-sentra/imports/students', ['file' => UploadedFile::fake()->create('siswa.csv', 1, 'text/csv')], ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('code', 'DEMO_RESTRICTED');
});

it('admin sekolah biasa tidak terpengaruh mode demo', function () {
    config(['sentra.demo.enabled' => true]);
    $school = School::factory()->create(['code' => 'sekolah-a']);
    $admin = User::factory()->create();
    $this->addMember($school, $admin, [Role::SchoolAdmin]);

    $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.is_demo', false);
    expect(DemoMode::isDemoSchoolId($school->id))->toBeFalse();
});
