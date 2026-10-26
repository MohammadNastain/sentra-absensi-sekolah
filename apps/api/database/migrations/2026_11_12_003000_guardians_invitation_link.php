<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0011 §1 (G2) — wali dibuat sebelum akunnya ada; undangan yang dikirim kepadanya ditautkan lewat `invitation_id`,
 * sehingga saat undangan diterima keanggotaan baru dapat diisikan ke `guardians.membership_id`. Satu undangan = satu wali.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE guardians ADD COLUMN invitation_id uuid NULL;
            ALTER TABLE guardians ADD CONSTRAINT guardians_invitation_id_unique UNIQUE (invitation_id);
            ALTER TABLE guardians ADD CONSTRAINT guardians_invitation_fk FOREIGN KEY (invitation_id) REFERENCES membership_invitations (id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE guardians DROP CONSTRAINT guardians_invitation_fk;
            ALTER TABLE guardians DROP CONSTRAINT guardians_invitation_id_unique;
            ALTER TABLE guardians DROP COLUMN invitation_id;
            SQL);
    }
};
