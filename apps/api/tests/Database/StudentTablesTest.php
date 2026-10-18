<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Tahap 2B — constraint dan RLS tabel siswa dan penempatan kelas, diuji langsung di DB sebagai sentra_app.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->ctx = $this->dbContext();
});

function expectStudentViolation(string $schoolId, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($schoolId, fn () => DB::transaction($operation)))
        ->toThrow(QueryException::class, $constraint);
}

function insertStudentRow(string $school, string $number, array $extra = []): string
{
    return insertRow('students', ['school_id' => $school, 'student_number' => $number, 'full_name' => 'Siswa '.$number, ...$extra]);
}

/** Kelas pada tahun ajaran sekolah itu; tahun dibuat sekali lalu dipakai bersama (rentang tahun tidak boleh bertumpuk). */
function insertClassRow(string $school, string $name): string
{
    static $years = [];
    $years[$school] ??= insertRow('academic_years', ['school_id' => $school, 'name' => 'Uji', 'start_date' => '2026-07-13', 'end_date' => '2027-06-26']);

    return insertRow('classes', ['school_id' => $school, 'academic_year_id' => $years[$school], 'name' => $name, 'grade_level' => 7]);
}

it('siswa: NIS unik per sekolah, NISN 10 digit dan unik per sekolah, nama tidak kosong, status terbatas', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertStudentRow($this->schoolA, '1001', ['nisn' => '0012345678']));
    $this->ctx->runInSchool($this->schoolB, fn () => insertStudentRow($this->schoolB, '1001', ['nisn' => '0012345678'])); // sekolah lain boleh sama

    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolA, '1001'), 'students_school_number_unique');
    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolA, '1002', ['nisn' => '0012345678']), 'students_nisn_unique');
    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolA, '1003', ['nisn' => '123']), 'students_nisn_format');
    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolA, '1004', ['full_name' => '  ']), 'students_full_name_not_blank');
    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolA, '1005', ['status' => 'dihapus']), 'students_status_check');
});

it('penempatan: siswa tidak bisa berada di dua kelas pada tanggal yang sama, tetapi boleh berurutan', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        $student = insertStudentRow($this->schoolA, '1001');
        $classA = insertClassRow($this->schoolA, '7A');
        $classB = insertClassRow($this->schoolA, '7B');

        insertRow('class_enrollments', ['school_id' => $this->schoolA, 'student_id' => $student, 'class_id' => $classA, 'start_date' => '2026-07-13', 'end_date' => '2026-08-31']);
        insertRow('class_enrollments', ['school_id' => $this->schoolA, 'student_id' => $student, 'class_id' => $classB, 'start_date' => '2026-09-01']);

        $this->student = $student;
        $this->classA = $classA;
    });

    // Penempatan terbuka (end_date NULL) bentrok dengan apa pun setelah 2026-09-01; juga dengan rentang yang beririsan.
    expectStudentViolation($this->schoolA, fn () => insertRow('class_enrollments', [
        'school_id' => $this->schoolA, 'student_id' => $this->student, 'class_id' => $this->classA, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
    ]), 'class_enrollments_no_overlap');
    expectStudentViolation($this->schoolA, fn () => insertRow('class_enrollments', [
        'school_id' => $this->schoolA, 'student_id' => $this->student, 'class_id' => $this->classA, 'start_date' => '2026-08-31', 'end_date' => '2026-09-05',
    ]), 'class_enrollments_no_overlap');
    expectStudentViolation($this->schoolA, fn () => insertRow('class_enrollments', [
        'school_id' => $this->schoolA, 'student_id' => $this->student, 'class_id' => $this->classA, 'start_date' => '2026-12-01', 'end_date' => '2026-11-01',
    ]), 'class_enrollments_dates_check');
});

it('penempatan: FK komposit mencegah menautkan siswa dan kelas dari sekolah berbeda', function () {
    $studentA = $this->ctx->runInSchool($this->schoolA, fn () => insertStudentRow($this->schoolA, '1001'));
    $classB = $this->ctx->runInSchool($this->schoolB, fn () => insertClassRow($this->schoolB, '7A'));

    expectStudentViolation($this->schoolA, fn () => insertRow('class_enrollments', [
        'school_id' => $this->schoolA, 'student_id' => $studentA, 'class_id' => $classB, 'start_date' => '2026-07-13',
    ]), 'class_enrollments_class_fk');
});

it('RLS: tanpa konteks tidak ada baris terlihat dan konteks A tidak melihat siswa B', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertStudentRow($this->schoolA, '1001'));
    $this->ctx->runInSchool($this->schoolB, fn () => insertStudentRow($this->schoolB, '2001'));

    expect(DB::table('students')->count())->toBe(0);
    expect($this->ctx->runInSchool($this->schoolA, fn () => DB::table('students')->pluck('student_number')->all()))->toBe(['1001']);
    expectStudentViolation($this->schoolA, fn () => insertStudentRow($this->schoolB, '9999'), 'row-level security');
});
