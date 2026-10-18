<?php

namespace App\Http\Controllers\School;

use App\Domain\Membership\Actions\ManageInvitations;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\InvitationPresenter;
use App\Http\Requests\InviteMemberRequest;
use App\Models\MembershipInvitation;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvitationController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly ManageInvitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $page = MembershipInvitation::query()->safe()->orderByDesc('created_at')->orderByDesc('id')->paginate(50);
        $filter = $request->query('status');

        $items = collect($page->items())
            ->when(is_string($filter) && $filter !== '', fn ($c) => $c->filter(fn (MembershipInvitation $i) => $i->status() === $filter))
            ->map(fn (MembershipInvitation $i) => InvitationPresenter::present($i))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(InviteMemberRequest $request): JsonResponse
    {
        $invitation = $this->invitations->invite($this->tenant->school(), $this->actor($request), $request->email(), $request->roles());

        return response()->json(['data' => InvitationPresenter::present($invitation)], 201);
    }

    public function resend(Request $request, School $school, string $invitation): JsonResponse
    {
        return response()->json(['data' => InvitationPresenter::present(
            $this->invitations->resend($this->tenant->school(), $this->actor($request), $invitation),
        )]);
    }

    public function revoke(Request $request, School $school, string $invitation): JsonResponse
    {
        return response()->json(['data' => InvitationPresenter::present(
            $this->invitations->revoke($this->tenant->school(), $this->actor($request), $invitation),
        )]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
