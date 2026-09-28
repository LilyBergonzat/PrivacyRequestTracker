<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

const USERS_PAGE_COMPONENT = 'pages::admin.users';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->handler = User::factory()->create();
});

describe('disabling and enabling', function () {
    test('an admin can disable a user', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('disable', $this->handler->id)
            ->assertOk();

        expect($this->handler->refresh()->isDisabled())->toBeTrue();
    });

    test('an admin can enable a disabled user', function () {
        $disabled = User::factory()->disabled()->create();

        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('enable', $disabled->id)
            ->assertOk();

        expect($disabled->refresh()->isDisabled())->toBeFalse();
    });

    test('an admin cannot disable themselves', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('disable', $this->admin->id)
            ->assertForbidden();

        expect($this->admin->refresh()->isDisabled())->toBeFalse();
    });

    test('an already disabled user cannot be disabled again', function () {
        $disabled = User::factory()->disabled()->create();
        $disabledAt = $disabled->disabled_at;

        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('disable', $disabled->id)
            ->assertForbidden();

        expect($disabled->refresh()->disabled_at->equalTo($disabledAt))->toBeTrue();
    });

    test('an active user cannot be enabled', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('enable', $this->handler->id)
            ->assertForbidden();
    });
});

describe('two-factor reset', function () {
    test('an admin can reset the two-factor authentication of a user', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('resetTwoFactor', $this->handler->id)
            ->assertOk();

        $this->handler->refresh();

        expect($this->handler->hasEnabledTwoFactorAuthentication())->toBeFalse()
            ->and($this->handler->two_factor_secret)->toBeNull()
            ->and($this->handler->two_factor_recovery_codes)->toBeNull();
    });

    test('an admin cannot reset their own two-factor authentication here', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('resetTwoFactor', $this->admin->id)
            ->assertForbidden();

        expect($this->admin->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    });

    test('a user without two-factor authentication cannot be reset', function () {
        $withoutTwoFactor = User::factory()->withoutTwoFactor()->create();

        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('resetTwoFactor', $withoutTwoFactor->id)
            ->assertForbidden();
    });
});

describe('role changes', function () {
    test('an admin can promote a handler', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('changeRole', $this->handler->id, UserRole::Admin->value)
            ->assertOk();

        expect($this->handler->refresh()->role)->toBe(UserRole::Admin);
    });

    test('an admin can demote another admin', function () {
        $otherAdmin = User::factory()->admin()->create();

        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('changeRole', $otherAdmin->id, UserRole::Handler->value)
            ->assertOk();

        expect($otherAdmin->refresh()->role)->toBe(UserRole::Handler);
    });

    test('an admin cannot change their own role', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('changeRole', $this->admin->id, UserRole::Handler->value)
            ->assertForbidden();

        expect($this->admin->refresh()->role)->toBe(UserRole::Admin);
    });

    test('an unknown role is rejected', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->call('changeRole', $this->handler->id, 'superadmin');
    })->throws(ValueError::class);
});

describe('creating users', function () {
    test('an admin can create a user with a generated password', function () {
        $component = Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'New Person')
            ->set('email', 'new.person@example.com')
            ->set('role', UserRole::Admin->value)
            ->call('createUser')
            ->assertHasNoErrors();

        $created = User::where('email', 'new.person@example.com')->firstOrFail();
        $password = $component->get('generatedPassword');

        expect($created->name)->toBe('New Person')
            ->and($created->role)->toBe(UserRole::Admin)
            ->and($created->isDisabled())->toBeFalse()
            ->and($created->hasEnabledTwoFactorAuthentication())->toBeFalse()
            ->and($password)->toBeString()->not->toBeEmpty()
            ->and(Hash::check($password, $created->password))->toBeTrue();
    });

    test('new users are handlers by default', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'New Person')
            ->set('email', 'new.person@example.com')
            ->call('createUser')
            ->assertHasNoErrors();

        expect(User::where('email', 'new.person@example.com')->firstOrFail()->role)
            ->toBe(UserRole::Handler);
    });

    test('the form is cleared after creating a user', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'New Person')
            ->set('email', 'new.person@example.com')
            ->set('role', UserRole::Admin->value)
            ->call('createUser')
            ->assertSet('name', '')
            ->assertSet('email', '')
            ->assertSet('role', UserRole::Handler->value);
    });

    test('closing the modal forgets the generated password', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'New Person')
            ->set('email', 'new.person@example.com')
            ->call('createUser')
            ->call('closeCreateModal')
            ->assertSet('generatedPassword', null);
    });

    test('the generated password is not stored in the session', function () {
        $component = Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'New Person')
            ->set('email', 'new.person@example.com')
            ->call('createUser');

        expect(json_encode(session()->all()))->not->toContain($component->get('generatedPassword'));
    });

    test('invalid input is rejected', function (array $input, string $field) {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set([
                'name' => 'New Person',
                'email' => 'new.person@example.com',
                'role' => UserRole::Handler->value,
                ...$input,
            ])
            ->call('createUser')
            ->assertHasErrors($field)
            ->assertSet('generatedPassword', null);

        expect(User::where('name', 'New Person')->exists())->toBeFalse();
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'missing email' => [['email' => ''], 'email'],
        'malformed email' => [['email' => 'not-an-email'], 'email'],
        'unknown role' => [['role' => 'superadmin'], 'role'],
    ]);

    test('an email that is already used is rejected', function () {
        Livewire::actingAs($this->admin)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'Duplicate')
            ->set('email', $this->handler->email)
            ->call('createUser')
            ->assertHasErrors(['email' => 'unique']);

        expect(User::where('email', $this->handler->email)->count())->toBe(1);
    });
});

describe('handlers', function () {
    // Each target is one an admin would be allowed to act on, so only the role makes the difference.
    test('handlers cannot call any user management action', function (string $method, bool $disabledTarget, array $extraArguments) {
        $target = $disabledTarget ? User::factory()->disabled()->create() : User::factory()->create();

        Livewire::actingAs($this->handler)
            ->test(USERS_PAGE_COMPONENT)
            ->call($method, $target->id, ...$extraArguments)
            ->assertForbidden();
    })->with([
        'disable' => ['disable', false, []],
        'enable' => ['enable', true, []],
        'reset two-factor' => ['resetTwoFactor', false, []],
        'promote' => ['changeRole', false, ['admin']],
    ]);

    test('handlers cannot create users', function () {
        Livewire::actingAs($this->handler)
            ->test(USERS_PAGE_COMPONENT)
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@example.com')
            ->set('role', UserRole::Admin->value)
            ->call('createUser')
            ->assertForbidden();

        expect(User::where('email', 'sneaky@example.com')->exists())->toBeFalse();
    });
});
