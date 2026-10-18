<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Tahap 2A — guru (D-07): akun login opsional

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
});

function teachersApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/teachers{$path}", $data);
}

function seedTeacher(School $school, string $name, array $extra = []): string
{
    return test()->inSchool($school, fn () => Teacher::query()->create([
        'school_id' => $school->id, 'full_name' => $name, 'status' => 'active', ...$extra,
    ])->id);
}

it('membuat guru tanpa akun dan mencatat audit', function () {
    $id = teachersApi('', 'postJson', ['full_name' => 'Siti Rahma', 'employee_number' => '198001012005012001'])
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Siti Rahma')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.account', null)
        ->json('data.id');

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.teacher.created')->where('entity_id', $id)->exists())->toBeTrue();
});

it('menautkan guru ke akun anggota sekolah dan menampilkan akunnya', function () {
    $user = User::factory()->create(['name' => 'Budi Guru', 'email' => 'budi@example.test']);
    $membership = $this->addMember($this->school, $user, [Role::HomeroomTeacher]);

    teachersApi('', 'postJson', ['full_name' => 'Budi Guru', 'membership_id' => $membership->id])
        ->assertCreated()
        ->assertJsonPath('data.account.email', 'budi@example.test')
        ->assertJsonPath('data.account.membership_id', $membership->id);
});

it('menolak tautan akun: milik sekolah lain, sudah berakhir, atau sudah dipakai guru lain', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = $this->addMember($other, User::factory()->create(), [Role::HomeroomTeacher]);
    $ended = $this->addMember($this->school, User::factory()->create(), [Role::HomeroomTeacher], 'ended');
    $taken = $this->addMember($this->school, User::factory()->create(), [Role::HomeroomTeacher]);
    seedTeacher($this->school, 'Sudah Terpakai', ['membership_id' => $taken->id]);

    foreach ([$foreign, $ended, $taken] as $membership) {
        teachersApi('', 'postJson', ['full_name' => 'Calon', 'membership_id' => $membership->id])
            ->assertJsonValidationErrors(['membership_id']);
    }
});

it('nomor pegawai unik per sekolah (boleh sama di sekolah lain) dan status tidak bisa diisi saat membuat', function () {
    seedTeacher($this->school, 'Guru A', ['employee_number' => '1234']);
    $other = School::factory()->create(['code' => 'sekolah-b']);
    seedTeacher($other, 'Guru B', ['employee_number' => '1234']);

    teachersApi('', 'postJson', ['full_name' => 'Guru C', 'employee_number' => '1234'])->assertJsonValidationErrors(['employee_number']);
    teachersApi('', 'postJson', ['full_name' => 'Guru D', 'status' => 'inactive'])->assertJsonValidationErrors(['status']);
    teachersApi('', 'postJson', ['employee_number' => '9'])->assertJsonValidationErrors(['full_name']);
});

it('daftar: cari nama atau nomor pegawai, filter status, wildcard LIKE diperlakukan literal', function () {
    seedTeacher($this->school, 'Siti Rahma', ['employee_number' => '111']);
    seedTeacher($this->school, 'Budi Santoso', ['employee_number' => '222']);
    seedTeacher($this->school, 'Citra 100%', ['status' => 'inactive']);

    teachersApi('?q=siti')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Siti Rahma');
    teachersApi('?q=222')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Budi Santoso');
    teachersApi('?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Citra 100%');
    teachersApi('?q=%25')->assertJsonCount(1, 'data'); // hanya yang benar-benar memuat "%"
    teachersApi()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.full_name', 'Budi Santoso');
});

it('mengubah guru mencatat before/after, dan menautkan serta melepas akun', function () {
    $membership = $this->addMember($this->school, User::factory()->create(), [Role::HomeroomTeacher]);
    $id = seedTeacher($this->school, 'Siti');

    teachersApi("/{$id}", 'patchJson', ['full_name' => 'Siti Rahma', 'membership_id' => $membership->id])
        ->assertOk()->assertJsonPath('data.full_name', 'Siti Rahma')->assertJsonPath('data.account.membership_id', $membership->id);
    teachersApi("/{$id}", 'patchJson', ['membership_id' => null])->assertOk()->assertJsonPath('data.account', null);

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.teacher.updated')->where('entity_id', $id)->orderBy('created_at')->first();
    expect(json_decode($audit->before_value, true)['full_name'])->toBe('Siti')
        ->and(json_decode($audit->after_value, true)['full_name'])->toBe('Siti Rahma');
});

it('guru yang masih wali kelas aktif tidak bisa dinonaktifkan', function () {
    $teacherId = seedTeacher($this->school, 'Wali');
    $yearId = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26',
    ])->id);
    $classId = $this->inSchool($this->school, fn () => SchoolClass::query()->create([
        'school_id' => $this->school->id, 'academic_year_id' => $yearId, 'name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => $teacherId,
    ])->id);

    teachersApi("/{$teacherId}", 'patchJson', ['status' => 'inactive'])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    $this->inSchool($this->school, fn () => SchoolClass::query()->whereKey($classId)->update(['status' => 'archived']));
    teachersApi("/{$teacherId}", 'patchJson', ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
});

it('T05: guru sekolah lain → 404 dan tidak ikut terdaftar', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = seedTeacher($other, 'Guru Asing');

    teachersApi("/{$foreign}")->assertNotFound();
    teachersApi("/{$foreign}", 'patchJson', ['full_name' => 'Dibajak'])->assertNotFound();
    teachersApi()->assertJsonPath('meta.total', 0);
    teachersApi('/bukan-uuid')->assertNotFound();
});

it('membership_id yang bukan UUID → 422 (bukan error database)', function () {
    teachersApi('', 'postJson', ['full_name' => 'Guru', 'membership_id' => 'bukan-uuid'])->assertJsonValidationErrors(['membership_id']);
});
