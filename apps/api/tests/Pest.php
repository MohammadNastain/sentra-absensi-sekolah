<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshPostgresDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshPostgresDatabase::class)
    ->in('Database', 'Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Menyisipkan baris lewat SQL mentah (tanpa Eloquent) dan mengembalikan id-nya.
 *
 * @param  array<string, mixed>  $values
 */
function insertRow(string $table, array $values): string
{
    $columns = implode(', ', array_keys($values));
    $placeholders = implode(', ', array_fill(0, count($values), '?'));

    return DB::scalar("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) RETURNING id", array_values($values));
}

function createSchoolRow(string $code): string
{
    return insertRow('schools', [
        'code' => $code,
        'name' => 'Sekolah '.strtoupper($code),
        'education_levels' => '{SMP}',
        'timezone' => 'Asia/Jakarta',
    ]);
}

function createUserRow(string $email): string
{
    return insertRow('users', [
        'email' => $email,
        'name' => 'Pengguna '.$email,
        'password' => 'bukan-hash-untuk-test-db',
    ]);
}

/**
 * Literal hex bytea PostgreSQL untuk binding parameter (data biner bukan teks UTF-8 yang valid).
 */
function bytea(string $binary): string
{
    return '\x'.bin2hex($binary);
}
