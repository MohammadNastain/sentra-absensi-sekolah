<?php

use App\Domain\Attendance\RecordScanAction;
use App\Models\Device;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ScanHelpers.php';

// Tahap 3C — uji konkurensi nyata: proses terpisah, koneksi database terpisah, data ter-commit.

beforeEach(fn () => scanSetUp()->call($this));

// Fixture harus ter-commit agar terlihat oleh proses lain, dan event append-only tidak bisa dihapus:
// skema dibangun ulang setelah tes ini agar tes lain tidak terkena sisa data.
afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack(); // bila tes gagal sebelum commit, lepaskan kunci agar migrate:fresh tidak menggantung
    }
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
});

it('N scan paralel dari kartu yang sama menghasilkan tepat satu check-in accepted', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl tidak tersedia.');
    }

    $device = $this->inSchool($this->school, fn () => Device::query()->first());
    DB::commit(); // akhiri transaksi pembungkus RefreshDatabase: fixture kini terlihat proses lain
    DB::purge();  // jangan wariskan soket database ke proses anak

    $workers = 8;
    $dir = sys_get_temp_dir().'/sentra-scan-'.Str::random(8);
    mkdir($dir);
    $pids = [];

    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::reconnect();
                $r = app(RecordScanAction::class)->record($this->school, $device, $this->token, (string) Str::uuid(), null);
                file_put_contents("{$dir}/{$i}", $r['result'].'|'.$r['code']);
            } catch (Throwable $e) {
                file_put_contents("{$dir}/{$i}", 'ERROR|'.$e->getMessage());
            }
            posix_kill(getmypid(), SIGKILL); // keluar tanpa menjalankan shutdown Pest/PHPUnit di anak
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    for ($i = 0; $i < $workers; $i++) {
        $results[] = is_file("{$dir}/{$i}") ? (string) file_get_contents("{$dir}/{$i}") : 'MISSING';
        @unlink("{$dir}/{$i}");
    }
    @rmdir($dir);

    expect(array_count_values(array_map(fn ($r) => explode('|', $r)[0], $results)))->toEqual(['accepted' => 1, 'duplicate' => $workers - 1]);

    DB::reconnect();
    $this->useSchool($this->school);
    expect(DB::table('attendance_events')->where('result', 'accepted')->count())->toBe(1)
        ->and(DB::table('daily_attendance')->count())->toBe(1);
});
