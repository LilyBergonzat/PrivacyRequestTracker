<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;

const SEEDED_ADMIN_EMAIL = 'test@example.com';

test('the admin is seeded correctly', function () {
    config(['privacy.admin_email' => SEEDED_ADMIN_EMAIL]);

    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(1);

    $user = User::first();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->email)->toBe(SEEDED_ADMIN_EMAIL)
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('the admin seed creates only one user even if ran multiple times', function () {
    config(['privacy.admin_email' => SEEDED_ADMIN_EMAIL]);

    $this->seed(AdminUserSeeder::class);

    $passwordHash = User::first()->password;

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(1);

    $user = User::first();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->password)->toBe($passwordHash);
});

test('the admin is not seeded when no email configured', function () {
    config(['privacy.admin_email' => null]);

    $this->seed(AdminUserSeeder::class);

    expect(User::count())->toBe(0);
});
