<?php

namespace App\Domain\Audit;

use App\Models\User;
use App\Support\Http\RequestId;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menulis audit log append-only (ARCHITECTURE §12).
 *
 * - Panggil di DALAM transaksi yang sama dengan perubahan data: bila audit gagal, perubahan dibatalkan (U01).
 * - Tidak memakai INSERT ... RETURNING: baris tanpa sekolah harus tetap dapat ditulis di luar konteks
 *   platform, padahal RETURNING mensyaratkan baris lolos policy SELECT.
 * - school_id diisi pemanggil sesuai konteks; RLS menolak school_id yang berbeda dari konteks.
 */
class AuditLogger
{
    public function __construct(private readonly AuditRedactor $redactor) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?string $schoolId,
        string $entityType,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
    ): string {
        $actor ??= Auth::guard('web')->user();
        $request = request();
        $id = (string) Str::uuid7();

        DB::table('audit_logs')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'actor_type' => $actor instanceof User ? 'user' : 'system',
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reason' => $reason,
            'before_value' => $this->encode($this->redactor->redact($entityType, $before)),
            'after_value' => $this->encode($this->redactor->redact($entityType, $after)),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : Str::limit($request->userAgent(), 500, ''),
            'request_id' => RequestId::current(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function encode(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
