<?php

namespace App\Domain\Lms;

use App\Domain\Audit\AuditLogger;
use App\Domain\Entitlement\Feature;
use App\Domain\Tenancy\DatabaseContext;
use App\Domain\Tenancy\TenantContext;
use App\Models\LmsAssignment;
use App\Models\LmsAttachment;
use App\Models\LmsMaterial;
use App\Models\LmsSubmission;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Lampiran LMS untuk tiga pemilik (ADR 0010 §5): materi dan tugas dikelola guru/admin; lampiran pengumpulan dikelola
 * siswa pemiliknya selama pengumpulan belum terkunci. Mengunduh mengikuti hak lihat pemiliknya: materi/tugas yang
 * terlihat, atau pengumpulan milik sendiri / yang dinilai pengguna ini. Selain lampiran sendiri, tiap pemilik juga
 * mensyaratkan fitur modulnya (materi, tugas, pengumpulan) aktif.
 */
final class ManageAttachments
{
    public function __construct(
        private readonly DatabaseContext $database,
        private readonly AuditLogger $audit,
        private readonly ManageMaterials $materials,
        private readonly ManageAssignments $assignments,
        private readonly ManageSubmissions $submissions,
        private readonly AttachmentStorage $files,
        private readonly TenantContext $tenant,
    ) {}

    public function upload(School $school, User $actor, string $ownerType, string $ownerId, UploadedFile $file): LmsAttachment
    {
        $mime = $this->files->acceptedMime($file);
        if ($mime === null) {
            throw ValidationException::withMessages(['file' => ['Jenis berkas tidak diizinkan atau isinya tidak sesuai ekstensinya.']]);
        }

        return $this->database->runInSchool($school->id, function () use ($school, $actor, $ownerType, $ownerId, $file, $mime) {
            $stored = null;

            try {
                return DB::transaction(function () use ($school, $actor, $ownerType, $ownerId, $file, $mime, &$stored) {
                    $owner = $this->lockedEditableOwner($actor, $ownerType, $ownerId);

                    if (LmsAttachment::query()->where('owner_type', $ownerType)->where('owner_id', $owner)->count() >= AttachmentStorage::MAX_PER_OWNER) {
                        throw ValidationException::withMessages(['file' => ['Maksimal '.AttachmentStorage::MAX_PER_OWNER.' berkas per '.self::noun($ownerType).'.']]);
                    }

                    $stored = $this->files->put($school->id, $file);
                    $attachment = LmsAttachment::query()->create([
                        'school_id' => $school->id,
                        'owner_type' => $ownerType,
                        'owner_id' => $owner,
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

    /** Lampiran yang boleh diunduh pengguna ini (404 bila pemiliknya tidak terlihat olehnya). */
    public function findForDownload(User $viewer, string $attachmentId): LmsAttachment
    {
        $attachment = LmsAttachment::query()->find($attachmentId) ?? throw new NotFoundHttpException;
        $this->requireOwnerFeature($attachment->owner_type);

        match ($attachment->owner_type) {
            LmsAttachment::OWNER_MATERIAL => $this->materials->find($viewer, $attachment->owner_id),
            LmsAttachment::OWNER_ASSIGNMENT => $this->assignments->find($viewer, $attachment->owner_id),
            default => $this->submissions->findReadable($viewer, $attachment->owner_id),
        };

        return $attachment;
    }

    /**
     * Menghapus lampiran. `$ownerType` membatasi jenis yang boleh lewat rute ini: guru/admin untuk materi dan tugas,
     * siswa untuk lampiran pengumpulannya sendiri; jenis lain dijawab 404.
     */
    public function delete(School $school, User $actor, string $attachmentId, bool $submissionOnly): void
    {
        $this->database->runInSchool($school->id, fn () => DB::transaction(function () use ($school, $actor, $attachmentId, $submissionOnly) {
            $attachment = LmsAttachment::query()->lockForUpdate()->find($attachmentId) ?? throw new NotFoundHttpException;
            if (($attachment->owner_type === LmsAttachment::OWNER_SUBMISSION) !== $submissionOnly) {
                throw new NotFoundHttpException;
            }
            $this->lockedEditableOwner($actor, $attachment->owner_type, $attachment->owner_id);

            $attachment->delete();
            $this->audit->record('school.lms_attachment.deleted', $school->id, 'lms_attachment', $attachment->id, self::audited($attachment), null, null, $actor);
            DB::afterCommit(fn () => $this->files->forget([$attachment->storage_key]));
        }));
    }

    /**
     * Memeriksa fitur pemilik dan hak mengubahnya, mengunci baris pemilik (agar batas jumlah berkas tidak terlampaui oleh
     * unggahan serentak), dan mengembalikan id pemilik.
     */
    private function lockedEditableOwner(User $actor, string $type, string $id): string
    {
        $this->requireOwnerFeature($type);

        switch ($type) {
            case LmsAttachment::OWNER_MATERIAL:
                $material = $this->materials->find($actor, $id);
                $this->materials->assertCanManage($material);

                return LmsMaterial::query()->lockForUpdate()->findOrFail($material->id)->id;
            case LmsAttachment::OWNER_ASSIGNMENT:
                $assignment = $this->assignments->find($actor, $id);
                $this->assignments->assertCanManage($assignment);

                return LmsAssignment::query()->lockForUpdate()->findOrFail($assignment->id)->id;
            default:
                $submission = $this->submissions->findEditable($actor, $id);

                return LmsSubmission::query()->lockForUpdate()->findOrFail($submission->id)->id;
        }
    }

    private function requireOwnerFeature(string $type): void
    {
        $this->tenant->requireAnyFeature(match ($type) {
            LmsAttachment::OWNER_MATERIAL => Feature::LmsMaterials,
            LmsAttachment::OWNER_ASSIGNMENT => Feature::LmsAssignments,
            default => Feature::LmsSubmissions,
        });
    }

    private static function noun(string $type): string
    {
        return match ($type) {
            LmsAttachment::OWNER_MATERIAL => 'materi',
            LmsAttachment::OWNER_ASSIGNMENT => 'tugas',
            default => 'pengumpulan',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function audited(LmsAttachment $attachment): array
    {
        return ['owner_type' => $attachment->owner_type, 'owner_id' => $attachment->owner_id, 'original_name' => $attachment->original_name, 'mime' => $attachment->mime, 'size' => $attachment->size];
    }
}
