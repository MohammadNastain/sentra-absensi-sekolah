<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// T10 — RLS diuji langsung di DB sebagai sentra_app (TAHAP_1_FONDASI §8).

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->userA = createUserRow('a@example.test');
    $this->userB = createUserRow('b@example.test');

    $ctx = $this->dbContext();
    $this->membershipA = $ctx->runInSchool($this->schoolA, fn () => insertRow('school_memberships', [
        'school_id' => $this->schoolA, 'user_id' => $this->userA,
    ]));
    $this->membershipB = $ctx->runInSchool($this->schoolB, fn () => insertRow('school_memberships', [
        'school_id' => $this->schoolB, 'user_id' => $this->userB,
    ]));
    $ctx->runInSchool($this->schoolA, fn () => DB::insert(
        'INSERT INTO membership_roles (school_id, membership_id, role_code) VALUES (?, ?, ?)',
        [$this->schoolA, $this->membershipA, 'school_admin'],
    ));
    $ctx->runInSchool($this->schoolB, fn () => DB::insert(
        'INSERT INTO membership_roles (school_id, membership_id, role_code) VALUES (?, ?, ?)',
        [$this->schoolB, $this->membershipB, 'principal'],
    ));
    $ctx->reset();
});

it('berjalan sebagai role aplikasi tanpa superuser dan tanpa BYPASSRLS', function () {
    $role = DB::selectOne('SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($role->rolname)->toBe('sentra_app')
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

it('tidak menampilkan baris apa pun tanpa konteks', function (string $table) {
    expect(DB::scalar("SELECT count(*) FROM {$table}"))->toBe(0);
})->with(['school_memberships', 'membership_roles']);

it('tidak menampilkan baris setelah konteks di-reset ke string kosong', function () {
    $this->dbContext()->setSchool($this->schoolA);
    $this->dbContext()->reset();

    expect(DB::scalar('SELECT current_setting(\'app.school_id\', true)'))->toBe('')
        ->and(DB::scalar('SELECT count(*) FROM school_memberships'))->toBe(0);
});

it('hanya menampilkan baris sekolah konteks', function () {
    $this->dbContext()->setSchool($this->schoolA);

    expect(DB::table('school_memberships')->pluck('id')->all())->toBe([$this->membershipA])
        ->and(DB::table('membership_roles')->pluck('school_id')->unique()->all())->toBe([$this->schoolA]);
});

it('menolak INSERT baris sekolah lain dari konteks A', function () {
    $this->dbContext()->setSchool($this->schoolA);

    insertRow('school_memberships', ['school_id' => $this->schoolB, 'user_id' => $this->userA]);
})->throws(QueryException::class, 'row-level security');

it('tidak dapat mengubah atau menghapus baris sekolah lain dari konteks A', function () {
    $this->dbContext()->setSchool($this->schoolA);

    $updated = DB::update('UPDATE school_memberships SET status_reason = ? WHERE id = ?', ['x', $this->membershipB]);
    $deleted = DB::delete('DELETE FROM membership_roles WHERE membership_id = ?', [$this->membershipB]);

    expect($updated)->toBe(0)->and($deleted)->toBe(0);

    $this->dbContext()->setSchool($this->schoolB);
    expect(DB::scalar('SELECT status_reason FROM school_memberships WHERE id = ?', [$this->membershipB]))->toBeNull()
        ->and(DB::scalar('SELECT count(*) FROM membership_roles'))->toBe(1);
});

it('menolak memindahkan baris ke sekolah lain lewat UPDATE', function () {
    $this->dbContext()->setSchool($this->schoolA);

    DB::update('UPDATE school_memberships SET school_id = ? WHERE id = ?', [$this->schoolB, $this->membershipA]);
})->throws(QueryException::class, 'row-level security');

it('mengizinkan user membaca keanggotaan & perannya sendiri lintas sekolah tanpa konteks sekolah', function () {
    $this->dbContext()->setUser($this->userA);

    expect(DB::table('school_memberships')->pluck('id')->all())->toBe([$this->membershipA])
        ->and(DB::table('membership_roles')->pluck('role_code')->all())->toBe(['school_admin']);
});

it('tidak mengizinkan konteks user menulis keanggotaan', function () {
    $this->dbContext()->setUser($this->userA);

    $updated = DB::update('UPDATE school_memberships SET status_reason = ? WHERE id = ?', ['x', $this->membershipA]);

    expect($updated)->toBe(0);
});

it('menolak FK komposit peran yang menunjuk keanggotaan sekolah lain', function () {
    // Konteks A, school_id A, tetapi membership milik B: FK (school_id, membership_id) gagal.
    $this->dbContext()->setSchool($this->schoolA);

    DB::insert(
        'INSERT INTO membership_roles (school_id, membership_id, role_code) VALUES (?, ?, ?)',
        [$this->schoolA, $this->membershipB, 'duty_officer'],
    );
})->throws(QueryException::class, 'membership_roles_membership_fk');

it('menolak nilai konteks yang bukan uuid alih-alih mengabaikannya', function () {
    DB::select("SELECT set_config('app.school_id', 'bukan-uuid', false)");

    DB::scalar('SELECT count(*) FROM school_memberships');
})->throws(QueryException::class, 'invalid input syntax for type uuid');

it('mencabut hak tulis tabel roles dari role aplikasi', function () {
    DB::insert('INSERT INTO roles (code, name) VALUES (?, ?)', ['hacker', 'Hacker']);
})->throws(QueryException::class, 'permission denied');

it('tidak mengizinkan role aplikasi menjalankan DDL', function () {
    DB::statement('CREATE TABLE coba (id int)');
})->throws(QueryException::class, 'permission denied');
