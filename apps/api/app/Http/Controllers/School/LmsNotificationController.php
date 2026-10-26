<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi LMS milik pengguna yang login di sekolah ini: 30 terbaru, jumlah yang belum dibaca, dan penandaan sudah dibaca.
 */
final class LmsNotificationController extends Controller
{
    private const LIMIT = 30;

    public function index(Request $request, School $school): JsonResponse
    {
        $mine = Notification::query()->where('user_id', $request->user()?->id);

        return response()->json(['data' => [
            'unread_count' => (clone $mine)->whereNull('read_at')->count(),
            'items' => (clone $mine)->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)->get()->map(fn (Notification $n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
                'assignment_id' => $n->data['assignment_id'] ?? null, 'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
        ]]);
    }

    /** Menandai satu notifikasi (`id`) atau semuanya (tanpa `id`) sebagai sudah dibaca. */
    public function read(Request $request, School $school): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'uuid']]);
        $query = Notification::query()->where('user_id', $request->user()?->id)->whereNull('read_at');
        if (isset($data['id'])) {
            $query->whereKey($data['id']);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => Notification::query()->where('user_id', $request->user()?->id)->whereNull('read_at')->count()]]);
    }
}
