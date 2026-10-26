<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Demo\DemoMode;
use App\Domain\Identity\SessionPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'two_factor_enabled' => $user->hasConfirmedTwoFactor(),
                'must_change_password' => $user->must_change_password,
                'is_platform_admin' => $user->isPlatformAdmin(),
                'is_demo' => DemoMode::isDemoUser($user),
                'two_factor_passed' => SessionPolicy::twoFactorPassed($request, $user),
            ],
        ]);
    }
}
