<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// ADR 0011 §2 — constraint dan RLS tabel wali, diuji langsung di DB sebagai sentra_app (lapis pertahanan di bawah aplikasi).

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->ctx = $this->dbContext();
});

function guardianViolation(string $schoolId, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($schoolId, fn () => DB::transaction($operation)))
        ->toThrow(QueryException::class, $constraint);
}

function guardianStudent(string $school, string $number): string
{
    return insertRow('students', ['school_id' => $school, 'student_number' => $number, 'full_name' => 'Siswa '.$number]);
}

it('wali: nama tidak kosong dan status terbatas', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'Bapak A']));

    guardianViolation($this->schoolA, fn () => insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => '  ']), 'guardians_full_name_not_blank');
    guardianViolation($this->schoolA, fn () => insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'X', 'status' => 'cuti']), 'guardians_status_check');
});

it('tautan wali-anak: hubungan terbatas, pasangan unik, satu wali utama per siswa, dan sesekolah', function () {
    [$studentId, $g1, $g2] = $this->ctx->runInSchool($this->schoolA, fn () => [
        guardianStudent($this->schoolA, '1'),
        insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'Bapak A']),
        insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'Ibu A']),
    ]);
    $link = fn (string $guardian, array $extra = []) => insertRow('student_guardians', ['school_id' => $this->schoolA, 'student_id' => $studentId, 'guardian_id' => $guardian, 'relationship' => 'ayah', ...$extra]);

    $this->ctx->runInSchool($this->schoolA, fn () => $link($g1, ['is_primary' => 'true']));

    guardianViolation($this->schoolA, fn () => $link($g1), 'student_guardians_pair_unique');
    guardianViolation($this->schoolA, fn () => $link($g2, ['relationship' => 'paman']), 'student_guardians_relationship_check');
    guardianViolation($this->schoolA, fn () => $link($g2, ['is_primary' => 'true']), 'student_guardians_one_primary');

    // Wali dan siswa sekolah lain tidak dapat ditautkan walau baris sekolah A sendiri sah.
    [$otherStudent, $otherGuardian] = $this->ctx->runInSchool($this->schoolB, fn () => [
        guardianStudent($this->schoolB, '2'),
        insertRow('guardians', ['school_id' => $this->schoolB, 'full_name' => 'Bapak B']),
    ]);
    guardianViolation($this->schoolA, fn () => $link($otherGuardian), 'student_guardians_guardian_fk');
    guardianViolation($this->schoolA, fn () => insertRow('student_guardians', ['school_id' => $this->schoolA, 'student_id' => $otherStudent, 'guardian_id' => $g2, 'relationship' => 'ibu']), 'student_guardians_student_fk');
});

it('RLS: wali dan tautannya terisolasi per sekolah', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        $student = guardianStudent($this->schoolA, '1');
        $guardian = insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'Bapak A']);
        insertRow('student_guardians', ['school_id' => $this->schoolA, 'student_id' => $student, 'guardian_id' => $guardian, 'relationship' => 'ayah']);
    });

    $this->ctx->runInSchool($this->schoolB, function () {
        expect(DB::table('guardians')->count())->toBe(0)->and(DB::table('student_guardians')->count())->toBe(0);
    });
    expect(fn () => $this->ctx->runInSchool($this->schoolB, fn () => insertRow('guardians', ['school_id' => $this->schoolA, 'full_name' => 'Curang'])))
        ->toThrow(QueryException::class, 'row-level security');
});

it('undangan boleh berperan wali, tetapi peran di luar daftar tetap ditolak', function () {
    $user = createUserRow('admin@example.test');
    $base = ['school_id' => $this->schoolA, 'email' => 'wali@example.test', 'token_hash' => bytea(random_bytes(32)), 'expires_at' => now()->addDay(), 'invited_by_user_id' => $user];

    $this->ctx->runInSchool($this->schoolA, fn () => insertRow('membership_invitations', [...$base, 'role_codes' => '{guardian}']));
    guardianViolation($this->schoolA, fn () => insertRow('membership_invitations', [...$base, 'email' => 'x@example.test', 'token_hash' => bytea(random_bytes(32)), 'role_codes' => '{student}']), 'membership_invitations_role_codes_check');
});
