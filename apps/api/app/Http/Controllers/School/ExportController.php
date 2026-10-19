<?php

namespace App\Http\Controllers\School;

use App\Domain\Attendance\AttendanceReports;
use App\Domain\Attendance\ManageExports;
use App\Domain\Tenancy\ClassScope;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ekspor laporan (CSV/XLSX) berjalan async. Hanya peminta yang dapat melihat dan mengunduh hasilnya.
 */
final class ExportController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ClassScope $scope,
        private readonly ManageExports $exports,
    ) {}

    public function store(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $base = $request->validate([
            'report' => ['required', Rule::in(ManageExports::REPORTS)],
            'format' => ['required', Rule::in(ManageExports::FORMATS)],
            'filters' => ['required', 'array'],
        ]);
        $filters = $this->filters($base['report'], (array) $base['filters']);

        return response()->json(['data' => $this->exports->request($this->tenant->school(), $actor, $base['report'], $base['format'], $filters, $this->scope->classIds())], 202);
    }

    public function index(Request $request, School $school): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->exports->list($this->tenant->school(), $actor)])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, School $school, string $export): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->exports->show($this->tenant->school(), $actor, $export)])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request, School $school, string $export): BinaryFileResponse
    {
        // Tanda tangan dicek setelah otorisasi izin (middleware); tidak valid/kedaluwarsa = 404 agar tidak membocorkan keberadaan berkas.
        if (! $request->hasValidSignature()) {
            throw new NotFoundHttpException('Berkas ekspor tidak tersedia.');
        }
        /** @var User $actor */
        $actor = $request->user();
        $file = $this->exports->download($this->tenant->school(), $actor, $export);

        return response()->download($file['path'], $file['name'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'no-store, private']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function filters(string $report, array $input): array
    {
        $rules = match ($report) {
            'daily' => ['date' => ['required', 'date_format:Y-m-d'], 'class_id' => ['nullable', 'uuid'], 'grade_level' => ['nullable', 'integer', 'between:1,13']],
            'monthly' => ['month' => ['required', 'date_format:Y-m'], 'class_id' => ['required', 'uuid']],
            'events' => ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filters.from'], 'result' => ['nullable', Rule::in(['duplicate', 'rejected', 'needs_review'])]],
            default => ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:filters.from'], 'class_id' => ['nullable', 'uuid'], 'grade_level' => ['nullable', 'integer', 'between:1,13']],
        };
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed["filters.{$key}"] = $rule;
        }
        $valid = validator(['filters' => $input], $prefixed)->validate()['filters'];

        if (isset($valid['from'], $valid['to']) && strtotime($valid['to']) - strtotime($valid['from']) > AttendanceReports::MAX_RANGE_DAYS * 86400) {
            throw ValidationException::withMessages(['filters.to' => 'Rentang laporan maksimal '.AttendanceReports::MAX_RANGE_DAYS.' hari.']);
        }

        return array_filter($valid, fn ($v) => $v !== null);
    }
}
