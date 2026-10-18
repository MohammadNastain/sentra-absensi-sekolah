<?php

namespace App\Http\Controllers\School;

use App\Domain\Imports\ImportFileException;
use App\Domain\Imports\ManageStudentImports;
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

final class StudentImportController extends Controller
{
    private const COLUMNS = ['nomor_induk', 'nisn', 'nama_lengkap', 'tanggal_lahir', 'kelas'];

    public function __construct(private readonly TenantContext $tenant, private readonly ManageStudentImports $imports) {}

    public function index(): JsonResponse
    {
        $page = ImportBatch::query()->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (ImportBatch $b) => self::present($b))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx']]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        try {
            $batch = $this->imports->upload($this->tenant->school(), $this->actor($request), $file);
        } catch (ImportFileException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json(['data' => self::present($batch)], 202);
    }

    public function show(School $school, string $import): JsonResponse
    {
        return response()->json(['data' => self::present($this->find($import))]);
    }

    public function rows(Request $request, School $school, string $import): JsonResponse
    {
        $batch = $this->find($import);
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
        $batch = $this->imports->commit($this->tenant->school(), $this->actor($request), $import);

        return response()->json(['data' => self::present($batch)]);
    }

    /** Baris yang gagal (atau dilewati saat commit) beserta kesalahannya, siap diperbaiki lalu diunggah ulang. */
    public function failedRows(School $school, string $import): StreamedResponse
    {
        $batch = $this->find($import);
        $rows = ImportRow::query()->where('batch_id', $batch->id)->whereIn('status', [ImportRow::STATUS_INVALID, ImportRow::STATUS_SKIPPED])->orderBy('row_number')->get();  // dimuat sebelum respons dikirim (konteks sekolah masih aktif)

        return $this->csv('perbaikan-'.pathinfo($batch->original_filename, PATHINFO_FILENAME).'.csv', function ($out) use ($rows): void {
            fputcsv($out, [...self::COLUMNS, 'kesalahan'], escape: '');
            foreach ($rows as $row) {
                $values = array_map(fn (string $c) => self::safeCell($row->payload[$c] ?? null), self::COLUMNS);
                $messages = implode('; ', array_map(fn (array $e) => $e['message'], $row->errors));
                fputcsv($out, [...$values, $messages], escape: '');
            }
        });
    }

    public function template(): StreamedResponse
    {
        return $this->csv('templat-impor-siswa.csv', function ($out): void {
            fputcsv($out, self::COLUMNS, escape: '');
            fputcsv($out, ['1001', '0012345678', 'Ahmad Rizky', '2012-05-01', '7A'], escape: '');
            fputcsv($out, ['1002', '', 'Bunga Lestari', '', ''], escape: '');
        });
    }

    private function find(string $id): ImportBatch
    {
        return ImportBatch::query()->find($id) ?? throw new NotFoundHttpException;
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
