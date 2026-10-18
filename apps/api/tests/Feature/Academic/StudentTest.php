<?php

use App\Domain\Identity\Role;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Tahap 2B — siswa, penempatan kelas, dan pindah kelas

beforeEach(function () {
    $this->school = School::factory()->create(['code' => 'sekolah-a']);
    $this->admin = User::factory()->create();
    $this->addMember($this->school, $this->admin, [Role::SchoolAdmin]);
    // Rentang lebar agar tanggal uji (hari ini) selalu jatuh di dalamnya.
    $this->year = $this->inSchool($this->school, fn () => AcademicYear::query()->create([
        'school_id' => $this->school->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ]));
    $this->classA = seedStudentClass($this, '7A');
    $this->classB = seedStudentClass($this, '7B');
});

function seedStudentClass($t, string $name, string $status = 'active'): string
{
    return $t->inSchool($t->school, fn () => SchoolClass::query()->create([
        'school_id' => $t->school->id, 'academic_year_id' => $t->year->id, 'name' => $name, 'grade_level' => 7, 'status' => $status,
    ])->id);
}

function studentsApi(string $path = '', string $method = 'getJson', array $data = [])
{
    $t = test();

    return $t->actingAs($t->admin)->{$method}("/api/v1/schools/sekolah-a/students{$path}", $data);
}

function seedStudent(School $school, string $name, string $number, array $extra = []): string
{
    return test()->inSchool($school, fn () => Student::query()->create([
        'school_id' => $school->id, 'full_name' => $name, 'student_number' => $number, 'status' => 'active', ...$extra,
    ])->id);
}

function schoolToday(): string
{
    return now('Asia/Jakarta')->toDateString();
}

it('membuat siswa tanpa kelas dan mencatat audit', function () {
    $id = studentsApi('', 'postJson', ['student_number' => '1001', 'nisn' => '0012345678', 'full_name' => 'Ahmad Rizky', 'date_of_birth' => '2012-05-01'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.current_class', null)
        ->assertJsonPath('data.enrollments', [])
        ->json('data.id');

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->where('action', 'school.student.created')->where('entity_id', $id)->exists())->toBeTrue();
});

it('validasi: field wajib, NISN 10 digit, unik per sekolah, status tidak bisa diisi saat membuat', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    seedStudent($other, 'Lain', '1001', ['nisn' => '0012345678']); // sekolah lain tidak menghalangi
    seedStudent($this->school, 'Ada', '2002', ['nisn' => '1111111111']);

    studentsApi('', 'postJson', [])->assertJsonValidationErrors(['student_number', 'full_name']);
    studentsApi('', 'postJson', ['student_number' => '1', 'full_name' => 'X', 'nisn' => '123'])->assertJsonValidationErrors(['nisn']);
    studentsApi('', 'postJson', ['student_number' => '2002', 'full_name' => 'X'])->assertJsonValidationErrors(['student_number']);
    studentsApi('', 'postJson', ['student_number' => '3', 'full_name' => 'X', 'nisn' => '1111111111'])->assertJsonValidationErrors(['nisn']);
    studentsApi('', 'postJson', ['student_number' => '1001', 'full_name' => 'Baru', 'nisn' => '0012345678'])->assertCreated();
    studentsApi('', 'postJson', ['student_number' => '4', 'full_name' => 'X', 'status' => 'inactive'])->assertJsonValidationErrors(['status']);
    studentsApi('', 'postJson', ['student_number' => '5', 'full_name' => 'X', 'date_of_birth' => '2999-01-01'])->assertJsonValidationErrors(['date_of_birth']);
});

it('daftar: cari nama (sebagian, tanpa peduli huruf), nomor induk, NISN; filter status dan kelas; wildcard literal', function () {
    $a = seedStudent($this->school, 'Ahmad Rizky', '1001', ['nisn' => '0012345678']);
    seedStudent($this->school, 'Budi Santoso', '1002');
    seedStudent($this->school, 'Citra 100%', '1003', ['status' => 'inactive']);
    studentsApi("/{$a}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => schoolToday()])->assertCreated();

    studentsApi('?q=rizk')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Ahmad Rizky');
    studentsApi('?q=1002')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Budi Santoso');
    studentsApi('?q=0012345678')->assertJsonCount(1, 'data');
    studentsApi('?q=%25')->assertJsonCount(1, 'data');
    studentsApi('?status=inactive')->assertJsonCount(1, 'data');
    studentsApi("?class_id={$this->classA}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.current_class.name', '7A');
    studentsApi("?class_id={$this->classB}")->assertJsonCount(0, 'data');
    studentsApi('?class_id=bukan-uuid')->assertOk()->assertJsonCount(3, 'data');
    studentsApi()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.full_name', 'Ahmad Rizky');
});

it('menempatkan siswa di kelas dan memindahkannya: penempatan lama ditutup sehari sebelumnya', function () {
    $id = seedStudent($this->school, 'Ahmad', '1001');

    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => '2026-07-13'])
        ->assertCreated()->assertJsonPath('data.enrollments.0.end_date', null);

    $response = studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB, 'start_date' => '2026-09-01'])->assertCreated();
    $response->assertJsonCount(2, 'data.enrollments')
        ->assertJsonPath('data.enrollments.0.class.name', '7A')
        ->assertJsonPath('data.enrollments.0.end_date', '2026-08-31')
        ->assertJsonPath('data.enrollments.1.class.name', '7B')
        ->assertJsonPath('data.enrollments.1.end_date', null);

    $this->useSchool($this->school);
    expect(DB::table('audit_logs')->whereIn('action', ['school.enrollment.created', 'school.enrollment.ended'])->count())->toBe(3);

    studentsApi("/{$id}")->assertJsonPath('data.current_class.name', '7B');
});

it('penempatan: tolak kelas yang sama, tanggal tidak setelah penempatan sebelumnya, kelas arsip/asing, di luar tahun ajaran', function () {
    $id = seedStudent($this->school, 'Ahmad', '1001');
    $archived = seedStudentClass($this, '9Z', 'archived');
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $otherYear = $this->inSchool($other, fn () => AcademicYear::query()->create([
        'school_id' => $other->id, 'name' => 'Uji', 'start_date' => '2020-01-01', 'end_date' => '2099-12-31',
    ])->id);
    $foreign = $this->inSchool($other, fn () => SchoolClass::query()->create(['school_id' => $other->id, 'academic_year_id' => $otherYear, 'name' => '7A', 'grade_level' => 7])->id);

    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => '2026-07-13'])->assertCreated();

    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => '2026-08-01'])
        ->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_CONFLICT');
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB, 'start_date' => '2026-07-13'])
        ->assertStatus(409)->assertJsonPath('code', 'ENROLLMENT_CONFLICT');
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB, 'start_date' => '2026-06-01'])
        ->assertStatus(409);
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $archived, 'start_date' => '2026-08-01'])->assertJsonValidationErrors(['class_id']);
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $foreign, 'start_date' => '2026-08-01'])->assertJsonValidationErrors(['class_id']);
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => 'bukan-uuid', 'start_date' => '2026-08-01'])->assertJsonValidationErrors(['class_id']);
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB, 'start_date' => '2100-01-01'])->assertJsonValidationErrors(['start_date']);
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB])->assertJsonValidationErrors(['start_date']);
});

it('siswa yang keluar (lulus/pindah/nonaktif) otomatis ditutup penempatannya dan tidak bisa ditempatkan lagi', function () {
    $id = seedStudent($this->school, 'Ahmad', '1001');
    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => schoolToday()])->assertCreated();

    studentsApi("/{$id}", 'patchJson', ['status' => 'graduated'])
        ->assertOk()->assertJsonPath('data.status', 'graduated')
        ->assertJsonPath('data.current_class.name', '7A') // berlaku sampai akhir hari ini
        ->assertJsonPath('data.enrollments.0.end_date', schoolToday());

    studentsApi("/{$id}/enrollments", 'postJson', ['class_id' => $this->classB, 'start_date' => schoolToday()])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    studentsApi("/{$id}", 'patchJson', ['status' => 'active'])->assertOk();
    studentsApi("/{$id}", 'patchJson', ['status' => 'dihapus'])->assertJsonValidationErrors(['status']);
});

it('mengubah data siswa mencatat before/after; nomor sendiri tidak dianggap bentrok', function () {
    $id = seedStudent($this->school, 'Ahmad', '1001');
    seedStudent($this->school, 'Budi', '1002');

    studentsApi("/{$id}", 'patchJson', ['full_name' => 'Ahmad Rizky', 'student_number' => '1001'])->assertOk()->assertJsonPath('data.full_name', 'Ahmad Rizky');
    studentsApi("/{$id}", 'patchJson', ['student_number' => '1002'])->assertJsonValidationErrors(['student_number']);

    $this->useSchool($this->school);
    $audit = DB::table('audit_logs')->where('action', 'school.student.updated')->where('entity_id', $id)->first();
    expect(json_decode($audit->before_value, true)['full_name'])->toBe('Ahmad')
        ->and(json_decode($audit->after_value, true)['full_name'])->toBe('Ahmad Rizky');
});

it('T05: siswa sekolah lain → 404 dan tidak ikut terdaftar', function () {
    $other = School::factory()->create(['code' => 'sekolah-b']);
    $foreign = seedStudent($other, 'Siswa Asing', '1');

    studentsApi("/{$foreign}")->assertNotFound();
    studentsApi("/{$foreign}", 'patchJson', ['full_name' => 'Dibajak'])->assertNotFound();
    studentsApi("/{$foreign}/enrollments", 'postJson', ['class_id' => $this->classA, 'start_date' => '2026-08-01'])->assertNotFound();
    studentsApi()->assertJsonPath('meta.total', 0);
    studentsApi('/bukan-uuid')->assertNotFound();
});
