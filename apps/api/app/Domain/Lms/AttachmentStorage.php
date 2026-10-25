<?php

namespace App\Domain\Lms;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyimpanan berkas lampiran LMS di disk privat `lms` (ADR 0010 §5). Aturan jenis berkas ada di sini agar tes dan
 * controller memakai satu sumber: ekstensi allowlist, MIME hasil sniffing isi berkas harus cocok dengan ekstensinya.
 */
final class AttachmentStorage
{
    public const DISK = 'lms';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PER_OWNER = 5;

    /**
     * Ekstensi → MIME hasil sniffing yang dapat diterima. Format Office modern berupa zip, format lama berupa OLE.
     *
     * @var array<string, list<string>>
     */
    private const TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** MIME hasil sniffing bila berkas lolos, null bila ekstensi tidak diizinkan atau isinya tidak cocok. */
    public function acceptedMime(UploadedFile $file): ?string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = self::TYPES[$extension] ?? null;
        $detected = $file->getMimeType();

        return $allowed !== null && $detected !== null && in_array($detected, $allowed, true) ? $detected : null;
    }

    /**
     * Menyimpan isi berkas dengan kunci acak `<school_id>/<uuid>` (tanpa nama asli); mengembalikan kunci dan sha256.
     *
     * @return array{key: string, sha256: string}
     */
    public function put(string $schoolId, UploadedFile $file): array
    {
        $key = $schoolId.'/'.Str::uuid()->toString();
        Storage::disk(self::DISK)->putFileAs($schoolId, $file, basename($key));

        return ['key' => $key, 'sha256' => (string) hash_file('sha256', (string) $file->getRealPath())];
    }

    /** @param  list<string>  $keys */
    public function forget(array $keys): void
    {
        if ($keys !== []) {
            Storage::disk(self::DISK)->delete($keys);
        }
    }

    public function stream(string $key): StreamedResponse
    {
        return Storage::disk(self::DISK)->response($key);
    }

    /** Nama berkas aman untuk header unduhan: tanpa pemisah path, kendali, atau tanda kutip. */
    public static function downloadName(string $original): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\"]+/u', '_', $original) ?? '';
        $name = trim($name, " .\t");

        return $name === '' ? 'lampiran' : mb_substr($name, 0, 150);
    }
}
