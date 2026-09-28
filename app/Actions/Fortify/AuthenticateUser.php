<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $email = $request->string('email')->toString();
        $password = $request->string('password')->toString();
        $targetUser = User::where('email', $email)->first();
        $hashCheck = $targetUser && Hash::check($password, $targetUser->password);

        return $hashCheck && !$targetUser->isDisabled() ? $targetUser : null;
    }
}
