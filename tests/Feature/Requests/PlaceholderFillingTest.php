<?php

declare(strict_types=1);

use App\Enums\RequestStatus;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\SystemStep;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->handler = User::factory()->create();

    $this->request = PrivacyRequest::factory()->create();
    $this->request->forceFill(['status' => RequestStatus::InProgress])->save();

    $this->system = System::factory()->create(['name' => 'Modmail Bot']);
    $this->step = SystemStep::factory()->for($this->system)->create([
        'title' => 'Preview',
        'instructions' => "```js\nconst uid = \"{{discord_id}}\";\ndb.notes.countDocuments({ recipient: uid, by: \"{{ Email }}\" });\n```",
    ]);
    $this->row = RequestSystem::factory()->for($this->request)->for($this->system)->create();
});

function placeholderPage(): Testable
{
    return Livewire::actingAs(test()->handler)
        ->test('pages::requests.show', ['privacyRequest' => test()->request->fresh()]);
}

describe('finding placeholders', function () {
    test('a step lists the placeholders found in its instructions, once each', function () {
        $step = SystemStep::factory()->make([
            'instructions' => 'Use {{discord_id}} then {{ discord_id }} and {{email}}, but not {{ not valid! }} or {single}.',
        ]);

        expect($step->placeholders())->toBe(['discord_id', 'email']);
    });

    test('a step without placeholders has none', function () {
        expect(SystemStep::factory()->make(['instructions' => 'Nothing to fill.'])->placeholders())->toBe([]);
    });

    test('the page gathers the placeholders of every shown step', function () {
        SystemStep::factory()->for($this->system)->create(['instructions' => 'Also {{username}} and {{discord_id}}']);

        expect(placeholderPage()->instance()->placeholderNames)->toBe(['Email', 'discord_id', 'username']);
    });
});

describe('the fill box', function () {
    test('one field per placeholder is shown, bound only to the browser', function () {
        placeholderPage()
            ->assertSee('Fill placeholders')
            ->assertSee('Not saved or sent anywhere')
            ->assertSeeHtml('x-model="$store.placeholders.values.discord_id"')
            ->assertSeeHtml('x-model="$store.placeholders.values.email"')
            ->assertDontSeeHtml('wire:model="$store');
    });

    test('the fill box is never bound to a Livewire property', function () {
        $html = placeholderPage()->html();

        $boxStart = strpos($html, 'Fill placeholders');
        $box = substr($html, $boxStart, strpos($html, 'wire:key="row-') - $boxStart);

        expect($box)->toContain('x-model="$store.placeholders.values.discord_id"')
            ->not->toContain('wire:model');
    });

    test('the fill box is hidden when no step has placeholders', function () {
        $this->step->update(['instructions' => 'Nothing to fill.']);

        placeholderPage()->assertDontSee('Fill placeholders');
    });

    test('the fill box is hidden on a closed request', function () {
        $this->request->forceFill(['status' => RequestStatus::Completed])->save();

        placeholderPage()->assertDontSee('Fill placeholders');
    });

    test('the fill box survives Livewire re-renders', function () {
        placeholderPage()->assertSeeHtml('<div wire:ignore class="space-y-3 rounded-lg border border-dashed');
    });
});

describe('the instructions', function () {
    test('step instructions are wired for client-side filling and copy buttons', function () {
        placeholderPage()
            ->assertSeeHtml('x-data="placeholderFill"')
            ->assertSeeHtml('data-copy-label="Copy"')
            ->assertSeeHtml('{{discord_id}}');
    });

    test('other markdown blocks are not filled', function () {
        $this->system->update(['description' => 'Uses {{discord_id}}']);

        $html = placeholderPage()->html();

        expect(substr_count($html, 'x-data="placeholderFill"'))->toBe(1);
    });

    test('the page never holds a raw identifier on the server side', function () {
        $page = placeholderPage();

        expect(json_encode($page->instance()->all()))->not->toContain('placeholders');
    });
});
