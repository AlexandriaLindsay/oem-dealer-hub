<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Brings someone in by email. A new person gets an account with no usable
 * password and a "set your password" email (the password reset link); someone
 * who already has an account - say, a dealer already on OEM Dealer Hub for another
 * brand - just gets the new membership and signs in as usual.
 */
final class Invite
{
    public static function user(string $name, string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
            Password::sendResetLink(['email' => $email]);
        }

        return $user;
    }
}
