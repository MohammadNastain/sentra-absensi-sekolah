<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Identity\Role;
use App\Domain\Membership\Actions\ManageInvitations;
use App\Domain\Tenancy\Actions\ManageSchools;
use App\Http\Controllers\Controller;
use App\Http\Requests\OptionalReasonRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\StoreSchoolRequest;
use App\Http\Requests\UpdateSchoolRequest;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformSchoolController extends Controller
{
    public function __construct(private readonly ManageSchools $schools) {}

    public function index(): JsonResponse
    {
        $schools = School::query()->orderBy('name')->paginate(50);

        return response()->json([
            'data' => collect($schools->items())->map(fn (School $school) => self::present($school))->all(),
            'meta' => [
                'current_page' => $schools->currentPage(),
                'last_page' => $schools->lastPage(),
                'total' => $schools->total(),
            ],
        ]);
    }

    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $school = $this->schools->create($this->actor($request), $request->validated());

        return response()->json(['data' => self::present($school)], 201);
    }

    public function show(School $school): JsonResponse
    {
        return response()->json(['data' => self::present($school)]);
    }

    public function update(UpdateSchoolRequest $request, School $school): JsonResponse
    {
        return response()->json(['data' => self::present(
            $this->schools->update($this->actor($request), $school, $request->validated(), platform: true),
        )]);
    }

    public function suspend(ReasonRequest $request, School $school): JsonResponse
    {
        return response()->json(['data' => self::present(
            $this->schools->suspend($this->actor($request), $school, $request->reason()),
        )]);
    }

    public function activate(OptionalReasonRequest $request, School $school): JsonResponse
    {
        return response()->json(['data' => self::present(
            $this->schools->activate($this->actor($request), $school, $request->reason()),
        )]);
    }

    /**
     * Undang Admin Sekolah pertama. Peran selalu school_admin (tidak dapat dipilih dari body).
     */
    public function inviteAdmin(Request $request, School $school, ManageInvitations $invitations): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        $invitation = $invitations->invite($school, $this->actor($request), mb_strtolower(trim($validated['email'])), [Role::SchoolAdmin]);

        return response()->json(['data' => InvitationPresenter::present($invitation)], 201);
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
    public static function present(School $school): array
    {
        return [
            'id' => $school->id,
            'code' => $school->code,
            'npsn' => $school->npsn,
            'name' => $school->name,
            'education_levels' => $school->education_levels,
            'timezone' => $school->timezone,
            'address' => $school->address,
            'status' => $school->status,
        ];
    }
}
