<?php

use App\Domain\Identity\Role;
use App\Domain\Identity\StudentLogin;
use App\Models\AcademicYear;
use App\Models\ClassEnrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// ADR 0010 §1 — akun siswa dari NIS: pembuatan, login `kode/NIS`, sandi awal sekali pakai, ganti sandi paksa.

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);

    $year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $this->class = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $year->id, 'name' => '7A', 'grade_level' => 7, 'status' => 'active',
    ])->id);

    $this->makeStudent = fn (string $number, string $status = 'active', bool $enroll = true) => $this->inSchool($this->school, function () use ($number, $status, $enroll) {
        $student = Student::query()->create(['school_id' => $this->school->id, 'full_name' => "Siswa {$number}", 'student_number' => $number, 'status' => $status]);
        if ($enroll) {
            ClassEnrollment::query()->create(['school_id' => $this->school->id, 'student_id' => $student->id, 'class_id' => $this->class, 'start_date' => '2020-01-01']);
        }

        return $student->id;
    });
    $this->s1 = ($this->makeStudent)('2024001');
    $this->s2 = ($this->makeStudent)('2024002');
});

function createAccounts(array $payload)
{
    $t = test();

    return $t->actingAs($t->admin)->postJson('/api/v1/schools/sekolah-a/students/accounts', $payload);
}

/** Login sebagai siswa dengan identitas `kode/NIS`, memakai sesi baru (tanpa admin). */
function loginStudent(string $username, string $password)
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/login', ['email' => $username, 'password' => $password]);
}

it('memetakan isian login ke alamat sintetis dan mengenalinya', function () {
    expect(StudentLogin::toEmail('Sekolah-A/2024001'))->toBe('2024001.sekolah-a@siswa.sentra.invalid')
        ->and(StudentLogin::toEmail('Guru@Contoh.test'))->toBe('guru@contoh.test')
        ->and(StudentLogin::toEmail('sekolah-a/spasi salah'))->toBe('sekolah-a/spasi salah')
        ->and(StudentLogin::isSynthetic('2024001.sekolah-a@siswa.sentra.invalid'))->toBeTrue()
        ->and(StudentLogin::isSynthetic('guru@contoh.test'))->toBeFalse()
        ->and(StudentLogin::generatePassword())->toMatch('/^[A-HJ-NP-Za-km-z2-9]{10}$/');
});

it('admin membuat akun siswa; sandi awal dikembalikan sekali dan tidak masuk audit', function () {
    $response = createAccounts(['student_ids' => [$this->s1, $this->s2]])->assertCreated()
        ->assertHeader('Cache-Control')->assertJsonCount(2, 'data.created')->assertJsonCount(0, 'data.skipped');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $first = $response->json('data.created.0');
    expect($first['username'])->toBe('sekolah-a/2024001')->and($first['initial_password'])->toHaveLength(10);

    $this->inSchool($this->school, function () use ($first) {
        $user = User::query()->where('email', '2024001.sekolah-a@siswa.sentra.invalid')->firstOrFail();
        expect($user->must_change_password)->toBeTrue()
            ->and(Student::query()->find($this->s1)->membership_id)->not->toBeNull()
            ->and(DB::table('membership_roles')->where('role_code', 'student')->count())->toBe(2);

        $audits = DB::table('audit_logs')->where('action', 'school.student_account.created')->get();
        expect($audits)->toHaveCount(2);
        foreach (DB::table('audit_logs')->get() as $row) {
            expect(json_encode($row))->not->toContain($first['initial_password']);
        }
    });
});

it('membuat akun untuk semua siswa aktif sebuah kelas yang belum punya akun', function () {
    ($this->makeStudent)('2024003', 'inactive');
    ($this->makeStudent)('2024004', 'active', false);

    createAccounts(['class_id' => $this->class])->assertCreated()->assertJsonCount(2, 'data.created');
});

it('melewati siswa yang tidak memenuhi syarat dengan alasan', function () {
    createAccounts(['student_ids' => [$this->s1]])->assertCreated();
    $inactive = ($this->makeStudent)('2024005', 'inactive');
    $badNumber = ($this->makeStudent)('NIS dengan spasi');
    $unknown = (string) Str::uuid();

    $skipped = collect(createAccounts(['student_ids' => [$this->s1, $inactive, $badNumber, $unknown]])->assertCreated()
        ->assertJsonCount(0, 'data.created')->json('data.skipped'))->pluck('reason', 'student_id');

    expect($skipped[$this->s1])->toBe('has_account')
        ->and($skipped[$inactive])->toBe('inactive')
        ->and($skipped[$badNumber])->toBe('invalid_number')
        ->and($skipped[$unknown])->toBe('not_found');
});

it('siswa login dengan kode/NIS, wajib ganti sandi, lalu bebas mengakses sekolahnya', function () {
    $password = createAccounts(['student_ids' => [$this->s1]])->json('data.created.0.initial_password');

    loginStudent('Sekolah-A/2024001', $password)->assertOk();
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    $this->getJson('/api/v1/schools/sekolah-a')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    $this->getJson('/api/v1/me/schools')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

    $this->putJson('/api/v1/auth/password', ['current_password' => $password, 'password' => 'kata-sandi-baru-123', 'password_confirmation' => 'kata-sandi-baru-123'])
        ->assertSuccessful();

    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.must_change_password', false);
    $this->getJson('/api/v1/schools/sekolah-a')->assertOk()->assertJsonPath('data.my_roles', ['student']);
});

it('kredensial siswa salah menghasilkan respons yang sama dengan NIS tak dikenal', function () {
    createAccounts(['student_ids' => [$this->s1]])->assertCreated();

    $wrong = loginStudent('sekolah-a/2024001', 'salah-sekali-123');
    $unknown = loginStudent('sekolah-a/9999999', 'salah-sekali-123');
    $otherSchool = loginStudent('sekolah-lain/2024001', 'salah-sekali-123');

    $strip = fn ($r) => array_diff_key($r->json(), ['request_id' => true]);
    $wrong->assertUnprocessable();
    expect($strip($unknown))->toBe($strip($wrong))->and($strip($otherSchool))->toBe($strip($wrong));
});

it('atur ulang sandi membuat sandi awal baru dan memaksa ganti lagi', function () {
    $old = createAccounts(['student_ids' => [$this->s1]])->json('data.created.0.initial_password');

    $new = $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/students/{$this->s1}/account/password")
        ->assertOk()->assertHeader('Cache-Control')->json('data.initial_password');
    expect($new)->not->toBe($old);

    loginStudent('sekolah-a/2024001', $old)->assertUnprocessable();
    loginStudent('sekolah-a/2024001', $new)->assertOk();
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.must_change_password', true);

    $this->inSchool($this->school, fn () => expect(DB::table('audit_logs')->where('action', 'school.student_account.password_reset')->count())->toBe(1));
});

it('atur ulang sandi ditolak bagi siswa tanpa akun dan lintas sekolah', function () {
    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/students/{$this->s1}/account/password")->assertConflict();

    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = $this->inSchool($other, fn () => Student::query()->create(['school_id' => $other->id, 'full_name' => 'Asing', 'student_number' => '7', 'status' => 'active'])->id);
    $this->actingAs($this->admin)->postJson("/api/v1/schools/sekolah-a/students/{$foreign}/account/password")->assertNotFound();
});

it('siswa tidak dapat membuat akun dan tidak muncul di daftar anggota', function () {
    createAccounts(['student_ids' => [$this->s1]])->assertCreated();

    $this->inSchool($this->school, fn () => expect(Student::query()->find($this->s1)->membership_id)->not->toBeNull());
    $studentUser = User::query()->where('email', '2024001.sekolah-a@siswa.sentra.invalid')->firstOrFail();
    $studentUser->forceFill(['must_change_password' => false])->save();

    $this->actingAs($studentUser)->postJson('/api/v1/schools/sekolah-a/students/accounts', ['student_ids' => [$this->s2]])->assertForbidden()->assertJsonPath('code', 'PERMISSION_DENIED');

    $members = $this->actingAs($this->admin)->getJson('/api/v1/schools/sekolah-a/members')->assertOk()->json('data');
    expect(collect($members)->pluck('roles')->flatten()->all())->not->toContain('student');
});

it('lupa sandi tidak mengirim surat ke alamat sintetis siswa', function () {
    Notification::fake();
    createAccounts(['student_ids' => [$this->s1]])->assertCreated();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => '2024001.sekolah-a@siswa.sentra.invalid'])->assertStatus(202);

    Notification::assertNothingSent();
});
