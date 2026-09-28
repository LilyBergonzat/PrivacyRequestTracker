<?php

declare(strict_types=1);

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'));
});

test('handlers cannot access the users page', function () {
    $handler = User::factory()->create();

    $this->actingAs($handler)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('admins without two-factor authentication are sent to set it up first', function () {
    $admin = User::factory()->admin()->withoutTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertRedirect(route('security.edit'));
});

test('admins can see every user sorted by name', function () {
    $admin = User::factory()->admin()->create(['name' => 'Charlie Admin']);
    User::factory()->create(['name' => 'Alice Handler']);
    User::factory()->disabled()->create(['name' => 'Bob Disabled']);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSeeInOrder(['Alice Handler', 'Bob Disabled', 'Charlie Admin']);
});

test('the users link is shown to admins', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.users.index'));
});

test('the users link is hidden from handlers', function () {
    $handler = User::factory()->create();

    $this->actingAs($handler)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Administration')
        ->assertDontSee(route('admin.users.index'));
});
