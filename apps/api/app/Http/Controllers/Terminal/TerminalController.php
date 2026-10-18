<?php

namespace App\Http\Controllers\Terminal;

use App\Domain\Attendance\RecordScanAction;
use App\Domain\Devices\PairDevice;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint terminal (guard `device`). Terminal tidak pernah memegang kredensial admin; token perangkat hanya
 * berlaku untuk /terminal/*.
 */
final class TerminalController extends Controller
{
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PairDevice $pairing,
        private readonly RecordScanAction $scans,
    ) {}

    public function pair(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'min:6', 'max:20']]);
        $claimed = $this->pairing->claim($validated['code']);

        $school = School::query()->findOrFail($claimed['school_id']);

        return response()->json(['data' => ['school' => ['name' => $school->name]]])
            ->header('Cache-Control', 'no-store, private')
            ->cookie(cookie(AuthenticateDevice::COOKIE, $claimed['token'], self::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'strict'));
    }

    public function config(): JsonResponse
    {
        $school = $this->tenant->school();
        $device = $this->tenant->device();

        return response()->json(['data' => [
            'school' => ['name' => $school->name, 'timezone' => $school->timezone],
            'device' => ['id' => $device->id, 'name' => $device->name],
            'server_time' => Carbon::now()->toIso8601String(),
            'methods' => ['qr', 'rfid'],
            'show_photo' => false,
        ]]);
    }

    public function heartbeat(): JsonResponse
    {
        return response()->json(['data' => ['server_time' => Carbon::now()->toIso8601String()]]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $payload = $this->scans->record(
            $this->tenant->school(),
            $this->tenant->device(),
            $data['value'],
            $data['idempotency_key'],
            isset($data['captured_at']) ? Carbon::parse($data['captured_at']) : null,
        );

        return response()->json(['data' => $payload])->header('Cache-Control', 'no-store, private');
    }

    /** Transaksi terakhir sekolah ini untuk layar terminal; hanya nama tampilan, tanpa nilai kredensial. */
    public function recent(): JsonResponse
    {
        $school = $this->tenant->school();
        $rows = DB::select(
            "SELECT e.event_type, e.result, e.reason_code, e.occurred_at_server, e.is_late, s.full_name, c.name AS class_name
             FROM attendance_events e
             LEFT JOIN students s ON s.school_id = e.school_id AND s.id = e.student_id
             LEFT JOIN class_enrollments en ON en.school_id = e.school_id AND en.student_id = e.student_id
                 AND en.start_date <= e.attendance_date AND (en.end_date IS NULL OR en.end_date >= e.attendance_date)
             LEFT JOIN classes c ON c.school_id = en.school_id AND c.id = en.class_id
             WHERE e.school_id = ? AND e.result IN ('accepted', 'duplicate', 'needs_review')
             ORDER BY e.occurred_at_server DESC LIMIT 10",
            [$school->id],
        );

        return response()->json(['data' => array_map(fn ($r) => [
            'result' => $r->result,
            'code' => $r->reason_code,
            'event_type' => $r->event_type,
            'display_name' => $r->full_name === null ? null : RecordScanAction::displayName((string) $r->full_name),
            'class_name' => $r->class_name,
            'is_late' => $r->is_late,
            'recorded_at_local' => Carbon::parse($r->occurred_at_server)->setTimezone($school->timezone)->format('H.i'),
        ], $rows)])->header('Cache-Control', 'no-store, private');
    }
}
