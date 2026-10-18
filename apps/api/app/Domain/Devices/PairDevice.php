<?php

namespace App\Domain\Devices;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Terminal menukar kode pairing dengan token perangkat. Klaim bersifat atomik di database (kode cocok, belum
 * kedaluwarsa, sekali pakai), sehingga dua terminal yang memakai kode sama tidak mungkin sama-sama berhasil.
 * Alasan penolakan sengaja seragam agar kode tidak dapat ditebak bertahap.
 */
final class PairDevice
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly DeviceSecrets $secrets,
    ) {}

    /**
     * @return array{token: string, device_id: string, school_id: string}
     */
    public function claim(string $code): array
    {
        $token = $this->secrets->newToken();

        $row = DB::selectOne(
            "SELECT id, school_id FROM claim_device_pairing(decode(?, 'hex'), decode(?, 'hex'))",
            [$this->secrets->pairingCodeHashHex($code), $this->secrets->tokenHashHex($token)],
        );
        if ($row === null) {
            throw ApiProblem::pairingCodeInvalid();
        }

        $deviceId = (string) $row->id;
        $schoolId = (string) $row->school_id;
        $this->database->runInSchool($schoolId, fn () => $this->audit->record('school.device.paired', $schoolId, 'device', $deviceId));

        return ['token' => $token, 'device_id' => $deviceId, 'school_id' => $schoolId];
    }
}
