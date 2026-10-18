<?php

namespace App\Domain\Attendance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Devices\DeviceSecrets;
use App\Domain\Identity\Permission;
use App\Models\Device;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Sesi bantuan petugas di terminal (ARCHITECTURE §6.3): login email+password (+ kode 2FA bila akun memakainya) di
 * perangkat yang sudah dipasangkan. Sesi terikat ke satu perangkat dan satu petugas, idle 10 menit, maksimum
 * 30 menit; hanya hash token yang disimpan dan token hanya ada di memori terminal. Semua kegagalan login
 * memberi jawaban yang sama agar akun tidak dapat ditebak. Konteks sekolah (RLS) harus sudah aktif.
 */
final class AssistSessions
{
    public const IDLE_SECONDS = 600;

    public const MAX_SECONDS = 1800;

    public function __construct(
        private readonly DeviceSecrets $secrets,
        private readonly AuditLogger $audit,
        private readonly TwoFactorAuthenticationProvider $twoFactor,
    ) {}

    /**
     * @return array{token: string, expires_at: Carbon, user: User}
     */
    public function start(School $school, Device $device, string $email, string $password, ?string $code): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
        // Selalu menghitung hash agar waktu jawab tidak membocorkan keberadaan akun.
        $passwordOk = Hash::check($password, $user instanceof User ? $user->getAuthPassword() : Hash::make('tidak-ada-akun'));

        if (! $user instanceof User || ! $passwordOk || ! $user->isActive() || ! $this->mayAssist($school, $user) || ! $this->secondFactorOk($user, $code)) {
            throw ApiProblem::assistLoginFailed();
        }

        $token = $this->secrets->newToken();
        $expires = Carbon::now()->addSeconds(self::MAX_SECONDS);

        DB::transaction(function () use ($school, $device, $user, $token, $expires) {
            DB::update(
                "UPDATE assist_sessions SET ended_at = ?, ended_reason = 'replaced' WHERE school_id = ? AND device_id = ? AND ended_at IS NULL",
                [Carbon::now(), $school->id, $device->id],
            );
            DB::insert(
                "INSERT INTO assist_sessions (school_id, device_id, user_id, token_hash, started_at, last_activity_at, expires_at) VALUES (?, ?, ?, decode(?, 'hex'), ?, ?, ?)",
                [$school->id, $device->id, $user->id, $this->secrets->tokenHashHex($token), Carbon::now(), Carbon::now(), $expires],
            );
            $this->audit->record('school.assist_session.started', $school->id, 'device', $device->id, null, ['name' => $device->name], null, $user);
        });

        return ['token' => $token, 'expires_at' => $expires, 'user' => $user];
    }

    /**
     * @return array{id: string, user: User}
     */
    public function resolve(string $token, Device $device): array
    {
        $row = DB::selectOne(
            "SELECT id, user_id, device_id, last_activity_at, expires_at, ended_at FROM assist_sessions WHERE token_hash = decode(?, 'hex')",
            [$this->secrets->tokenHashHex($token)],
        );
        if ($row === null || (string) $row->device_id !== $device->id || $row->ended_at !== null) {
            throw ApiProblem::assistSessionInvalid();
        }

        $now = Carbon::now();
        $expired = Carbon::parse((string) $row->expires_at)->lte($now);
        $idle = Carbon::parse((string) $row->last_activity_at)->addSeconds(self::IDLE_SECONDS)->lte($now);
        if ($expired || $idle) {
            DB::update('UPDATE assist_sessions SET ended_at = ?, ended_reason = ? WHERE id = ?', [$now, $expired ? 'expired' : 'idle', $row->id]);
            throw ApiProblem::assistSessionInvalid();
        }

        $user = User::query()->find((string) $row->user_id);
        if (! $user instanceof User || ! $user->isActive() || ! $this->mayAssist($device->school_id, $user)) {
            DB::update("UPDATE assist_sessions SET ended_at = ?, ended_reason = 'expired' WHERE id = ?", [$now, $row->id]);
            throw ApiProblem::assistSessionInvalid();
        }

        DB::update('UPDATE assist_sessions SET last_activity_at = ? WHERE id = ?', [$now, $row->id]);

        return ['id' => (string) $row->id, 'user' => $user];
    }

    public function end(string $sessionId, string $reason = 'logout'): void
    {
        DB::update('UPDATE assist_sessions SET ended_at = ?, ended_reason = ? WHERE id = ? AND ended_at IS NULL', [Carbon::now(), $reason, $sessionId]);
    }

    /** Mengakhiri semua sesi bantuan perangkat (dipakai saat perangkat dicabut). */
    public function endAllForDevice(string $schoolId, string $deviceId, string $reason): void
    {
        DB::update('UPDATE assist_sessions SET ended_at = ?, ended_reason = ? WHERE school_id = ? AND device_id = ? AND ended_at IS NULL', [Carbon::now(), $reason, $schoolId, $deviceId]);
    }

    private function mayAssist(School|string $school, User $user): bool
    {
        $schoolId = $school instanceof School ? $school->id : $school;
        $membership = SchoolMembership::query()->with('roles')->where('school_id', $schoolId)->where('user_id', $user->id)
            ->where('status', SchoolMembership::STATUS_ACTIVE)->first();
        if ($membership === null) {
            return false;
        }

        return in_array(Permission::SchoolAttendanceAssist, Permission::grantedTo($membership->roleEnums()), true);
    }

    private function secondFactorOk(User $user, ?string $code): bool
    {
        if (! $user->hasConfirmedTwoFactor()) {
            return true;
        }
        $secret = $user->getAttributes()['two_factor_secret'] ?? null;
        if (! is_string($secret) || $code === null || $code === '') {
            return false;
        }

        return $this->twoFactor->verify(decrypt($secret), $code);
    }
}
