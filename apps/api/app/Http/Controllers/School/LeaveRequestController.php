<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\AssistedAttendance;
use App\Domain\Attendance\ManageLeaveRequests;
use App\Domain\Identity\Permission;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pengajuan dan keputusan izin/sakit/dispensasi. Wali kelas hanya bekerja pada siswa kelasnya (ClassScope);
 * keputusan hanya untuk yang berizin `school.leave.approve`.
 */
final class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClassScope $scope,
        private readonly ManageLeaveRequests $leave,
        private readonly AssistedAttendance $students,
    ) {}

    /** Pencarian siswa untuk formulir pengajuan; wali kelas hanya melihat kelasnya. */
    public function students(Request $request, School $school): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'max:100']])['q'];

        return response()->json(['data' => $this->students->search($this->tenant->school(), $q, $this->scope->classIds())])->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request, School $school): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])]])['status'] ?? null;

        return response()->json(['data' => $this->leave->list($this->tenant->school(), $status, $this->scope->classIds())])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate([
            'student_id' => ['required', 'uuid'],
            'leave_type' => ['required', Rule::in(ManageLeaveRequests::TYPES)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$request->input('start_date').' +30 days'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['end_date.before_or_equal' => 'Rentang izin maksimal 31 hari.', 'reason.min' => 'Alasan minimal 5 karakter.']);

        return response()->json(['data' => $this->leave->create($this->tenant->school(), $actor, $v, $this->scope->classIds())], 201);
    }

    public function attach(Request $request, School $school, string $leave): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']], [
            'file.mimes' => 'Lampiran harus berupa PDF, JPG, atau PNG.',
            'file.max' => 'Ukuran lampiran maksimal 5 MB.',
        ]);

        return response()->json(['data' => $this->leave->attach($this->tenant->school(), $actor, $leave, $request->file('file'), $this->scope->classIds())]);
    }

    public function attachment(School $school, string $leave): StreamedResponse
    {
        $file = $this->leave->attachment($this->tenant->school(), $leave, $this->scope->classIds());

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'], 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request, School $school, string $leave): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,reject', 'min:10'],
        ], ['note.required_if' => 'Alasan penolakan wajib diisi.', 'note.min' => 'Catatan minimal 10 karakter.']);

        return response()->json(['data' => $this->leave->decide($this->tenant->school(), $actor, $leave, $v['decision'], isset($v['note']) ? trim($v['note']) : null)]);
    }

    public function cancel(Request $request, School $school, string $leave): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $v = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']], ['reason.min' => 'Alasan minimal 10 karakter.']);

        return response()->json(['data' => $this->leave->cancel(
            $this->tenant->school(), $actor, $leave, trim($v['reason']), $this->tenant->can(Permission::SchoolLeaveApprove), $this->scope->classIds(),
        )]);
    }
}
