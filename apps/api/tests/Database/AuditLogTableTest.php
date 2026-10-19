<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// U02 (append-only) dan U04 (visibilitas per sekolah & platform) — TAHAP_1_FONDASI §8.

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->user = createUserRow('admin@example.test');

    $insert = fn (?string $schoolId, string $action) => insertRow('audit_logs', [
        'school_id' => $schoolId,
        'actor_type' => 'user',
        'actor_user_id' => $this->user,
        'action' => $action,
    ]);

    $ctx = $this->dbContext();
    $this->auditA = $ctx->runInSchool($this->schoolA, fn () => $insert($this->schoolA, 'school.profile.updated'));
    $this->auditB = $ctx->runInSchool($this->schoolB, fn () => $insert($this->schoolB, 'school.profile.updated'));
    $this->auditPlatform = $ctx->runAsPlatform(fn () => $insert(null, 'platform.school.created'));
});

it('mengizinkan menulis audit tanpa sekolah di luar konteks platform, tetapi tidak membacanya kembali', function () {
    // Mis. auth.login.failed sebelum ada konteks. INSERT ... RETURNING akan ditolak karena
    // baris harus lolos policy SELECT, jadi penulis audit tidak boleh memakai RETURNING.
    DB::insert("INSERT INTO audit_logs (actor_type, action) VALUES ('system', 'auth.login.failed')");

    expect(DB::scalar('SELECT count(*) FROM audit_logs'))->toBe(0);

    $this->dbContext()->setPlatform(true);
    expect(DB::table('audit_logs')->where('action', 'auth.login.failed')->count())->toBe(1);
});

it('menolak UPDATE oleh role aplikasi', function () {
    $this->dbContext()->setSchool($this->schoolA);

    DB::update('UPDATE audit_logs SET reason = ? WHERE id = ?', ['ubah', $this->auditA]);
})->throws(QueryException::class, 'permission denied');

it('menolak DELETE oleh role aplikasi', function () {
    $this->dbContext()->setSchool($this->schoolA);

    DB::delete('DELETE FROM audit_logs WHERE id = ?', [$this->auditA]);
})->throws(QueryException::class, 'permission denied');

it('menolak TRUNCATE oleh role aplikasi', function () {
    DB::statement('TRUNCATE audit_logs');
})->throws(QueryException::class, 'permission denied');

it('memasang trigger append-only yang juga berlaku bagi owner', function () {
    $triggers = DB::table('pg_trigger')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
        ->where('pg_class.relname', 'audit_logs')
        ->where('pg_trigger.tgisinternal', false)
        ->where('pg_trigger.tgenabled', 'O')
        ->pluck('pg_trigger.tgname')
        ->sort()->values()->all();

    expect($triggers)->toBe(['audit_logs_ip_scrub_only', 'audit_logs_no_delete', 'audit_logs_no_truncate']);

    // Trigger diuji sebagai owner (yang masih punya hak UPDATE/DELETE) pada baris miliknya sendiri,
    // di transaksi terpisah yang selalu di-rollback.
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $owner->select("SELECT set_config('app.platform', 'on', true)");
        $id = $owner->scalar("INSERT INTO audit_logs (actor_type, action) VALUES ('system', 'test.trigger') RETURNING id");

        // DELETE: tidak ada policy DELETE, jadi owner pun tidak mengenai baris apa pun.
        expect($owner->delete('DELETE FROM audit_logs WHERE id = ?', [$id]))->toBe(0);

        // UPDATE selain mengosongkan ip_address ditolak trigger, juga untuk owner (policy scrub mengizinkan baris platform).
        expect(fn () => $owner->update('UPDATE audit_logs SET reason = ? WHERE id = ?', ['x', $id]))
            ->toThrow(QueryException::class, 'append-only');
    } finally {
        $owner->rollBack();
        $owner->disconnect();
    }
});

it('hanya menampilkan audit sekolah konteks', function () {
    $this->dbContext()->setSchool($this->schoolA);

    expect(DB::table('audit_logs')->pluck('id')->all())->toBe([$this->auditA]);
});

it('tidak menampilkan baris platform kepada konteks sekolah dan tanpa konteks', function () {
    expect(DB::scalar('SELECT count(*) FROM audit_logs'))->toBe(0);

    $this->dbContext()->setSchool($this->schoolA);
    expect(DB::scalar('SELECT count(*) FROM audit_logs WHERE school_id IS NULL'))->toBe(0);
});

it('menampilkan baris platform hanya dalam konteks platform', function () {
    $this->dbContext()->setPlatform(true);

    expect(DB::table('audit_logs')->pluck('id')->all())->toBe([$this->auditPlatform]);
});

it('menolak menulis audit sekolah lain dari konteks A', function () {
    $this->dbContext()->setSchool($this->schoolA);

    insertRow('audit_logs', [
        'school_id' => $this->schoolB, 'actor_type' => 'system', 'action' => 'school.profile.updated',
    ]);
})->throws(QueryException::class, 'row-level security');

it('mewajibkan actor_user_id untuk actor user', function () {
    DB::insert("INSERT INTO audit_logs (actor_type, action) VALUES ('user', 'auth.login.failed')");
})->throws(QueryException::class, 'audit_logs_actor_user_check');
