<?php

namespace App\Http\Controllers\School;

use App\Domain\Devices\Actions\ManageDevices;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Models\Device;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pengelolaan perangkat terminal oleh admin sekolah. Kode pairing mentah hanya ada di respons pembuatan
 * dan pembaruan kode (`Cache-Control: no-store`).
 */
final class DeviceController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageDevices $devices) {}

    public function index(): JsonResponse
    {
        $rows = Device::query()->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'pending_pairing' THEN 1 ELSE 2 END")->orderByDesc('created_at')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (Device $d) => self::present($d))->all()]);
    }

    public function show(School $school, string $device): JsonResponse
    {
        $found = Device::query()->find($device) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/\S/']]);
        $created = $this->devices->register($this->tenant->school(), $this->actor($request), $validated['name']);

        return $this->withCode($created, 201);
    }

    public function update(Request $request, School $school, string $device): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/\S/']]);

        return response()->json(['data' => self::present($this->devices->rename($this->tenant->school(), $this->actor($request), $device, $validated['name']))]);
    }

    public function renewPairingCode(Request $request, School $school, string $device): JsonResponse
    {
        return $this->withCode($this->devices->renewPairingCode($this->tenant->school(), $this->actor($request), $device), 200);
    }

    public function revoke(ReasonRequest $request, School $school, string $device): JsonResponse
    {
        return response()->json(['data' => self::present($this->devices->revoke($this->tenant->school(), $this->actor($request), $device, $request->reason()))]);
    }

    /**
     * @param  array{device: Device, pairing_code: string}  $result
     */
    private function withCode(array $result, int $status): JsonResponse
    {
        return response()->json(['data' => ['device' => self::present($result['device']), 'pairing_code' => $result['pairing_code']]], $status)
            ->header('Cache-Control', 'no-store, private');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Device $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'device_type' => $d->device_type,
            'status' => $d->status,
            'online' => $d->isOnline(),
            'pairing_expires_at' => $d->status === Device::STATUS_PENDING ? $d->pairing_expires_at?->toIso8601String() : null,
            'paired_at' => $d->paired_at?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'revoked_reason' => $d->revoked_reason,
        ];
    }
}
