<?php

namespace App\Http\Controllers\School;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Audit log sekolah. RLS membatasi ke baris sekolah konteks; `where school_id` eksplisit sebagai
 * lapis kedua. Baris platform (school_id NULL) tidak pernah muncul di sini (U04).
 */
final class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'uuid'],
        ]);

        $page = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->where('a.school_id', $tenant->school()->id)
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('a.action', 'like', $v.'%'))
            ->when($filters['entity_type'] ?? null, fn ($q, $v) => $q->where('a.entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn ($q, $v) => $q->where('a.entity_id', $v))
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id')
            ->select(['a.id', 'a.action', 'a.entity_type', 'a.entity_id', 'a.reason', 'a.before_value', 'a.after_value', 'a.request_id', 'a.created_at', 'a.actor_type', 'a.actor_user_id', 'u.name as actor_name'])
            ->paginate(50);

        $data = [];
        foreach ($page->items() as $item) {
            /** @var array<string, mixed> $row */
            $row = (array) $item;
            $data[] = [
                'id' => $row['id'],
                'action' => $row['action'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'reason' => $row['reason'],
                'before' => self::decode($row['before_value']),
                'after' => self::decode($row['after_value']),
                'actor' => ['type' => $row['actor_type'], 'user_id' => $row['actor_user_id'], 'name' => $row['actor_name']],
                'request_id' => $row['request_id'],
                'created_at' => Carbon::parse((string) $row['created_at'])->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private static function decode(mixed $json): mixed
    {
        return is_string($json) ? json_decode($json, true) : null;
    }
}
