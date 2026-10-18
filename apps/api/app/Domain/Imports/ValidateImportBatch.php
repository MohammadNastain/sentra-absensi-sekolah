<?php

namespace App\Domain\Imports;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Memvalidasi file impor yang sudah diunggah dan menulis hasilnya ke staging (import_rows). Dipanggil dari
 * job di dalam konteks sekolah. Hasil ditulis dalam satu transaksi: batch tidak pernah setengah tervalidasi.
 */
final class ValidateImportBatch
{
    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function handle(string $schoolId, string $batchId): void
    {
        $batch = ImportBatch::query()->where('school_id', $schoolId)->find($batchId);
        if ($batch === null || $batch->status !== ImportBatch::STATUS_UPLOADED) {
            return;
        }

        $batch->update(['status' => ImportBatch::STATUS_VALIDATING]);

        try {
            $parsed = $this->reader->read(
                Storage::disk('local')->path($batch->storage_path),
                pathinfo($batch->original_filename, PATHINFO_EXTENSION),
            );

            $missing = StudentImportValidator::missingHeaders($parsed['headers']);
            if ($missing !== []) {
                throw new ImportFileException('Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Gunakan templat yang disediakan.');
            }
            if ($parsed['rows'] === []) {
                throw new ImportFileException('File tidak berisi baris data.');
            }

            $validator = new StudentImportValidator($schoolId);
            $records = [];
            $valid = 0;
            foreach ($parsed['rows'] as $number => $row) {
                $result = $validator->validate($row);
                $isValid = $result['normalized'] !== null;
                $valid += $isValid ? 1 : 0;

                $records[] = [
                    'school_id' => $schoolId,
                    'batch_id' => $batch->id,
                    'row_number' => $number,
                    'payload' => json_encode(array_intersect_key($row, array_flip(['nomor_induk', 'nisn', 'nama_lengkap', 'tanggal_lahir', 'kelas'])), JSON_THROW_ON_ERROR),
                    'normalized' => $result['normalized'] === null ? null : json_encode($result['normalized'], JSON_THROW_ON_ERROR),
                    'status' => $isValid ? ImportRow::STATUS_VALID : ImportRow::STATUS_INVALID,
                    'errors' => json_encode($result['errors'], JSON_THROW_ON_ERROR),
                ];
            }

            DB::transaction(function () use ($batch, $records, $valid): void {
                ImportRow::query()->where('batch_id', $batch->id)->delete();
                foreach (array_chunk($records, 500) as $chunk) {
                    DB::table('import_rows')->insert($chunk);
                }
                $batch->update([
                    'status' => ImportBatch::STATUS_VALIDATED,
                    'total_rows' => count($records),
                    'valid_rows' => $valid,
                    'invalid_rows' => count($records) - $valid,
                    'error_message' => null,
                ]);
            });
        } catch (ImportFileException $e) {
            $batch->update(['status' => ImportBatch::STATUS_FAILED, 'error_message' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            $batch->update(['status' => ImportBatch::STATUS_FAILED, 'error_message' => 'Terjadi kesalahan saat memproses file. Coba unggah ulang.']);
        }
    }
}
