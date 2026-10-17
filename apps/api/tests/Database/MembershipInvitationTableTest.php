<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->schoolA = createSchoolRow('sekolah-a');
    $this->schoolB = createSchoolRow('sekolah-b');
    $this->inviter = createUserRow('admin@example.test');

    $this->invite = fn (string $schoolId, string $email, string $token, array $extra = []) => $this->dbContext()->runInSchool(
        $schoolId,
        fn () => insertRow('membership_invitations', [
            'school_id' => $schoolId,
            'email' => $email,
            'role_codes' => '{principal}',
            'token_hash' => bytea(hash('sha256', $token, binary: true)),
            'invited_by_user_id' => $this->inviter,
            'expires_at' => now()->addDays(7),
            ...$extra,
        ]),
    );
});

it('menemukan undangan lewat hash token tanpa konteks sekolah, hanya satu baris yang cocok', function () {
    $id = ($this->invite)($this->schoolA, 'guru@example.test', 'token-rahasia-a');
    ($this->invite)($this->schoolB, 'guru@example.test', 'token-rahasia-b');

    expect(DB::scalar('SELECT count(*) FROM membership_invitations'))->toBe(0);

    $rows = DB::select('SELECT id, school_id FROM find_invitation_by_token_hash(?::bytea)', [
        bytea(hash('sha256', 'token-rahasia-a', binary: true)),
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($id)
        ->and($rows[0]->school_id)->toBe($this->schoolA);
});

it('tidak mengembalikan apa pun untuk token yang tidak dikenal', function () {
    ($this->invite)($this->schoolA, 'guru@example.test', 'token-rahasia-a');

    $rows = DB::select('SELECT id FROM find_invitation_by_token_hash(?::bytea)', [
        bytea(hash('sha256', 'token-lain', binary: true)),
    ]);

    expect($rows)->toBe([]);
});

it('mengizinkan user membaca undangan ke email-nya sendiri (case-insensitive) dan bukan milik orang lain', function () {
    $mine = ($this->invite)($this->schoolA, 'Guru@Example.test', 'token-1');
    ($this->invite)($this->schoolB, 'orang-lain@example.test', 'token-2');
    $this->dbContext()->setUser(createUserRow('guru@example.test'));

    expect(DB::table('membership_invitations')->pluck('id')->all())->toBe([$mine]);
});

it('hanya mengizinkan satu undangan tertunda per email per sekolah (I05)', function () {
    ($this->invite)($this->schoolA, 'guru@example.test', 'token-1');
    ($this->invite)($this->schoolA, 'GURU@example.test', 'token-2');
})->throws(QueryException::class, 'membership_invitations_pending_unique');

it('mengizinkan undangan baru setelah undangan lama dicabut', function () {
    ($this->invite)($this->schoolA, 'guru@example.test', 'token-1', ['revoked_at' => now()]);

    expect(($this->invite)($this->schoolA, 'guru@example.test', 'token-2'))->toBeString();
});

it('menolak kode peran yang tidak dikenal', function () {
    $this->dbContext()->runInSchool($this->schoolA, fn () => insertRow('membership_invitations', [
        'school_id' => $this->schoolA,
        'email' => 'x@example.test',
        'role_codes' => '{super_admin}',
        'token_hash' => bytea(hash('sha256', 't', binary: true)),
        'invited_by_user_id' => $this->inviter,
        'expires_at' => now()->addDay(),
    ]));
})->throws(QueryException::class, 'membership_invitations_role_codes_check');

it('menolak email user duplikat tanpa memandang huruf besar/kecil', function () {
    createUserRow('sama@example.test');
    createUserRow('SAMA@example.test');
})->throws(QueryException::class, 'users_email_unique');

it('menolak zona waktu sekolah di luar WIB/WITA/WIT', function () {
    insertRow('schools', [
        'code' => 'sekolah-c', 'name' => 'C', 'education_levels' => '{SD}', 'timezone' => 'Asia/Singapore',
    ]);
})->throws(QueryException::class, 'schools_timezone_check');
