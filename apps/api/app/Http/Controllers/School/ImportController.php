<?php

namespace App\Http\Controllers\School;

use App\Domain\Imports\ImportFileException;
use App\Domain\Imports\ImportKind;
use App\Domain\Imports\ImportKinds;
use App\Domain\Imports\ManageImports;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Impor berkas CSV/XLSX: siswa, guru, dan jadwal mengajar memakai alur yang sama (unggah → validasi async →
 * preview → commit baris valid). Jenis impor menentukan kolom dan aturan validasinya (App\Domain\Imports\ImportKind).
 */
final class ImportController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageImports $imports) {}

    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        if (is_string($type) && in_array($type, ImportKinds::all(), true)) {
            $this->tenant->requireAnyFeature(ImportKinds::feature($type));
        }
        // Riwayat hanya menampilkan jenis impor yang fiturnya masih dimiliki sekolah.
        $entitled = array_values(array_filter(ImportKinds::all(), fn (string $t) => $this->tenant->hasFeature(ImportKinds::feature($t))));
        $page = ImportBatch::query()
            ->whereIn('type', $entitled)
            ->when(is_string($type) && $type !== '', fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (ImportBatch $b) => self::present($b))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function storeStudents(Request $request): JsonResponse
    {
        return $this->upload($request, ImportKinds::get(ImportKinds::STUDENTS));
    }

    public function storeTeachers(Request $request): JsonResponse
    {
        return $this->upload($request, ImportKinds::get(ImportKinds::TEACHERS));
    }

    public function storeTeachingSchedule(Request $request): JsonResponse
    {
        return $this->upload($request, ImportKinds::get(ImportKinds::TEACHING_SCHEDULE));
    }

    private function upload(Request $request, ImportKind $kind): JsonResponse
    {
        $this->tenant->requireAnyFeature(ImportKinds::feature($kind->type()));
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx']]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        try {
            $batch = $this->imports->upload($this->tenant->school(), $this->actor($request), $file, $kind);
        } catch (ImportFileException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json(['data' => self::present($batch)], 202);
    }

    public function show(School $school, string $import): JsonResponse
    {
        return response()->json(['data' => self::present($this->findEntitled($import))]);
    }

    public function rows(Request $request, School $school, string $import): JsonResponse
    {
        $batch = $this->findEntitled($import);
        $status = $request->query('status');

        $page = ImportRow::query()
            ->where('batch_id', $batch->id)
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('row_number')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (ImportRow $r) => [
                'row_number' => $r->row_number,
                'status' => $r->status,
                'values' => $r->payload,
                'errors' => $r->errors,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function commit(Request $request, School $school, string $import): JsonResponse
    {
        $this->findEntitled($import);
        $batch = $this->imports->commit($this->tenant->school(), $this->actor($request), $import);

        return response()->json(['data' => self::present($batch)]);
    }

    /** Baris yang gagal (atau dilewati saat commit) beserta kesalahannya, siap diperbaiki lalu diunggah ulang. */
    public function failedRows(School $school, string $import): StreamedResponse
    {
        $batch = $this->findEntitled($import);
        $rows = ImportRow::query()->where('batch_id', $batch->id)->whereIn('status', [ImportRow::STATUS_INVALID, ImportRow::STATUS_SKIPPED])->orderBy('row_number')->get();  // dimuat sebelum respons dikirim (konteks sekolah masih aktif)
        $columns = ImportKinds::get($batch->type)->columns();

        return $this->csv('perbaikan-'.pathinfo($batch->original_filename, PATHINFO_FILENAME).'.csv', function ($out) use ($rows, $columns): void {
            fputcsv($out, [...$columns, 'kesalahan'], escape: '');
            foreach ($rows as $row) {
                $values = array_map(fn (string $c) => self::safeCell($row->payload[$c] ?? null), $columns);
                $messages = implode('; ', array_map(fn (array $e) => $e['message'], $row->errors));
                fputcsv($out, [...$values, $messages], escape: '');
            }
        });
    }

    /** Templat CSV; `?type=` students (bawaan), teachers, atau teaching_schedule. */
    public function template(Request $request): StreamedResponse
    {
        $type = $request->query('type', ImportKinds::STUDENTS);
        $known = [ImportKinds::STUDENTS, ImportKinds::TEACHERS, ImportKinds::TEACHING_SCHEDULE];
        if (! is_string($type) || ! in_array($type, $known, true)) {
            throw ValidationException::withMessages(['type' => 'Jenis impor tidak dikenal.']);
        }
        $this->tenant->requireAnyFeature(ImportKinds::feature($type));
        $kind = ImportKinds::get($type);

        return $this->csv($kind->templateFilename(), function ($out) use ($kind): void {
            fputcsv($out, $kind->columns(), escape: '');
            foreach ($kind->templateRows() as $row) {
                fputcsv($out, $row, escape: '');
            }
        });
    }

    private function find(string $id): ImportBatch
    {
        return ImportBatch::query()->find($id) ?? throw new NotFoundHttpException;
    }

    /** Batch milik sekolah ini yang jenis impornya masih termasuk fitur sekolah (ADR 0009). */
    private function findEntitled(string $id): ImportBatch
    {
        $batch = $this->find($id);
        $this->tenant->requireAnyFeature(ImportKinds::feature($batch->type));

        return $batch;
    }

    /** @param  callable(resource): void  $write */
    private function csv(string $filename, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            $write($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Mencegah sel diperlakukan sebagai rumus saat file dibuka di spreadsheet (CSV injection). */
    private static function safeCell(?string $value): string
    {
        $value ??= '';

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
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
    public static function present(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'status' => $batch->status,
            'original_filename' => $batch->original_filename,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'committed_rows' => $batch->committed_rows,
            'error_message' => $batch->error_message,
            'created_at' => $batch->created_at->toIso8601String(),
            'committed_at' => $batch->committed_at?->toIso8601String(),
        ];
    }
}
