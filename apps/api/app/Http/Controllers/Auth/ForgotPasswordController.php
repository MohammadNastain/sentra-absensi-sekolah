<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Selalu 202, apa pun hasilnya (email terdaftar, tidak terdaftar, user nonaktif, atau dibatasi
 * broker), sehingga endpoint tidak dapat dipakai menebak email terdaftar (TAHAP_1_FONDASI §3).
 */
final class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);

        Password::sendResetLink(['email' => mb_strtolower(trim($validated['email']))], function ($user, string $token) {
            if ($user instanceof User && $user->isActive()) {
                $user->sendPasswordResetNotification($token);
            }
        });

        return response()->json(['message' => 'Bila email terdaftar, tautan reset password telah dikirim.'], 202);
    }
}
