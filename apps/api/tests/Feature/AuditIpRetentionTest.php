<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Retensi IP audit (D-26): hanya ip_address yang dikosongkan, baris tetap append-only.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $ctx = $this->dbContext();
    $row = fn (?string $schoolId, int $days) => insertRow('audit_logs', [
        'school_id' => $schoolId, 'actor_type' => 'system', 'action' => 'school.profile.updated', 'ip_address' => '203.0.113.9',
        'user_agent' => 'uji', 'created_at' => now()->subDays($days)->toDateTimeString(),
    ]);
    $this->oldA = $ctx->runInSchool($this->schoolA, fn () => $row($this->schoolA, 120));
    $this->freshA = $ctx->runInSchool($this->schoolA, fn () => $row($this->schoolA, 10));
    $this->oldB = $ctx->runInSchool($this->schoolB, fn () => $row($this->schoolB, 91));
    $this->oldPlatform = $ctx->runAsPlatform(fn () => $row(null, 200));
});

it('mengosongkan IP audit yang lewat 90 hari di semua sekolah dan platform, baris lain utuh', function () {
    $this->artisan('audit:scrub-ip')->expectsOutput('Alamat IP audit dikosongkan: 3')->assertSuccessful();

    $ctx = $this->dbContext();
    $ctx->setSchool($this->schoolA);
    expect(DB::table('audit_logs')->where('id', $this->oldA)->first())->ip_address->toBeNull()->user_agent->toBe('uji')->action->toBe('school.profile.updated');
    expect(DB::table('audit_logs')->where('id', $this->freshA)->value('ip_address'))->toBe('203.0.113.9');
    $ctx->setSchool($this->schoolB);
    expect(DB::table('audit_logs')->where('id', $this->oldB)->value('ip_address'))->toBeNull();
    $ctx->setPlatform(true);
    expect(DB::table('audit_logs')->where('id', $this->oldPlatform)->value('ip_address'))->toBeNull();

    // Idempoten: dijalankan lagi tidak mengubah apa pun.
    $this->artisan('audit:scrub-ip')->expectsOutput('Alamat IP audit dikosongkan: 0')->assertSuccessful();
});

it('role aplikasi tetap tidak bisa mengubah kolom audit lain', function () {
    $this->dbContext()->setSchool($this->schoolA);

    expect(fn () => DB::update('UPDATE audit_logs SET user_agent = ? WHERE id = ?', ['x', $this->oldA]))->toThrow(QueryException::class, 'permission denied');
});

it('role aplikasi tidak bisa mengisi ulang atau mengubah IP selain mengosongkannya', function () {
    $this->dbContext()->setSchool($this->schoolA);

    expect(fn () => DB::update('UPDATE audit_logs SET ip_address = ? WHERE id = ?', ['198.51.100.1', $this->oldA]))->toThrow(QueryException::class, 'append-only');
});

it('menolak masa retensi nol', function () {
    $this->artisan('audit:scrub-ip', ['--days' => 0])->assertFailed();
});
