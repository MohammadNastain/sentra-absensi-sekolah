<?php

namespace App\Domain\Tenancy;

use App\Domain\Audit\AuditLogger;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Akses dukungan (break-glass) Super Admin ke data satu sekolah (D-21). Setiap izin beralasan, berlaku
 * {@see self::DURATION_MINUTES} menit, dan dapat diakhiri lebih awal. Selama izin aktif, Super Admin bertindak
 * sebagai Admin Sekolah dan setiap request-nya dicatat ke audit log sekolah tersebut.
 */
class SupportAccess
{
    public const DURATION_MINUTES = 60;

    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Membuka izin baru; izin aktif sebelumnya untuk sekolah yang sama diakhiri agar hanya ada satu.
     *
     * @return array{id: string, expires_at: Carbon}
     */
    public function start(User $actor, School $school, string $reason): array
    {
        return DB::transaction(function () use ($actor, $school, $reason) {
            $now = Carbon::now();
            $expires = $now->copy()->addMinutes(self::DURATION_MINUTES);

            $this->database->runAsPlatform(function () use ($actor, $school, $now): void {
                DB::table('support_grants')
                    ->where('user_id', $actor->id)->where('school_id', $school->id)
                    ->whereNull('ended_at')->where('expires_at', '>', $now)
                    ->update(['ended_at' => $now]);
            });

            $id = $this->database->runAsPlatform(fn () => DB::table('support_grants')->insertGetId([
                'school_id' => $school->id,
                'user_id' => $actor->id,
                'reason' => $reason,
                'expires_at' => $expires,
                'created_at' => $now,
            ]));

            $this->auditInSchool($school, fn () => $this->audit->record(
                'platform.support.started', $school->id, 'support_grant', (string) $id,
                null, ['expires_at' => $expires->toIso8601String()], $reason, $actor,
            ));

            return ['id' => (string) $id, 'expires_at' => $expires];
        });
    }

    /** Mengakhiri semua izin aktif milik $actor untuk sekolah ini; mengembalikan true bila ada yang diakhiri. */
    public function end(User $actor, School $school): bool
    {
        return DB::transaction(function () use ($actor, $school) {
            $now = Carbon::now();

            $ended = $this->database->runAsPlatform(fn () => DB::table('support_grants')
                ->where('user_id', $actor->id)->where('school_id', $school->id)
                ->whereNull('ended_at')->where('expires_at', '>', $now)
                ->update(['ended_at' => $now]));

            if ($ended > 0) {
                $this->auditInSchool($school, fn () => $this->audit->record(
                    'platform.support.ended', $school->id, 'support_grant', null, null, null, null, $actor,
                ));
            }

            return $ended > 0;
        });
    }

    /**
     * Izin aktif milik $actor untuk sekolah ini, atau null. Dipakai ResolveSchoolMembership.
     *
     * @return array{id: string, expires_at: Carbon}|null
     */
    public function active(User $actor, School $school): ?array
    {
        $row = $this->database->runAsPlatform(fn () => DB::table('support_grants')
            ->where('user_id', $actor->id)->where('school_id', $school->id)
            ->whereNull('ended_at')->where('expires_at', '>', Carbon::now())
            ->orderByDesc('expires_at')->first());

        return $row === null ? null : ['id' => (string) $row->id, 'expires_at' => Carbon::parse($row->expires_at)];
    }

    /** Baris audit sekolah diizinkan RLS hanya bila konteks sekolah sama dengan school_id baris. */
    private function auditInSchool(School $school, callable $write): void
    {
        $this->database->runInSchool($school->id, $write);
    }
}
