<?php

namespace App\Domain\Imports;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Jobs\ValidateImport;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\School;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Siklus impor (siswa, guru, jadwal mengajar): unggah (validasi dijalankan job) → commit baris valid saja dalam
 * satu transaksi (D-15). Commit mencatat satu audit tingkat batch (jumlah, nama file, hash file); data hasil impor
 * tidak diaudit satu per satu.
 */
final class ManageImports
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly SpreadsheetReader $reader,
    ) {}

    public function upload(School $school, User $actor, UploadedFile $file, ImportKind $kind): ImportBatch
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $this->reader->assertSafe($file->getRealPath(), $extension);

        $id = (string) Str::uuid7();
        $path = "imports/{$school->id}/{$id}.{$extension}";
        Storage::disk('local')->putFileAs("imports/{$school->id}", $file, "{$id}.{$extension}");

        $batch = $this->database->runInSchool($school->id, function () use ($school, $actor, $file, $id, $path, $kind) {
            $batch = new ImportBatch([
                'school_id' => $school->id,
                'type' => $kind->type(),
                'status' => ImportBatch::STATUS_UPLOADED,
                'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200),
                'storage_path' => $path,
                'file_sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by_user_id' => $actor->id,
            ]);
            $batch->id = $id;
            $batch->save();

            return $batch;
        });

        ValidateImport::dispatch($school->id, $batch->id);

        return $this->database->runInSchool($school->id, fn () => ImportBatch::query()->findOrFail($batch->id));
    }

    /**
     * Menyimpan semua baris valid. Baris yang ternyata tidak lagi valid sejak preview (misalnya nomor sudah
     * dipakai) ditandai skipped; sisanya tetap disimpan.
     */
    public function commit(School $school, User $actor, string $batchId): ImportBatch
    {
        try {
            return $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $batchId) {
                $batch = ImportBatch::query()->where('school_id', $school->id)->lockForUpdate()->find($batchId)
                    ?? throw new NotFoundHttpException;
                if ($batch->status !== ImportBatch::STATUS_VALIDATED) {
                    throw ApiProblem::invalidStateTransition('Impor ini tidak dapat di-commit (status: '.$batch->status.').');
                }

                $processor = ImportKinds::get($batch->type)->processor($school->id);
                $committed = 0;
                $skipped = 0;

                $rows = ImportRow::query()->where('batch_id', $batch->id)->where('status', ImportRow::STATUS_VALID)->orderBy('row_number')->get();
                foreach ($rows as $row) {
                    // Validasi ulang terhadap kondisi saat ini (data bisa berubah sejak preview).
                    $recheck = $processor->validate($row->payload);
                    if ($recheck['normalized'] === null) {
                        $row->update(['status' => ImportRow::STATUS_SKIPPED, 'errors' => $recheck['errors']]);
                        $skipped++;

                        continue;
                    }

                    $processor->persist($recheck['normalized'], $actor);
                    $row->update(['status' => ImportRow::STATUS_COMMITTED]);
                    $committed++;
                }

                $batch->update([
                    'status' => ImportBatch::STATUS_COMMITTED,
                    'committed_rows' => $committed,
                    'invalid_rows' => $batch->invalid_rows + $skipped,
                    'valid_rows' => $committed,
                    'committed_by_user_id' => $actor->id,
                    'committed_at' => now(),
                ]);

                $this->audit->record('school.import.committed', $school->id, 'import_batch', $batch->id, null, [
                    'type' => $batch->type,
                    'original_filename' => $batch->original_filename,
                    'file_sha256' => $batch->file_sha256,
                    'total_rows' => $batch->total_rows,
                    'valid_rows' => $batch->valid_rows,
                    'invalid_rows' => $batch->invalid_rows,
                    'committed_rows' => $batch->committed_rows,
                    'status' => $batch->status,
                ], null, $actor);

                return $batch;
            }));
        } catch (QueryException $e) {
            throw ConstraintViolations::translate($e) ?? $e;
        }
    }
}
