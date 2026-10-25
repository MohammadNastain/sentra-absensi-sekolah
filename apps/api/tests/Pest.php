<?php

use App\Domain\Tenancy\DatabaseContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\RefreshPostgresDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshPostgresDatabase::class, InteractsWithTenancy::class)
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

/**
 * Sekolah baru otomatis berlangganan paket `legacy` (semua fitur rilis), seperti di produksi (ADR 0009 §8).
 * `$plan = null` membuat sekolah tanpa langganan.
 */
function createSchoolRow(string $code, ?string $plan = 'legacy'): string
{
    $id = insertRow('schools', [
        'code' => $code,
        'name' => 'Sekolah '.strtoupper($code),
        'education_levels' => '{SMP}',
        'timezone' => 'Asia/Jakarta',
    ]);

    if ($plan !== null) {
        setSchoolPlan($id, $plan);
    }

    return $id;
}

/** Mengganti langganan berjalan sebuah sekolah (yang lama menjadi `ended`), lewat konteks platform. */
function setSchoolPlan(string $schoolId, string $plan, string $status = 'active'): void
{
    app(DatabaseContext::class)->runAsPlatform(function () use ($schoolId, $plan, $status): void {
        DB::update("UPDATE school_subscriptions SET status = 'ended', ends_on = CURRENT_DATE WHERE school_id = ? AND status <> 'ended'", [$schoolId]);
        DB::table('school_subscriptions')->insert(['school_id' => $schoolId, 'plan_code' => $plan, 'status' => $status]);
    });
}

/** Menetapkan override fitur sebuah sekolah ('grant' atau 'revoke'); menimpa override lama bila ada. */
function setFeatureOverride(string $schoolId, string $feature, string $effect): void
{
    app(DatabaseContext::class)->runAsPlatform(function () use ($schoolId, $feature, $effect): void {
        DB::table('school_feature_overrides')->updateOrInsert(['school_id' => $schoolId, 'feature_code' => $feature], ['effect' => $effect]);
    });
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
