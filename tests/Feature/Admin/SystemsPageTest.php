<?php

declare(strict_types=1);

use App\Models\System;
use App\Models\User;
use Livewire\Livewire;

const SYSTEMS_PAGE_COMPONENT = 'pages::admin.systems.index';

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('admin.systems.index'))
            ->assertRedirect(route('login'));
    });

    test('handlers cannot access the systems page', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.systems.index'))
            ->assertForbidden();
    });

    test('admins can access the systems page', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.systems.index'))
            ->assertOk()
            ->assertSeeInOrder(['<title>', 'Systems', '</title>'], escape: false);
    });

    test('the systems link is shown to admins', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee(route('admin.systems.index'));
    });

    test('the systems link is hidden from handlers', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('admin.systems.index'));
    });
});

describe('listing', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create());
    });

    test('an empty state is shown when there are no systems', function () {
        $this->get(route('admin.systems.index'))
            ->assertSee('No systems yet');
    });

    test('systems are listed by sort order, inactive ones included', function () {
        System::factory()->create(['name' => 'Second System', 'sort_order' => 2]);
        System::factory()->inactive()->create(['name' => 'Inactive System', 'sort_order' => 1]);
        System::factory()->create(['name' => 'First System', 'sort_order' => 0]);

        $this->get(route('admin.systems.index'))
            ->assertDontSee('No systems yet')
            ->assertSeeInOrder(['First System', 'Inactive System', 'Second System'])
            ->assertSee('Inactive');
    });

    test('the slug is shown under the name', function () {
        System::factory()->create(['name' => 'Modmail Bot']);

        $this->get(route('admin.systems.index'))
            ->assertSeeInOrder(['Modmail Bot', 'modmail-bot']);
    });

    test('the response time is shown in days when set', function (int $days, string $expected) {
        System::factory()->withResponsePeriod($days)->create();

        $this->get(route('admin.systems.index'))
            ->assertSee($expected)
            ->assertDontSee('GDPR default');
    })->with([
        'singular' => [1, '1 day'],
        'plural' => [14, '14 days'],
    ]);

    test('each system links to its management page', function () {
        $system = System::factory()->create(['name' => 'Modmail Bot']);

        $this->get(route('admin.systems.index'))
            ->assertSee(route('admin.systems.show', $system));
    });

    test('the gdpr default is shown when no response time is set', function () {
        System::factory()->create();

        $this->get(route('admin.systems.index'))
            ->assertSee('1 month')
            ->assertSee('GDPR default');
    });
});

describe('creating systems', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
    });

    test('an admin can create a system with the gdpr default response time', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Modmail Bot')
            ->set('form.response_period_days', '')
            ->call('createSystem')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $system = System::where('slug', 'modmail-bot')->firstOrFail();

        expect($system->name)->toBe('Modmail Bot')
            ->and($system->response_period_days)->toBeNull()
            ->and($system->is_active)->toBeTrue();
    });

    test('an admin can create a system with a custom response time and a description', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Modmail Bot')
            ->set('form.description', 'Stores **threads** in MongoDB.')
            ->set('form.response_period_days', 14)
            ->call('createSystem')
            ->assertHasNoErrors();

        $system = System::where('slug', 'modmail-bot')->firstOrFail();

        expect($system->response_period_days)->toBe(14)
            ->and($system->description)->toBe('Stores **threads** in MongoDB.');
    });

    test('the new system shows up in the list', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->assertSee('No systems yet')
            ->set('form.name', 'Modmail Bot')
            ->call('createSystem')
            ->assertDontSee('No systems yet')
            ->assertSee('Modmail Bot');
    });

    test('the form is cleared after creating a system', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Modmail Bot')
            ->set('form.description', 'Something')
            ->set('form.response_period_days', 14)
            ->call('createSystem')
            ->assertSet('form.name', '')
            ->assertSet('form.response_period_days', null);
    });

    test('closing the modal resets it for the next system', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('createSystem')
            ->assertHasErrors('form.name')
            ->call('closeCreateModal')
            ->assertHasNoErrors()
            ->assertSet('saved', false);
    });

    test('closing the modal discards a half-filled form', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Half')
            ->set('form.response_period_days', 14)
            ->call('closeCreateModal')
            ->assertSet('form.name', '')
            ->assertSet('form.response_period_days', null);

        expect(System::count())->toBe(0);
    });

    test('invalid input is rejected', function (array $input, string $field) {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set(['form.name' => 'Modmail Bot', ...$input])
            ->call('createSystem')
            ->assertHasErrors($field)
            ->assertSet('saved', false);

        expect(System::count())->toBe(0);
    })->with([
        'missing name' => [['form.name' => ''], 'form.name'],
        'name too long' => [['form.name' => str_repeat('a', 256)], 'form.name'],
        'zero days' => [['form.response_period_days' => 0], 'form.response_period_days'],
        'negative days' => [['form.response_period_days' => -3], 'form.response_period_days'],
        'more than a month' => [['form.response_period_days' => 32], 'form.response_period_days'],
    ]);

    test('a new system is added at the end of the list', function () {
        System::factory()->create(['name' => 'Zeta', 'sort_order' => 0]);
        System::factory()->create(['name' => 'Yota', 'sort_order' => 5]);

        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Alpha')
            ->call('createSystem')
            ->assertHasNoErrors();

        expect(System::where('name', 'Alpha')->firstOrFail()->sort_order)->toBe(6)
            ->and(System::ordered()->pluck('name')->last())->toBe('Alpha');
    });

    test('the first system gets sort order zero', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Alpha')
            ->call('createSystem');

        expect(System::where('name', 'Alpha')->firstOrFail()->sort_order)->toBe(0);
    });

    test('handlers cannot create systems', function () {
        Livewire::actingAs(User::factory()->create())
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->set('form.name', 'Sneaky System')
            ->call('createSystem')
            ->assertForbidden();

        expect(System::count())->toBe(0);
    });
});

describe('reordering', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();

        $this->first = System::factory()->create(['name' => 'First', 'sort_order' => 0]);
        $this->second = System::factory()->create(['name' => 'Second', 'sort_order' => 1]);
        $this->third = System::factory()->create(['name' => 'Third', 'sort_order' => 2]);
        $this->fourth = System::factory()->create(['name' => 'Fourth', 'sort_order' => 3]);
    });

    test('a system can be moved to the top', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->fourth->id, 0)
            ->assertOk();

        expect(System::ordered()->pluck('name')->all())->toBe(['Fourth', 'First', 'Second', 'Third']);
    });

    test('a system can be moved down', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->first->id, 2)
            ->assertOk();

        expect(System::ordered()->pluck('name')->all())->toBe(['Second', 'Third', 'First', 'Fourth']);
    });

    test('dropping a system where it already is changes nothing', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->second->id, 1)
            ->assertOk();

        expect(System::ordered()->pluck('name')->all())->toBe(['First', 'Second', 'Third', 'Fourth']);
    });

    test('a position past the end moves the system last', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->first->id, 99)
            ->assertOk();

        expect(System::ordered()->pluck('name')->last())->toBe('First');
    });

    test('sort orders are renumbered without gaps', function () {
        $this->second->forceFill(['sort_order' => 40])->save();
        $this->third->forceFill(['sort_order' => 41])->save();
        $this->fourth->forceFill(['sort_order' => 90])->save();

        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->fourth->id, 1);

        expect(System::ordered()->pluck('sort_order')->all())->toBe([0, 1, 2, 3])
            ->and(System::ordered()->pluck('name')->all())->toBe(['First', 'Fourth', 'Second', 'Third']);
    });

    test('inactive systems are reordered like the others', function () {
        $this->second->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->second->id, 3);

        expect(System::ordered()->pluck('name')->all())->toBe(['First', 'Third', 'Fourth', 'Second']);
    });

    test('the list is shown in the new order', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->fourth->id, 0)
            ->assertSeeInOrder(['Fourth', 'First', 'Second', 'Third']);
    });

    test('an unknown system is rejected and nothing moves', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', 999_999, 0)
            ->assertNotFound();

        expect(System::ordered()->pluck('sort_order')->all())->toBe([0, 1, 2, 3]);
    });

    test('a negative position is rejected and nothing moves', function () {
        Livewire::actingAs($this->admin)
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->first->id, -1)
            ->assertStatus(400);

        expect(System::ordered()->pluck('name')->all())->toBe(['First', 'Second', 'Third', 'Fourth']);
    });

    test('handlers cannot reorder systems', function () {
        Livewire::actingAs(User::factory()->create())
            ->test(SYSTEMS_PAGE_COMPONENT)
            ->call('sortSystem', $this->fourth->id, 0)
            ->assertForbidden();

        expect(System::ordered()->pluck('name')->first())->toBe('First');
    });

    test('the rows are sortable by id', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.systems.index'))
            ->assertSee('wire:sort="sortSystem"', escape: false)
            ->assertSee('wire:sort:item="' . $this->first->id . '"', escape: false);
    });
});
