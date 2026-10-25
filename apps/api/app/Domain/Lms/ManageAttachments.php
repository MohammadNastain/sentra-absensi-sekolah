<?php

namespace App\Domain\Lms;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\DatabaseContext;
use App\Models\LmsAttachment;
use App\Models\LmsMaterial;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Lampiran materi (ADR 0010 §5). Mengunggah dan menghapus mengikuti hak kelola materi; mengunduh mengikuti hak lihat
 * materi pemiliknya, sehingga lampiran materi draf tidak terbaca siswa dan lampiran lintas kelas/sekolah tidak terbaca.
 */
final class ManageAttachments
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly ManageMaterials $materials,
        private readonly AttachmentStorage $files,
    ) {}

    public function upload(School $school, User $actor, string $materialId, UploadedFile $file): LmsAttachment
    {
        $mime = $this->files->acceptedMime($file);
        if ($mime === null) {
            throw ValidationException::withMessages(['file' => ['Jenis berkas tidak diizinkan atau isinya tidak sesuai ekstensinya.']]);
        }

        return $this->database->runInSchool($school->id, function () use ($school, $actor, $materialId, $file, $mime) {
            $stored = null;

            try {
                return DB::transaction(function () use ($school, $actor, $materialId, $file, $mime, &$stored) {
                    $material = $this->materials->find($actor, $materialId);
                    $this->materials->assertCanManage($material);
                    // Kunci baris materi agar dua unggahan serentak tidak melewati batas jumlah berkas.
                    LmsMaterial::query()->lockForUpdate()->findOrFail($material->id);

                    if (LmsAttachment::query()->where('owner_type', LmsAttachment::OWNER_MATERIAL)->where('owner_id', $material->id)->count() >= AttachmentStorage::MAX_PER_OWNER) {
                        throw ValidationException::withMessages(['file' => ['Maksimal '.AttachmentStorage::MAX_PER_OWNER.' berkas per materi.']]);
                    }

                    $stored = $this->files->put($school->id, $file);
                    $attachment = LmsAttachment::query()->create([
                        'school_id' => $school->id,
                        'owner_type' => LmsAttachment::OWNER_MATERIAL,
                        'owner_id' => $material->id,
                        'original_name' => AttachmentStorage::downloadName((string) $file->getClientOriginalName()),
                        'storage_key' => $stored['key'],
                        'mime' => $mime,
                        'size' => (int) $file->getSize(),
                        'sha256' => $stored['sha256'],
                        'uploaded_by_user_id' => $actor->id,
                    ]);
                    $this->audit->record('school.lms_attachment.created', $school->id, 'lms_attachment', $attachment->id, null, self::audited($attachment), null, $actor);

                    return $attachment;
                });
            } catch (Throwable $e) {
                // Transaksi gagal setelah berkas tertulis: jangan tinggalkan berkas yatim.
                if ($stored !== null) {
                    $this->files->forget([$stored['key']]);
                }
                throw $e;
            }
        });
    }

    /** Lampiran yang boleh diunduh pengguna ini (404 bila materi pemiliknya tidak terlihat olehnya). */
    public function findForDownload(User $viewer, string $attachmentId): LmsAttachment
    {
        $attachment = LmsAttachment::query()->find($attachmentId) ?? throw new NotFoundHttpException;
        $this->materials->find($viewer, $attachment->owner_id);

        return $attachment;
    }

    public function delete(School $school, User $actor, string $attachmentId): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $attachmentId) {
            $attachment = LmsAttachment::query()->lockForUpdate()->find($attachmentId) ?? throw new NotFoundHttpException;
            $this->materials->assertCanManage($this->materials->find($actor, $attachment->owner_id));

            $attachment->delete();
            $this->audit->record('school.lms_attachment.deleted', $school->id, 'lms_attachment', $attachment->id, self::audited($attachment), null, null, $actor);
            DB::afterCommit(fn () => $this->files->forget([$attachment->storage_key]));
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(LmsAttachment $attachment): array
    {
        return ['owner_type' => $attachment->owner_type, 'owner_id' => $attachment->owner_id, 'original_name' => $attachment->original_name, 'mime' => $attachment->mime, 'size' => $attachment->size];
    }
}
