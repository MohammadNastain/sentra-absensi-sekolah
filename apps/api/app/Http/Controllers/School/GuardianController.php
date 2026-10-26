<?php

namespace App\Http\Controllers\School;

use App\Domain\Guardians\ManageGuardians;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin mengelola wali dan tautannya ke anak (ADR 0011 §1–§2). Izin: school.guardians.manage; fitur: parent_portal.
 */
final class GuardianController extends Controller
{
    private const RELATIONSHIPS = ['ayah', 'ibu', 'wali'];

    public function __construct(private readonly TenantContext $tenant, private readonly ManageGuardians $guardians) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');
        $status = $request->query('status');

        $page = Guardian::query()
            ->when(in_array($status, [Guardian::STATUS_ACTIVE, Guardian::STATUS_INACTIVE], true), fn ($q) => $q->where('status', $status))
            ->when(is_string($search) && trim($search) !== '', fn ($q) => $q->where('full_name', 'ilike', '%'.addcslashes(trim($search), '\\%_').'%'))
            ->orderBy('full_name')->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => $this->present(collect($page->items())),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Wali sebuah siswa, wali utama lebih dulu. */
    public function forStudent(School $school, string $student): JsonResponse
    {
        Student::query()->find($student) ?? throw new NotFoundHttpException;
        $ids = StudentGuardian::query()->where('student_id', $student)->orderByDesc('is_primary')->orderBy('created_at')->orderBy('id')->pluck('guardian_id');
        $presented = collect($this->present(Guardian::query()->whereIn('id', $ids)->get()))->keyBy('id');

        return response()->json(['data' => $ids->map(fn ($id) => $presented->get($id))->filter()->values()->all()]);
    }

    /** Wali baru (dengan surel, lalu diundang) atau tautan wali yang sudah ada (`guardian_id`) ke siswa ini. */
    public function store(Request $request, School $school, string $student): JsonResponse
    {
        $rules = ['relationship' => ['required', Rule::in(self::RELATIONSHIPS)], 'is_primary' => ['sometimes', 'boolean']];
        $existing = $request->filled('guardian_id');
        $data = $request->validate($existing
            ? $rules + ['guardian_id' => ['required', 'uuid']]
            : $rules + [
                'full_name' => ['required', 'string', 'max:150', 'regex:/\S/u'],
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
                'phone' => ['nullable', 'string', 'max:30'],
            ]);

        if ($existing) {
            $this->guardians->linkExisting($this->tenant->school(), $this->actor($request), $student, (string) $data['guardian_id'], (string) $data['relationship'], (bool) ($data['is_primary'] ?? false));
            $guardianId = (string) $data['guardian_id'];
        } else {
            $data['email'] = mb_strtolower(trim((string) $data['email']));
            $guardianId = $this->guardians->createAndLink($this->tenant->school(), $this->actor($request), $student, $data)->id;
        }

        return response()->json(['data' => $this->present(Guardian::query()->whereKey($guardianId)->get())[0]], 201);
    }

    public function update(Request $request, School $school, string $guardian): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150', 'regex:/\S/u'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', Rule::in([Guardian::STATUS_ACTIVE, Guardian::STATUS_INACTIVE])],
        ]);
        $updated = $this->guardians->update($this->tenant->school(), $this->actor($request), $guardian, $data);

        return response()->json(['data' => $this->present(Guardian::query()->whereKey($updated->id)->get())[0]]);
    }

    public function updateLink(Request $request, School $school, string $student, string $guardian): JsonResponse
    {
        $data = $request->validate(['relationship' => ['sometimes', Rule::in(self::RELATIONSHIPS)], 'is_primary' => ['sometimes', 'boolean']]);
        $this->guardians->updateLink($this->tenant->school(), $this->actor($request), $student, $guardian, $data);

        return response()->json(['data' => $this->present(Guardian::query()->whereKey($guardian)->get())[0]]);
    }

    public function destroyLink(Request $request, School $school, string $student, string $guardian): JsonResponse
    {
        $this->guardians->unlink($this->tenant->school(), $this->actor($request), $student, $guardian);

        return response()->json(null, 204);
    }

    /** Kirim ulang undangan; `email` hanya perlu bila wali belum pernah diundang. */
    public function reinvite(Request $request, School $school, string $guardian): JsonResponse
    {
        $data = $request->validate(['email' => ['sometimes', 'string', 'email:rfc', 'max:255']]);
        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $this->guardians->reinvite($this->tenant->school(), $this->actor($request), $guardian, $email);

        return response()->json(['data' => $this->present(Guardian::query()->whereKey($guardian)->get())[0]]);
    }

    /**
     * Wali beserta anak dan status akunnya. Status akun: `active` (sudah masuk), `invited` (undangan berlaku),
     * `invitation_expired` (undangan kedaluwarsa), `none` (belum/tidak ada undangan berlaku).
     *
     * @param  Collection<int, Guardian>  $guardians
     * @return list<array<string, mixed>>
     */
    private function present(Collection $guardians): array
    {
        $ids = $guardians->pluck('id')->all();
        $children = DB::table('student_guardians as sg')
            ->join('students as s', fn ($j) => $j->on('s.id', '=', 'sg.student_id')->on('s.school_id', '=', 'sg.school_id'))
            ->whereIn('sg.guardian_id', $ids)
            ->orderBy('s.full_name')->orderBy('s.id')
            ->get(['sg.guardian_id', 'sg.student_id', 'sg.relationship', 'sg.is_primary', 's.full_name', 's.student_number', 's.status'])
            ->groupBy('guardian_id');

        $memberEmail = DB::table('school_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.id', $guardians->pluck('membership_id')->filter()->all())->pluck('u.email', 'm.id');
        $invitations = DB::table('membership_invitations')->whereIn('id', $guardians->pluck('invitation_id')->filter()->all())
            ->get(['id', 'email', 'expires_at', 'accepted_at', 'revoked_at'])->keyBy('id');

        return array_values($guardians->map(function (Guardian $g) use ($children, $memberEmail, $invitations): array {
            $invitation = $g->invitation_id === null ? null : $invitations->get($g->invitation_id);
            $account = match (true) {
                $g->membership_id !== null => 'active',
                $invitation === null, $invitation->revoked_at !== null => 'none',
                strtotime((string) $invitation->expires_at) < time() => 'invitation_expired',
                default => 'invited',
            };

            return [
                'id' => $g->id,
                'full_name' => $g->full_name,
                'phone' => $g->phone,
                'status' => $g->status,
                'email' => $g->membership_id !== null ? ($memberEmail[$g->membership_id] ?? null) : $invitation?->email,
                'account' => $account,
                'children' => collect($children->get($g->id, []))->map(fn ($c) => [
                    'student_id' => $c->student_id, 'full_name' => $c->full_name, 'student_number' => $c->student_number,
                    'status' => $c->status, 'relationship' => $c->relationship, 'is_primary' => (bool) $c->is_primary,
                ])->values()->all(),
            ];
        })->all());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
