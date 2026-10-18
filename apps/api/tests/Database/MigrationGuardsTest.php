<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

// M03, M04 — TAHAP_1_FONDASI §8

/** Skema ternormalisasi: kolom, indeks, constraint, policy RLS, dan status RLS per tabel. */
function schemaSnapshot(): string
{
    $lines = [];
    $columns = DB::select("SELECT table_name, column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name <> 'migrations' ORDER BY table_name, column_name");
    foreach ($columns as $c) {
        $lines[] = "column {$c->table_name}.{$c->column_name} {$c->data_type} nullable={$c->is_nullable}";
    }
    foreach (DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY indexdef") as $i) {
        $lines[] = 'index '.$i->indexdef;
    }
    foreach (DB::select("SELECT conrelid::regclass::text AS t, conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE connamespace = 'public'::regnamespace AND conrelid::regclass::text <> 'migrations' ORDER BY 1, 2") as $k) {
        $lines[] = "constraint {$k->t} {$k->conname} {$k->def}";
    }
    foreach (DB::select("SELECT tablename, policyname, cmd, qual, with_check FROM pg_policies WHERE schemaname = 'public' ORDER BY 1, 2") as $p) {
        $lines[] = "policy {$p->tablename} {$p->policyname} {$p->cmd} {$p->qual} {$p->with_check}";
    }
    foreach (DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r' AND relname <> 'migrations' ORDER BY 1") as $r) {
        $lines[] = "rls {$r->relname} enabled=".var_export($r->relrowsecurity, true).' forced='.var_export($r->relforcerowsecurity, true);
    }

    return implode("\n", $lines)."\n";
}

it('M03: skema basis data cocok dengan snapshot yang di-commit', function () {
    $file = database_path('schema-snapshot.txt');
    $current = schemaSnapshot();

    if (getenv('UPDATE_SCHEMA_SNAPSHOT') === '1') {
        file_put_contents($file, $current);
    }

    expect(file_exists($file))->toBeTrue('Jalankan sekali dengan UPDATE_SCHEMA_SNAPSHOT=1 untuk membuat snapshot.')
        ->and($current)->toBe(file_get_contents($file));
});

it('M04: DemoSeeder menolak berjalan di production', function () {
    config(['app.env' => 'production']);
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run($this->dbContext()))->toThrow(RuntimeException::class, 'production');
});

it('M04: DemoSeeder berjalan idempoten di lingkungan non-production', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->actingAs(User::query()->where('email', 'admin@demo.test')->firstOrFail())
        ->getJson('/api/v1/schools/demo-smpn-1')->assertOk();
});
