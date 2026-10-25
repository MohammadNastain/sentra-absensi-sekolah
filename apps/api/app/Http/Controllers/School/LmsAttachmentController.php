<?php

namespace App\Http\Controllers\School;

use App\Domain\Lms\AttachmentStorage;
use App\Domain\Lms\ManageAttachments;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\LmsAttachment;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lampiran materi LMS (ADR 0010 §5). Unduhan selalu lewat sini: tidak ada URL publik ke berkas. */
final class LmsAttachmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ManageAttachments $attachments,
        private readonly AttachmentStorage $files,
    ) {}

    public function store(Request $request, School $school, string $material): JsonResponse
    {
        return $this->upload($request, LmsAttachment::OWNER_MATERIAL, $material);
    }

    public function storeForAssignment(Request $request, School $school, string $assignment): JsonResponse
    {
        return $this->upload($request, LmsAttachment::OWNER_ASSIGNMENT, $assignment);
    }

    public function storeForSubmission(Request $request, School $school, string $submission): JsonResponse
    {
        return $this->upload($request, LmsAttachment::OWNER_SUBMISSION, $submission);
    }

    private function upload(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(AttachmentStorage::MAX_BYTES / 1024), 'extensions:'.implode(',', AttachmentStorage::extensions())],
        ]);
        $attachment = $this->attachments->upload($this->tenant->school(), $this->actor($request), $ownerType, $ownerId, $request->file('file'));

        return response()->json(['data' => [
            'id' => $attachment->id, 'name' => $attachment->original_name, 'mime' => $attachment->mime, 'size' => $attachment->size,
        ]], 201);
    }

    public function download(Request $request, School $school, string $attachment): StreamedResponse
    {
        $found = $this->attachments->findForDownload($this->actor($request), $attachment);

        $name = AttachmentStorage::downloadName($found->original_name);
        $response = $this->files->stream($found->storage_key);
        $response->headers->add([
            // Selalu diunduh, tidak pernah ditampilkan inline, dan tanpa tebakan tipe oleh peramban.
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        return $response;
    }

    public function destroy(Request $request, School $school, string $attachment): Response
    {
        $this->attachments->delete($this->tenant->school(), $this->actor($request), $attachment, false);

        return response()->noContent();
    }

    /** Siswa menghapus lampiran pengumpulannya sendiri (selama belum terkunci). */
    public function destroyFromSubmission(Request $request, School $school, string $submission, string $attachment): Response
    {
        $this->attachments->delete($this->tenant->school(), $this->actor($request), $attachment, true);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
