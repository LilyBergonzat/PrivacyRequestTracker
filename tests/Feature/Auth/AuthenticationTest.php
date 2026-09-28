<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    config(['privacy.require_two_factor' => false]);

    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('a disabled user cannot log in', function () {
    $user = User::factory()->disabled()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('a logged-in disabled user gets automatically logged out', function () {
    $user = User::factory()->disabled()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a user without 2FA gets redirected when mandatory', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertRedirect(route('security.edit'));

    $this->assertAuthenticated();
});

test('a user with 2FA does not get redirected when mandatory', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);

    $this->assertAuthenticated();
});

test('a user without 2FA can reach security.edit when mandatory', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)->withSession([
        'auth.password_confirmed_at' => time(),
    ])->get(route('security.edit'));

    $response->assertStatus(200);

    $this->assertAuthenticated();
});

test('a user without 2FA does not get redirected when not mandatory', function () {
    config(['privacy.require_two_factor' => false]);

    $user = User::factory()->withoutTwoFactor()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);

    $this->assertAuthenticated();
});
