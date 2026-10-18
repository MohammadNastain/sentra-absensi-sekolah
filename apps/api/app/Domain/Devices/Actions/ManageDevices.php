<?php

namespace App\Domain\Devices\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Devices\DeviceSecrets;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\Device;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Siklus hidup perangkat terminal dari dashboard: daftar → kode pairing → (terminal mengklaim) → cabut.
 * Perangkat yang dicabut tidak bisa dipakai ulang; menggantinya berarti mendaftarkan perangkat baru (D-30).
 * Kode pairing mentah hanya dikembalikan sekali kepada admin.
 */
final class ManageDevices
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly DeviceSecrets $secrets,
    ) {}

    /**
     * @return array{device: Device, pairing_code: string}
     */
    public function register(School $school, User $actor, string $name): array
    {
        return $this->run($school, function () use ($school, $actor, $name) {
            // Kunci baris sekolah agar dua pendaftaran bersamaan tidak sama-sama lolos batas.
            $limit = (int) DB::table('schools')->where('id', $school->id)->lockForUpdate()->value('device_limit');
            $used = Device::query()->where('school_id', $school->id)->where('status', '<>', Device::STATUS_REVOKED)->count();
            if ($used >= $limit) {
                throw ApiProblem::deviceLimitReached($limit);
            }

            $code = $this->secrets->newPairingCode();
            $id = (string) DB::scalar(
                "INSERT INTO devices (school_id, name, pairing_code_hash, pairing_expires_at, created_by_user_id)
                 VALUES (?, ?, decode(?, 'hex'), now() + make_interval(mins => ?), ?) RETURNING id",
                [$school->id, trim($name), $this->secrets->pairingCodeHashHex($code), DeviceSecrets::PAIRING_TTL_MINUTES, $actor->id],
            );
            $device = Device::query()->findOrFail($id);
            $this->audit->record('school.device.registered', $school->id, 'device', $device->id, null, $this->snapshot($device), null, $actor);

            return ['device' => $device, 'pairing_code' => $code];
        });
    }

    /**
     * Kode baru untuk perangkat yang belum dipasangkan (kode lama kedaluwarsa atau terlewat).
     *
     * @return array{device: Device, pairing_code: string}
     */
    public function renewPairingCode(School $school, User $actor, string $deviceId): array
    {
        return $this->run($school, function () use ($school, $actor, $deviceId) {
            $device = $this->lock($school, $deviceId);
            if ($device->status !== Device::STATUS_PENDING) {
                throw ApiProblem::invalidStateTransition('Kode pairing hanya dapat dibuat untuk perangkat yang belum dipasangkan.');
            }

            $code = $this->secrets->newPairingCode();
            DB::update(
                "UPDATE devices SET pairing_code_hash = decode(?, 'hex'), pairing_expires_at = now() + make_interval(mins => ?), updated_at = now() WHERE id = ?",
                [$this->secrets->pairingCodeHashHex($code), DeviceSecrets::PAIRING_TTL_MINUTES, $device->id],
            );
            $device->refresh();
            $this->audit->record('school.device.pairing_code_renewed', $school->id, 'device', $device->id, null, $this->snapshot($device), null, $actor);

            return ['device' => $device, 'pairing_code' => $code];
        });
    }

    public function rename(School $school, User $actor, string $deviceId, string $name): Device
    {
        return $this->run($school, function () use ($school, $actor, $deviceId, $name) {
            $device = $this->lock($school, $deviceId);
            $before = $this->snapshot($device);
            $device->forceFill(['name' => trim($name)])->save();
            $this->audit->record('school.device.updated', $school->id, 'device', $device->id, $before, $this->snapshot($device), null, $actor);

            return $device;
        });
    }

    /** Pencabutan langsung efektif: request berikutnya dari perangkat itu ditolak DEVICE_REVOKED. */
    public function revoke(School $school, User $actor, string $deviceId, string $reason): Device
    {
        return $this->run($school, function () use ($school, $actor, $deviceId, $reason) {
            $device = $this->lock($school, $deviceId);
            if ($device->status === Device::STATUS_REVOKED) {
                throw ApiProblem::invalidStateTransition('Perangkat sudah dicabut.');
            }

            $before = $this->snapshot($device);
            DB::update(
                "UPDATE devices SET status = 'revoked', revoked_at = now(), revoked_reason = ?, pairing_code_hash = NULL, pairing_expires_at = NULL, updated_at = now() WHERE id = ?",
                [$reason, $device->id],
            );
            $device->refresh();
            $this->audit->record('school.device.revoked', $school->id, 'device', $device->id, $before, $this->snapshot($device), $reason, $actor);

            return $device;
        });
    }

    private function lock(School $school, string $deviceId): Device
    {
        return Device::query()->where('school_id', $school->id)->lockForUpdate()->find($deviceId) ?? throw new NotFoundHttpException;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function run(School $school, callable $work): mixed
    {
        return $this->database->runInSchool($school->id, fn () => DB::transaction(fn () => $work()));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Device $device): array
    {
        return ['name' => $device->name, 'device_type' => $device->device_type, 'status' => $device->status, 'revoked_reason' => $device->revoked_reason];
    }
}
