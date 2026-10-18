<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Tahap 3A — constraint dan RLS tabel kredensial, diuji langsung di DB sebagai sentra_app.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->user = createUserRow('admin@contoh.test');
    $this->ctx = $this->dbContext();
    $this->studentA = $this->ctx->runInSchool($this->schoolA, fn () => insertRow('students', ['school_id' => $this->schoolA, 'student_number' => '1', 'full_name' => 'A']));
});

function insertCredential(string $school, string $student, string $user, string $hashHex, array $extra = []): string
{
    $row = ['school_id' => $school, 'student_id' => $student, 'credential_type' => 'qr', 'hash_key_version' => 1, 'created_by_user_id' => $user, ...$extra];
    $columns = implode(', ', array_keys($row));
    $placeholders = implode(', ', array_fill(0, count($row), '?'));

    return DB::scalar("INSERT INTO student_credentials ({$columns}, value_hash) VALUES ({$placeholders}, decode(?, 'hex')) RETURNING id", [...array_values($row), $hashHex]);
}

function expectCredentialViolation(string $school, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($school, fn () => DB::transaction($operation)))->toThrow(QueryException::class, $constraint);
}

it('satu kredensial aktif per siswa dan jenis; yang diblokir atau dicabut tidak dihitung', function () {
    $this->ctx->runInSchool($this->schoolA, function () {
        insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32));
        insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('b2', 32), ['status' => 'blocked', 'blocked_at' => now()]);
        insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('c3', 32), ['status' => 'revoked', 'revoked_at' => now()]);
    });

    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('d4', 32)), 'student_credentials_one_active');
});

it('nilai kredensial unik per sekolah kecuali yang dicabut; sekolah lain bebas', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32), ['status' => 'revoked', 'revoked_at' => now()]));
    $studentB = $this->ctx->runInSchool($this->schoolB, fn () => insertRow('students', ['school_id' => $this->schoolB, 'student_number' => '1', 'full_name' => 'B']));
    $this->ctx->runInSchool($this->schoolB, fn () => insertCredential($this->schoolB, $studentB, $this->user, str_repeat('e5', 32)));
    $studentA2 = $this->ctx->runInSchool($this->schoolA, fn () => insertRow('students', ['school_id' => $this->schoolA, 'student_number' => '2', 'full_name' => 'A2']));

    $this->ctx->runInSchool($this->schoolA, fn () => insertCredential($this->schoolA, $studentA2, $this->user, str_repeat('a1', 32))); // hash yang dicabut boleh dipakai ulang
    $this->ctx->runInSchool($this->schoolB, fn () => insertCredential($this->schoolB, insertRow('students', ['school_id' => $this->schoolB, 'student_number' => '2', 'full_name' => 'B2']), $this->user, str_repeat('a1', 32)));

    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, insertRow('students', ['school_id' => $this->schoolA, 'student_number' => '3', 'full_name' => 'A3']), $this->user, str_repeat('a1', 32)), 'student_credentials_value_unique');
});

it('constraint status, panjang hash, dan FK komposit', function () {
    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32), ['status' => 'hilang']), 'student_credentials_status_check');
    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32), ['status' => 'revoked']), 'student_credentials_status_timestamps');
    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, 'abcd'), 'student_credentials_hash_length');
    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32), ['credential_type' => 'face']), 'student_credentials_type_check');

    $studentB = $this->ctx->runInSchool($this->schoolB, fn () => insertRow('students', ['school_id' => $this->schoolB, 'student_number' => '9', 'full_name' => 'B']));
    expectCredentialViolation($this->schoolA, fn () => insertCredential($this->schoolA, $studentB, $this->user, str_repeat('a1', 32)), 'student_credentials_student_fk');
});

it('RLS memisahkan kredensial antar sekolah', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('a1', 32)));

    expect(DB::table('student_credentials')->count())->toBe(0)
        ->and($this->ctx->runInSchool($this->schoolB, fn () => DB::table('student_credentials')->count()))->toBe(0)
        ->and($this->ctx->runInSchool($this->schoolA, fn () => DB::table('student_credentials')->count()))->toBe(1);
    expectCredentialViolation($this->schoolB, fn () => insertCredential($this->schoolA, $this->studentA, $this->user, str_repeat('f6', 32)), 'row-level security');
});
