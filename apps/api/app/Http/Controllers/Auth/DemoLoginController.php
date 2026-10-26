<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Demo\DemoMode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Masuk satu klik sebagai admin sekolah demo (tanpa sandi). 404 bila mode demo mati atau sekolah demo belum disiapkan,
 * sehingga endpoint ini tidak terlihat di lingkungan yang tidak memakainya.
 */
final class DemoLoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! DemoMode::enabled()) {
            throw new NotFoundHttpException;
        }

        $user = User::query()->where('email', DemoMode::adminEmail())->first();
        if ($user === null || ! $user->isActive()) {
            throw new NotFoundHttpException;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => ['demo' => true]]);
    }
}
