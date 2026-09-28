<?php

declare(strict_types=1);

use App\Enums\RequestType;
use App\Models\System;
use App\Models\SystemStep;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const SYSTEM_STEPS_COMPONENT = 'pages::admin.systems.show';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->system = System::factory()->create(['name' => 'Modmail Bot']);
    $this->otherSystem = System::factory()->create(['name' => 'French']);
});

function stepsPage(): Testable
{
    return Livewire::actingAs(test()->admin)
        ->test(SYSTEM_STEPS_COMPONENT, ['system' => test()->system]);
}

describe('listing', function () {
    test('an empty state is shown when the system has no steps', function () {
        stepsPage()->assertSee('No steps yet');
    });

    test('steps are listed in order with their type and status', function () {
        SystemStep::factory()->for($this->system)->create(['title' => 'Connect']);
        SystemStep::factory()->for($this->system)->forType(RequestType::Deletion)->create(['title' => 'Delete threads']);
        SystemStep::factory()->for($this->system)->inactive()->create(['title' => 'Old step']);

        stepsPage()
            ->assertDontSee('No steps yet')
            ->assertSeeInOrder(['Connect', 'All types', 'Delete threads', 'Deletion', 'Old step', 'Inactive']);
    });

    test('steps of other systems are not listed', function () {
        SystemStep::factory()->for($this->otherSystem)->create(['title' => 'Someone else’s step']);

        stepsPage()->assertDontSee('Someone else’s step');
    });
});

describe('creating steps', function () {
    test('new step opens an empty form', function () {
        $existing = SystemStep::factory()->for($this->system)->create(['title' => 'Connect']);

        stepsPage()
            ->call('editStep', $existing->id)
            ->call('newStep')
            ->assertSet('stepForm.step', null)
            ->assertSet('stepForm.title', '')
            ->assertSet('stepForm.instructions', '');
    });

    test('an admin can create a step for all request types', function () {
        stepsPage()
            ->call('newStep')
            ->set('stepForm.request_type', '')
            ->set('stepForm.title', 'Connect')
            ->set('stepForm.instructions', 'mongosh "<MONGO_URI>"')
            ->call('saveStep')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show');

        $step = SystemStep::sole();

        expect($step->system_id)->toBe($this->system->id)
            ->and($step->request_type)->toBeNull()
            ->and($step->title)->toBe('Connect')
            ->and($step->instructions)->toBe('mongosh "<MONGO_URI>"')
            ->and($step->is_active)->toBeTrue();
    });

    test('an admin can create a step for a specific request type', function () {
        stepsPage()
            ->call('newStep')
            ->set('stepForm.request_type', RequestType::Deletion->value)
            ->set('stepForm.title', 'Delete threads')
            ->set('stepForm.instructions', 'db.logs.deleteMany({ "recipient.id": uid });')
            ->call('saveStep')
            ->assertHasNoErrors();

        expect(SystemStep::sole()->request_type)->toBe(RequestType::Deletion);
    });

    test('placeholders are stored as typed', function () {
        stepsPage()
            ->call('newStep')
            ->set('stepForm.title', 'Preview')
            ->set('stepForm.instructions', 'const uid = "{{discord_id}}";')
            ->call('saveStep')
            ->assertHasNoErrors();

        expect(SystemStep::sole()->instructions)->toBe('const uid = "{{discord_id}}";');
    });

    test('new steps are added at the end of the system', function () {
        SystemStep::factory()->for($this->system)->count(2)->create();

        stepsPage()
            ->call('newStep')
            ->set('stepForm.title', 'Last')
            ->set('stepForm.instructions', '…')
            ->call('saveStep');

        expect(SystemStep::where('title', 'Last')->sole()->sort_order)->toBe(2)
            ->and($this->system->steps()->ordered()->pluck('title')->last())->toBe('Last');
    });

    test('the new step shows up in the list and the form is cleared', function () {
        stepsPage()
            ->call('newStep')
            ->set('stepForm.title', 'Connect')
            ->set('stepForm.instructions', '…')
            ->call('saveStep')
            ->assertSee('Connect')
            ->assertSet('stepForm.title', '');
    });

    test('invalid input is rejected', function (array $input, string $field) {
        stepsPage()
            ->call('newStep')
            ->set(['stepForm.title' => 'Connect', 'stepForm.instructions' => '…', ...$input])
            ->call('saveStep')
            ->assertHasErrors($field);

        expect(SystemStep::count())->toBe(0);
    })->with([
        'missing title' => [['stepForm.title' => ''], 'stepForm.title'],
        'title too long' => [['stepForm.title' => str_repeat('a', 256)], 'stepForm.title'],
        'missing instructions' => [['stepForm.instructions' => ''], 'stepForm.instructions'],
        'unknown request type' => [['stepForm.request_type' => 'bogus'], 'stepForm.request_type'],
    ]);
});

describe('editing steps', function () {
    beforeEach(function () {
        $this->step = SystemStep::factory()->for($this->system)->forType(RequestType::Deletion)->create([
            'title' => 'Delete threads',
            'instructions' => 'db.logs.deleteMany()',
        ]);
    });

    test('the form is filled with the step values', function () {
        stepsPage()
            ->call('editStep', $this->step->id)
            ->assertSet('stepForm.title', 'Delete threads')
            ->assertSet('stepForm.instructions', 'db.logs.deleteMany()')
            ->assertSet('stepForm.request_type', RequestType::Deletion->value)
            ->assertSet('stepForm.is_active', true);
    });

    test('an admin can edit a step without creating a new one', function () {
        stepsPage()
            ->call('editStep', $this->step->id)
            ->set('stepForm.title', 'Delete their threads')
            ->set('stepForm.request_type', '')
            ->set('stepForm.is_active', false)
            ->call('saveStep')
            ->assertHasNoErrors();

        $this->step->refresh();

        expect(SystemStep::count())->toBe(1)
            ->and($this->step->title)->toBe('Delete their threads')
            ->and($this->step->request_type)->toBeNull()
            ->and($this->step->is_active)->toBeFalse();
    });

    test('editing clears errors left over from a previous attempt', function () {
        stepsPage()
            ->call('newStep')
            ->call('saveStep')
            ->assertHasErrors('stepForm.title')
            ->call('editStep', $this->step->id)
            ->assertHasNoErrors();
    });

    test('a step of another system cannot be edited from this page', function () {
        $foreign = SystemStep::factory()->for($this->otherSystem)->create();

        stepsPage()
            ->call('editStep', $foreign->id)
            ->assertNotFound();
    });
});

describe('deleting steps', function () {
    test('an admin can delete a step', function () {
        $step = SystemStep::factory()->for($this->system)->create(['title' => 'Connect']);

        stepsPage()
            ->call('deleteStep', $step->id)
            ->assertOk()
            ->assertDontSee('Connect');

        expect(SystemStep::find($step->id))->toBeNull();
    });

    test('a step of another system cannot be deleted from this page', function () {
        $foreign = SystemStep::factory()->for($this->otherSystem)->create();

        stepsPage()
            ->call('deleteStep', $foreign->id)
            ->assertNotFound();

        expect(SystemStep::find($foreign->id))->not->toBeNull();
    });
});

describe('reordering steps', function () {
    beforeEach(function () {
        foreach (['First', 'Second', 'Third'] as $title) {
            SystemStep::factory()->for($this->system)->create(['title' => $title]);
        }

        foreach (['Un', 'Deux'] as $title) {
            SystemStep::factory()->for($this->otherSystem)->create(['title' => $title]);
        }
    });

    test('a step can be moved', function () {
        $third = SystemStep::where('title', 'Third')->sole();

        stepsPage()
            ->call('sortStep', $third->id, 0)
            ->assertOk();

        expect($this->system->steps()->ordered()->pluck('title')->all())->toBe(['Third', 'First', 'Second'])
            ->and($this->system->steps()->ordered()->pluck('sort_order')->all())->toBe([0, 1, 2]);
    });

    test('reordering one system does not touch the steps of another', function () {
        $otherBefore = $this->otherSystem->steps()->ordered()->get(['id', 'sort_order'])->toArray();
        $third = SystemStep::where('title', 'Third')->sole();

        stepsPage()->call('sortStep', $third->id, 0);

        expect($this->otherSystem->steps()->ordered()->get(['id', 'sort_order'])->toArray())->toBe($otherBefore);
    });

    test('a step of another system cannot be moved from this page', function () {
        $foreign = SystemStep::where('title', 'Deux')->sole();

        stepsPage()
            ->call('sortStep', $foreign->id, 0)
            ->assertNotFound();

        expect($this->otherSystem->steps()->ordered()->pluck('title')->all())->toBe(['Un', 'Deux']);
    });

    test('a negative position is rejected', function () {
        $first = SystemStep::where('title', 'First')->sole();

        stepsPage()
            ->call('sortStep', $first->id, -1)
            ->assertStatus(400);

        expect($this->system->steps()->ordered()->pluck('title')->all())->toBe(['First', 'Second', 'Third']);
    });
});

describe('handlers', function () {
    test('handlers cannot change steps', function (string $method, Closure $arguments) {
        $step = SystemStep::factory()->for($this->system)->create(['title' => 'Connect']);
        SystemStep::factory()->for($this->system)->create();

        Livewire::actingAs(User::factory()->create())
            ->test(SYSTEM_STEPS_COMPONENT, ['system' => $this->system])
            ->set(['stepForm.title' => 'Sneaky', 'stepForm.instructions' => '…'])
            ->call($method, ...$arguments($step))
            ->assertForbidden();

        expect($this->system->steps()->ordered()->pluck('title')->first())->toBe('Connect')
            ->and(SystemStep::where('title', 'Sneaky')->exists())->toBeFalse();
    })->with([
        'create' => ['saveStep', fn (SystemStep $step): array => []],
        'delete' => ['deleteStep', fn (SystemStep $step): array => [$step->id]],
        'reorder' => ['sortStep', fn (SystemStep $step): array => [$step->id, 1]],
    ]);
});

describe('markdown', function () {
    test('step instructions are rendered as markdown in the list', function () {
        SystemStep::factory()->for($this->system)->create([
            'title' => 'Preview',
            'instructions' => "Write the **counts** in the summary.\n\n<script>alert(1)</script>",
        ]);

        stepsPage()
            ->assertSeeHtml('<strong>counts</strong>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    });

    test('the preview follows what is typed in the modal', function () {
        stepsPage()
            ->call('newStep')
            ->set('stepForm.instructions', 'Run **this** first')
            ->assertSeeHtml('<strong>this</strong>')
            ->set('stepForm.instructions', 'Now <script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    });

    test('the system description is rendered as markdown', function () {
        $this->system->forceFill(['description' => 'Stores **threads** in MongoDB.'])->save();

        stepsPage()->assertSeeHtml('<strong>threads</strong>');
    });

    test('the description at the top updates right after saving', function () {
        stepsPage()
            ->set('form.description', 'Now in **Postgres**.')
            ->call('save')
            ->assertSeeHtml('<strong>Postgres</strong>');
    });
});
