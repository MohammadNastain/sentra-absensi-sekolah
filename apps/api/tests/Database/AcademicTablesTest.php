<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Tahap 2A — constraint dan RLS tabel akademik, diuji langsung di DB sebagai sentra_app.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->ctx = $this->dbContext();
});

/**
 * Menjalankan operasi yang diharapkan melanggar constraint di dalam savepoint, sehingga transaksi test
 * (dan konteks RLS) tetap sehat untuk pernyataan berikutnya.
 */
function expectViolation(string $schoolId, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($schoolId, fn () => DB::transaction($operation)))
        ->toThrow(QueryException::class, $constraint);
}

function insertYear(string $school, string $name, string $start, string $end, bool $active = false): string
{
    return insertRow('academic_years', [
        'school_id' => $school, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => $active ? 'true' : 'false',
    ]);
}

it('tahun ajaran: rentang tidak boleh tumpang tindih dalam satu sekolah, tetapi boleh antar sekolah', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertYear($this->schoolA, '2026/2027', '2026-07-13', '2027-06-26'));
    $this->ctx->runInSchool($this->schoolB, fn () => insertYear($this->schoolB, '2026/2027', '2026-07-13', '2027-06-26'));

    expectViolation($this->schoolA, fn () => insertYear($this->schoolA, 'Bentrok', '2027-06-26', '2027-12-31'), 'academic_years_no_overlap');
});

it('tahun ajaran: tanggal selesai harus setelah mulai, dan paling banyak satu aktif per sekolah', function () {
    expectViolation($this->schoolA, fn () => insertYear($this->schoolA, 'Terbalik', '2026-07-13', '2026-07-13'), 'academic_years_dates_check');

    $this->ctx->runInSchool($this->schoolA, fn () => insertYear($this->schoolA, '2026/2027', '2026-07-13', '2027-06-26', true));
    expectViolation($this->schoolA, fn () => insertYear($this->schoolA, '2027/2028', '2027-07-12', '2028-06-24', true), 'academic_years_one_active');

    // Sekolah lain bebas punya tahun aktifnya sendiri.
    $this->ctx->runInSchool($this->schoolB, fn () => insertYear($this->schoolB, '2026/2027', '2026-07-13', '2027-06-26', true));
});

it('kelas: FK komposit mencegah merujuk tahun ajaran atau guru sekolah lain', function () {
    $yearA = $this->ctx->runInSchool($this->schoolA, fn () => insertYear($this->schoolA, '2026/2027', '2026-07-13', '2027-06-26'));
    $teacherB = $this->ctx->runInSchool($this->schoolB, fn () => insertRow('teachers', ['school_id' => $this->schoolB, 'full_name' => 'Guru B']));

    // Kelas sekolah B yang menunjuk tahun ajaran sekolah A.
    expectViolation($this->schoolB, fn () => insertRow('classes', [
        'school_id' => $this->schoolB, 'academic_year_id' => $yearA, 'name' => '7A', 'grade_level' => 7,
    ]), 'classes_academic_year_fk');

    // Kelas sekolah A yang menunjuk guru sekolah B.
    expectViolation($this->schoolA, fn () => insertRow('classes', [
        'school_id' => $this->schoolA, 'academic_year_id' => $yearA, 'name' => '7A', 'grade_level' => 7, 'homeroom_teacher_id' => $teacherB,
    ]), 'classes_homeroom_teacher_fk');
});

it('kelas: tingkat 1–13 dan nama tidak kosong', function () {
    $year = $this->ctx->runInSchool($this->schoolA, fn () => insertYear($this->schoolA, '2026/2027', '2026-07-13', '2027-06-26'));

    expectViolation($this->schoolA, fn () => insertRow('classes', [
        'school_id' => $this->schoolA, 'academic_year_id' => $year, 'name' => '7A', 'grade_level' => 14,
    ]), 'classes_grade_level_check');

    expectViolation($this->schoolA, fn () => insertRow('classes', [
        'school_id' => $this->schoolA, 'academic_year_id' => $year, 'name' => '  ', 'grade_level' => 7,
    ]), 'classes_name_not_blank');
});

it('guru: satu akun hanya untuk satu guru, nomor pegawai unik per sekolah', function () {
    $userId = createUserRow('guru@example.test');
    $membership = $this->ctx->runInSchool($this->schoolA, fn () => insertRow('school_memberships', ['school_id' => $this->schoolA, 'user_id' => $userId]));

    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('teachers', [
        'school_id' => $this->schoolA, 'full_name' => 'G1', 'membership_id' => $membership, 'employee_number' => '1',
    ]));

    expectViolation($this->schoolA, fn () => insertRow('teachers', [
        'school_id' => $this->schoolA, 'full_name' => 'G2', 'membership_id' => $membership,
    ]), 'teachers_membership_id_unique');

    expectViolation($this->schoolA, fn () => insertRow('teachers', [
        'school_id' => $this->schoolA, 'full_name' => 'G3', 'employee_number' => '1',
    ]), 'teachers_employee_number_unique');
});

it('RLS: tanpa konteks tidak ada baris terlihat, konteks A tidak melihat B, dan tulis lintas sekolah ditolak', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertYear($this->schoolA, 'A', '2026-07-13', '2027-06-26'));
    $this->ctx->runInSchool($this->schoolB, fn () => insertYear($this->schoolB, 'B', '2026-07-13', '2027-06-26'));
    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('teachers', ['school_id' => $this->schoolA, 'full_name' => 'Guru A']));
    $this->ctx->reset();

    foreach (['academic_years', 'teachers', 'classes'] as $table) {
        expect(DB::scalar("SELECT count(*) FROM {$table}"))->toBe(0);
    }

    $this->ctx->setSchool($this->schoolA);
    expect(DB::table('academic_years')->pluck('name')->all())->toBe(['A'])
        ->and(DB::table('teachers')->pluck('full_name')->all())->toBe(['Guru A']);

    // Konteks A, tetapi menulis baris sekolah B: ditolak WITH CHECK.
    expectViolation($this->schoolA, fn () => insertYear($this->schoolB, 'Selundupan', '2030-07-01', '2031-06-30'), 'row-level security');
});
