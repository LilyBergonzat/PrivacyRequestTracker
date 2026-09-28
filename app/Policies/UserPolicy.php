<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function disable(User $actor, User $target): bool
    {
        return $actor->isAdmin() && !$target->is($actor) && !$target->isDisabled();
    }

    public function enable(User $actor, User $target): bool
    {
        return $actor->isAdmin() && !$target->is($actor) && $target->isDisabled();
    }

    public function resetTwoFactor(User $actor, User $target): bool
    {
        return $actor->isAdmin() && !$target->is($actor) && $target->hasEnabledTwoFactorAuthentication();
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $actor->isAdmin() && !$target->is($actor);
    }
}
