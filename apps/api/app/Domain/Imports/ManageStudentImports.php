<?php

namespace App\Domain\Imports;

use App\Domain\Academic\ConstraintViolations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Jobs\ValidateStudentImport;
use App\Models\ClassEnrollment;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Siklus impor siswa: unggah (validasi dijalankan job) → commit baris valid saja dalam satu transaksi (D-15).
 * Commit mencatat satu audit tingkat batch (jumlah, nama file, hash file); siswa hasil impor tidak diaudit satu per satu.
 */
final class ManageStudentImports
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly SpreadsheetReader $reader,
    ) {}

    public function upload(School $school, User $actor, UploadedFile $file): ImportBatch
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $this->reader->assertSafe($file->getRealPath(), $extension);

        $id = (string) Str::uuid7();
        $path = "imports/{$school->id}/{$id}.{$extension}";
        Storage::disk('local')->putFileAs("imports/{$school->id}", $file, "{$id}.{$extension}");

        $batch = $this->database->runInSchool($school->id, function () use ($school, $actor, $file, $id, $path) {
            $batch = new ImportBatch([
                'school_id' => $school->id,
                'type' => 'students',
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

        ValidateStudentImport::dispatch($school->id, $batch->id);

        return $this->database->runInSchool($school->id, fn () => ImportBatch::query()->findOrFail($batch->id));
    }

    /**
     * Menyimpan semua baris valid sebagai siswa (dan penempatan kelas bila kolom kelas diisi). Nomor yang
     * ternyata sudah dipakai sejak validasi ditandai skipped; sisanya tetap disimpan.
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

                $validator = new StudentImportValidator($school->id);
                $start = $validator->enrollStartDate();
                $committed = 0;
                $skipped = 0;

                $rows = ImportRow::query()->where('batch_id', $batch->id)->where('status', ImportRow::STATUS_VALID)->orderBy('row_number')->get();
                foreach ($rows as $row) {
                    // Validasi ulang terhadap kondisi saat ini (data bisa berubah sejak preview).
                    $payload = $row->payload;
                    $recheck = $validator->validate($payload);
                    if ($recheck['normalized'] === null) {
                        $row->update(['status' => ImportRow::STATUS_SKIPPED, 'errors' => $recheck['errors']]);
                        $skipped++;

                        continue;
                    }

                    /** @var array{student_number: string, nisn: string|null, full_name: string, date_of_birth: string|null, class_id: string|null} $data */
                    $data = $recheck['normalized'];
                    $student = Student::query()->create([
                        'school_id' => $school->id,
                        'student_number' => $data['student_number'],
                        'nisn' => $data['nisn'],
                        'full_name' => $data['full_name'],
                        'date_of_birth' => $data['date_of_birth'],
                        'status' => Student::STATUS_ACTIVE,
                    ]);
                    if ($data['class_id'] !== null && $start !== null) {
                        ClassEnrollment::query()->create([
                            'school_id' => $school->id,
                            'student_id' => $student->id,
                            'class_id' => $data['class_id'],
                            'start_date' => $start,
                            'created_by_user_id' => $actor->id,
                        ]);
                    }
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
