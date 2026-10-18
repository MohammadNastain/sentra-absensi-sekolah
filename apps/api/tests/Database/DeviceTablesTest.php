<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Tahap 3B — constraint, fungsi klaim pairing, dan RLS tabel perangkat.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->user = createUserRow('admin@contoh.test');
    $this->ctx = $this->dbContext();
});

function insertPendingDevice(string $school, string $user, string $codeHex, string $expires = "now() + interval '10 minutes'"): string
{
    return DB::scalar(
        "INSERT INTO devices (school_id, name, pairing_code_hash, pairing_expires_at, created_by_user_id) VALUES (?, 'Gerbang', decode(?, 'hex'), {$expires}, ?) RETURNING id",
        [$school, $codeHex, $user],
    );
}

function expectDeviceViolation(string $school, callable $operation, string $constraint): void
{
    expect(fn () => test()->dbContext()->runInSchool($school, fn () => DB::transaction($operation)))->toThrow(QueryException::class, $constraint);
}

it('klaim pairing atomik: sekali pakai, tidak bisa setelah kedaluwarsa, dan token tersimpan sebagai hash', function () {
    $code = str_repeat('ab', 32);
    $this->ctx->runInSchool($this->schoolA, fn () => insertPendingDevice($this->schoolA, $this->user, $code));

    $row = DB::selectOne("SELECT id, school_id FROM claim_device_pairing(decode(?, 'hex'), decode(?, 'hex'))", [$code, str_repeat('cd', 32)]);
    expect($row->school_id)->toBe($this->schoolA);
    expect(DB::selectOne("SELECT id FROM claim_device_pairing(decode(?, 'hex'), decode(?, 'hex'))", [$code, str_repeat('ef', 32)]))->toBeNull();

    $found = DB::selectOne("SELECT school_id, status FROM find_device_by_token_hash(decode(?, 'hex'))", [str_repeat('cd', 32)]);
    expect($found->school_id)->toBe($this->schoolA)->and($found->status)->toBe('active');
    expect(DB::selectOne("SELECT id FROM find_device_by_token_hash(decode(?, 'hex'))", [str_repeat('00', 32)]))->toBeNull();

    $this->ctx->runInSchool($this->schoolB, fn () => insertPendingDevice($this->schoolB, $this->user, str_repeat('12', 32), "now() - interval '1 minute'"));
    expect(DB::selectOne("SELECT id FROM claim_device_pairing(decode(?, 'hex'), decode(?, 'hex'))", [str_repeat('12', 32), str_repeat('34', 32)]))->toBeNull();
});

it('tanpa konteks sekolah, tabel perangkat tidak terbaca langsung; RLS memisahkan sekolah', function () {
    $this->ctx->runInSchool($this->schoolA, fn () => insertPendingDevice($this->schoolA, $this->user, str_repeat('ab', 32)));

    expect(DB::table('devices')->count())->toBe(0)
        ->and($this->ctx->runInSchool($this->schoolB, fn () => DB::table('devices')->count()))->toBe(0)
        ->and($this->ctx->runInSchool($this->schoolA, fn () => DB::table('devices')->count()))->toBe(1);
    expectDeviceViolation($this->schoolB, fn () => insertPendingDevice($this->schoolA, $this->user, str_repeat('56', 32)), 'row-level security');
});

it('constraint keadaan: status dan kolom rahasia harus konsisten; kode pairing unik', function () {
    expectDeviceViolation($this->schoolA, fn () => DB::insert("INSERT INTO devices (school_id, name, status, created_by_user_id) VALUES (?, 'X', 'active', ?)", [$this->schoolA, $this->user]), 'devices_state_check');
    expectDeviceViolation($this->schoolA, fn () => DB::insert("INSERT INTO devices (school_id, name, status, created_by_user_id) VALUES (?, 'X', 'revoked', ?)", [$this->schoolA, $this->user]), 'devices_state_check');
    expectDeviceViolation($this->schoolA, fn () => DB::insert("INSERT INTO devices (school_id, name, status, created_by_user_id) VALUES (?, '  ', 'pending_pairing', ?)", [$this->schoolA, $this->user]), 'devices_name_not_blank');

    $this->ctx->runInSchool($this->schoolA, fn () => insertPendingDevice($this->schoolA, $this->user, str_repeat('ab', 32)));
    expectDeviceViolation($this->schoolA, fn () => insertPendingDevice($this->schoolA, $this->user, str_repeat('ab', 32)), 'devices_pairing_code_unique');
    expectDeviceViolation($this->schoolA, fn () => DB::update('UPDATE schools SET device_limit = 0 WHERE id = ?', [$this->schoolA]), 'schools_device_limit_check');
});
