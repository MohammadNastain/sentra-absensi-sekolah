<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\School\AuditLogController;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Audit platform (school_id NULL): pembuatan/penangguhan sekolah, perubahan paket dan override. Hanya terbaca dalam
 * konteks platform (RLS audit_read); audit sekolah tetap di /schools/{school}/audit-logs (U04).
 */
final class PlatformAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'school' => ['nullable', 'string', 'max:40'],
        ]);

        // Sekolah yang dimaksud: entri sekolah menunjuknya lewat entity_id, entri paket/override lewat school_id di nilainya.
        $schoolId = isset($filters['school']) ? School::query()->where('code', $filters['school'])->value('id') : null;
        $unknownSchool = isset($filters['school']) && $schoolId === null;

        $page = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->whereNull('a.school_id')
            ->when($unknownSchool, fn ($q) => $q->whereRaw('false'))
            ->when($schoolId, fn ($q, $id) => $q->where(fn ($w) => $w->where('a.entity_id', $id)
                ->orWhereRaw("(a.after_value::jsonb ->> 'school_id') = ?", [$id])
                ->orWhereRaw("(a.before_value::jsonb ->> 'school_id') = ?", [$id])))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('a.action', 'like', $v.'%'))
            ->when($filters['entity_type'] ?? null, fn ($q, $v) => $q->where('a.entity_type', $v))
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id')
            ->select(AuditLogController::COLUMNS)
            ->paginate(50);

        return AuditLogController::respond($page);
    }
}
