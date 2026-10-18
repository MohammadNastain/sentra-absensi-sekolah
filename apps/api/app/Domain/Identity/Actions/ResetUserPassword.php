<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => PasswordRules::forNewPassword()])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
