<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\ManageCorrections;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi absensi: langsung (admin), pengajuan (wali kelas, dibatasi ClassScope), keputusan, dan riwayat.
 */
final class CorrectionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClassScope $scope,
        private readonly ManageCorrections $corrections,
    ) {}

    public function apply(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->corrections->applyDirect($this->tenant->school(), $actor, $this->validated($request))], 201);
    }

    public function request(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->corrections->request($this->tenant->school(), $actor, $this->validated($request), $this->scope->classIds())], 201);
    }

    public function index(Request $request, School $school): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])]])['status'] ?? null;

        return response()->json(['data' => $this->corrections->list($this->tenant->school(), $status, $this->scope->classIds())])->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, School $school, string $correction): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'min:10', 'max:500', 'required_if:decision,reject'],
        ], ['note.required_if' => 'Alasan penolakan wajib diisi.', 'note.min' => 'Catatan minimal 10 karakter.']);

        return response()->json(['data' => $this->corrections->decide($this->tenant->school(), $actor, $correction, $v['decision'], isset($v['note']) ? trim($v['note']) : null)]);
    }

    public function cancel(Request $request, School $school, string $correction): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.min' => 'Alasan minimal 10 karakter.']);

        return response()->json(['data' => $this->corrections->cancel($this->tenant->school(), $actor, $correction, trim($v['reason']), $this->scope->classIds())]);
    }

    public function history(Request $request, School $school): JsonResponse
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'student_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->corrections->history($this->tenant->school(), $v['from'], $v['to'], $v['student_id'] ?? null)])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array{student_id: string, date: string, type: string, value: string, reason: string}
     */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'student_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['status_change', 'early_leave_decision', ...ManageCorrections::TIMES])],
            'value' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Alasan minimal 10 karakter.']);

        $allowed = match ($v['type']) {
            'status_change' => ManageCorrections::STATUSES,
            'early_leave_decision' => ManageCorrections::EARLY_LEAVE,
            default => null,
        };
        $valid = $allowed === null ? (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['value']) : in_array($v['value'], $allowed, true);
        if (! $valid) {
            throw ValidationException::withMessages(['value' => 'Nilai tidak sesuai dengan jenis koreksi.']);
        }
        $v['reason'] = trim($v['reason']);

        return $v;
    }
}
