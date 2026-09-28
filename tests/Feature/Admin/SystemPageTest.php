<?php

declare(strict_types=1);

use App\Models\System;
use App\Models\User;
use Livewire\Livewire;

const SYSTEM_PAGE_COMPONENT = 'pages::admin.systems.show';

beforeEach(function () {
    $this->system = System::factory()->withResponsePeriod(14)->create([
        'name' => 'Modmail Bot',
        'description' => 'Stores threads in MongoDB.',
    ]);
});

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('admin.systems.show', $this->system))
            ->assertRedirect(route('login'));
    });

    test('handlers cannot access a system page', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.systems.show', $this->system))
            ->assertForbidden();
    });

    test('admins can access a system page by its slug', function () {
        expect(route('admin.systems.show', $this->system))->toEndWith('/admin/systems/modmail-bot');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.systems.show', $this->system))
            ->assertOk()
            ->assertSee('Modmail Bot')
            ->assertSee('modmail-bot');
    });

    test('an unknown slug gives a 404', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/systems/does-not-exist')
            ->assertNotFound();
    });

    test('the page cannot be reached by id', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/systems/' . $this->system->id)
            ->assertNotFound();
    });
});

describe('editing', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
    });

    test('the form is filled with the current values', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->assertSet('form.name', 'Modmail Bot')
            ->assertSet('form.description', 'Stores threads in MongoDB.')
            ->assertSet('form.response_period_days', 14)
            ->assertSet('form.is_active', true);
    });

    test('an admin can edit a system', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set('form.name', 'Support Bot')
            ->set('form.description', 'Now in Postgres.')
            ->set('form.response_period_days', 7)
            ->call('save')
            ->assertHasNoErrors();

        $this->system->refresh();

        expect($this->system->name)->toBe('Support Bot')
            ->and($this->system->description)->toBe('Now in Postgres.')
            ->and($this->system->response_period_days)->toBe(7);
    });

    test('the response time can be cleared back to the gdpr default', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set('form.response_period_days', '')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->system->refresh()->response_period_days)->toBeNull();
    });

    test('an admin can deactivate a system and it stays in the list', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->system->refresh()->is_active)->toBeFalse();

        $this->actingAs($this->admin)
            ->get(route('admin.systems.index'))
            ->assertSee('Modmail Bot')
            ->assertSee('Inactive');
    });

    test('an admin can reactivate a system', function () {
        $inactive = System::factory()->inactive()->create();

        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $inactive])
            ->set('form.is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        expect($inactive->refresh()->is_active)->toBeTrue();
    });

    test('renaming a system keeps its slug and its url', function () {
        $urlBefore = route('admin.systems.show', $this->system);

        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set('form.name', 'Support Bot')
            ->call('save');

        $this->system->refresh();

        expect($this->system->slug)->toBe('modmail-bot')
            ->and(route('admin.systems.show', $this->system))->toBe($urlBefore);
    });

    test('saving confirms with a toast', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->call('save')
            ->assertDispatched('toast-show');
    });

    test('invalid input is rejected and nothing is saved', function (array $input, string $field) {
        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set($input)
            ->call('save')
            ->assertHasErrors($field);

        $this->system->refresh();

        expect($this->system->name)->toBe('Modmail Bot')
            ->and($this->system->response_period_days)->toBe(14);
    })->with([
        'missing name' => [['form.name' => ''], 'form.name'],
        'name too long' => [['form.name' => str_repeat('a', 256)], 'form.name'],
        'zero days' => [['form.response_period_days' => 0], 'form.response_period_days'],
        'more than a month' => [['form.response_period_days' => 32], 'form.response_period_days'],
    ]);

    test('editing one system does not touch the others', function () {
        $other = System::factory()->create(['name' => 'Discord Server']);

        Livewire::actingAs($this->admin)
            ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
            ->set('form.name', 'Support Bot')
            ->call('save');

        expect($other->refresh()->name)->toBe('Discord Server');
    });
});

test('handlers cannot save a system', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(SYSTEM_PAGE_COMPONENT, ['system' => $this->system])
        ->set('form.name', 'Hijacked')
        ->call('save')
        ->assertForbidden();

    expect($this->system->refresh()->name)->toBe('Modmail Bot');
});
