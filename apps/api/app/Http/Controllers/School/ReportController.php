<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\AttendanceReports;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Laporan kehadiran (hanya baca). Wali kelas hanya melihat kelas yang ia ampu (ClassScope); filter kelas di luar
 * jangkauannya menghasilkan laporan kosong, bukan kebocoran.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClassScope $scope,
        private readonly AttendanceReports $reports,
    ) {}

    public function summary(Request $request, School $school): JsonResponse
    {
        [$from, $to, $class, $grade] = $this->range($request);

        return $this->respond($this->reports->summary($this->tenant->school(), $from, $to, $class, $grade, $this->scope->classIds()));
    }

    public function students(Request $request, School $school): JsonResponse
    {
        [$from, $to, $class, $grade] = $this->range($request);

        return $this->respond($this->reports->students($this->tenant->school(), $from, $to, $class, $grade, $this->scope->classIds()));
    }

    public function lateness(Request $request, School $school): JsonResponse
    {
        [$from, $to, $class, $grade] = $this->range($request);

        return $this->respond($this->reports->lateness($this->tenant->school(), $from, $to, $class, $grade, $this->scope->classIds()));
    }

    public function daily(Request $request, School $school): JsonResponse
    {
        $v = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'class_id' => ['nullable', 'uuid'], 'grade_level' => ['nullable', 'integer', 'between:1,13']]);

        return $this->respond($this->reports->daily($this->tenant->school(), $v['date'], $v['class_id'] ?? null, isset($v['grade_level']) ? (int) $v['grade_level'] : null, $this->scope->classIds()));
    }

    public function monthly(Request $request, School $school): JsonResponse
    {
        $v = $request->validate(['month' => ['required', 'date_format:Y-m'], 'class_id' => ['required', 'uuid']]);

        return $this->respond($this->reports->monthly($this->tenant->school(), $v['month'], $v['class_id'], $this->scope->classIds()));
    }

    public function events(Request $request, School $school): JsonResponse
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'result' => ['nullable', Rule::in(['duplicate', 'rejected', 'needs_review'])],
        ]);

        return $this->respond($this->reports->rejectedEvents($this->tenant->school(), $v['from'], $v['to'], $v['result'] ?? null));
    }

    /**
     * @return array{string, string, string|null, int|null}
     */
    private function range(Request $request): array
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'class_id' => ['nullable', 'uuid'],
            'grade_level' => ['nullable', 'integer', 'between:1,13'],
        ]);
        if (strtotime($v['to']) - strtotime($v['from']) > AttendanceReports::MAX_RANGE_DAYS * 86400) {
            throw ValidationException::withMessages(['to' => 'Rentang laporan maksimal '.AttendanceReports::MAX_RANGE_DAYS.' hari.']);
        }

        return [$v['from'], $v['to'], $v['class_id'] ?? null, isset($v['grade_level']) ? (int) $v['grade_level'] : null];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
