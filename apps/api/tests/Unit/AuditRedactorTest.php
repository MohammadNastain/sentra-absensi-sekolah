<?php

use App\Domain\Audit\AuditRedactor;

// U03 — field sensitif tidak pernah masuk before/after.

it('U03: membuang field di luar allowlist, termasuk password, token, dan secret 2FA', function () {
    $redacted = (new AuditRedactor)->redact('user', [
        'email' => 'guru@example.test',
        'name' => 'Guru',
        'status' => 'active',
        'password' => 'rahasia',
        'remember_token' => 'abc',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => '["x"]',
        'token_hash' => 'deadbeef',
    ]);

    expect($redacted)->toBe(['email' => 'guru@example.test', 'name' => 'Guru', 'status' => 'active']);
});

it('U03: undangan tidak menyimpan hash token', function () {
    $redacted = (new AuditRedactor)->redact('membership_invitation', [
        'email' => 'guru@example.test',
        'role_codes' => ['principal'],
        'token_hash' => 'deadbeef',
        'token' => 'mentah',
    ]);

    expect(array_keys($redacted))->toBe(['email', 'role_codes']);
});

it('meneruskan null apa adanya', function () {
    expect((new AuditRedactor)->redact('school', null))->toBeNull();
});

it('menolak jenis entitas yang belum punya allowlist', function () {
    (new AuditRedactor)->redact('entitas_baru', ['a' => 1]);
})->throws(InvalidArgumentException::class, 'allowlist');
