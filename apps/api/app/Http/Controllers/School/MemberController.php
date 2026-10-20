<?php

namespace App\Http\Controllers\School;

use App\Domain\Membership\Actions\CreateMemberAccount;
use App\Domain\Membership\Actions\ManageMembers;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeRolesRequest;
use App\Http\Requests\CreateMemberAccountRequest;
use App\Http\Requests\OptionalReasonRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\SetMemberPasswordRequest;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MemberController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageMembers $members, private readonly CreateMemberAccount $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $page = SchoolMembership::query()
            ->with(['user', 'roles'])
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('joined_at')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (SchoolMembership $m) => self::present($m))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Akun anggota dibuat langsung (email + password), tanpa undangan surel. */
    public function storeAccount(CreateMemberAccountRequest $request): JsonResponse
    {
        $membership = $this->accounts->create(
            $this->tenant->school(),
            $this->actor($request),
            trim((string) $request->validated('name')),
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->roles(),
            $request->validated('employee_number'),
        );

        return response()->json(['data' => self::present($membership)], 201);
    }

    /** Admin menetapkan password baru untuk anggota tanpa surel. */
    public function setPassword(SetMemberPasswordRequest $request, School $school, string $membership): JsonResponse
    {
        $this->accounts->setPassword($this->tenant->school(), $this->actor($request), $membership, (string) $request->validated('password'));

        return response()->json(['message' => 'Password diperbarui.']);
    }

    public function show(School $school, string $membership): JsonResponse
    {
        $found = SchoolMembership::query()->with(['user', 'roles'])->find($membership) ?? throw new NotFoundHttpException;

        return response()->json(['data' => self::present($found)]);
    }

    public function updateRoles(ChangeRolesRequest $request, School $school, string $membership): JsonResponse
    {
        return $this->respond($this->members->changeRoles($this->tenant->school(), $this->actor($request), $membership, $request->roles(), $request->reason()));
    }

    public function suspend(ReasonRequest $request, School $school, string $membership): JsonResponse
    {
        return $this->respond($this->members->suspend($this->tenant->school(), $this->actor($request), $membership, $request->reason()));
    }

    public function reactivate(OptionalReasonRequest $request, School $school, string $membership): JsonResponse
    {
        return $this->respond($this->members->reactivate($this->tenant->school(), $this->actor($request), $membership, $request->reason()));
    }

    public function end(ReasonRequest $request, School $school, string $membership): JsonResponse
    {
        return $this->respond($this->members->end($this->tenant->school(), $this->actor($request), $membership, $request->reason()));
    }

    public function requestPasswordReset(ReasonRequest $request, School $school, string $membership): JsonResponse
    {
        $this->members->requestPasswordReset($this->tenant->school(), $this->actor($request), $membership, $request->reason());

        return response()->json(['message' => 'Tautan reset password telah dikirim ke email anggota.'], 202);
    }

    private function respond(SchoolMembership $membership): JsonResponse
    {
        return response()->json(['data' => self::present($membership)]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SchoolMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'user' => ['id' => $membership->user->id, 'name' => $membership->user->name, 'email' => $membership->user->email],
            'status' => $membership->status,
            'status_reason' => $membership->status_reason,
            'roles' => array_map(fn ($role) => $role->value, $membership->roleEnums()),
            'joined_at' => $membership->joined_at->toIso8601String(),
            'ended_at' => $membership->ended_at?->toIso8601String(),
        ];
    }
}
