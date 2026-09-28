<?php

declare(strict_types=1);

use App\Enums\RequestType;
use App\Models\System;
use App\Models\SystemStep;

describe('relationships', function () {
    test('a step belongs to its system', function () {
        $system = System::factory()->create();
        $step = SystemStep::factory()->for($system)->create();

        expect($step->system->is($system))->toBeTrue();
    });

    test('a system has many steps', function () {
        $system = System::factory()->create();
        SystemStep::factory()->for($system)->count(3)->create();
        SystemStep::factory()->create();

        expect($system->steps)->toHaveCount(3)
            ->each(fn ($step) => $step->system_id->toBe($system->id));
    });

    test('steps are deleted along with their system', function () {
        $system = System::factory()->create();
        SystemStep::factory()->for($system)->count(2)->create();

        $system->delete();

        expect(SystemStep::count())->toBe(0);
    });
});

describe('defaults and casts', function () {
    test('a new step is active in memory without a refresh', function () {
        $system = System::factory()->create();

        $step = $system->steps()->create(['title' => 'Connect', 'instructions' => 'mongosh']);

        expect($step->is_active)->toBeTrue();
    });

    test('a new system is active in memory without a refresh', function () {
        $system = System::create(['name' => 'Modmail Bot']);

        expect($system->is_active)->toBeTrue();
    });

    test('the request type is cast to the enum', function () {
        $step = SystemStep::factory()->forType(RequestType::Deletion)->create();

        expect($step->refresh()->request_type)->toBe(RequestType::Deletion);
    });

    test('a step without request type applies to all types', function () {
        $step = SystemStep::factory()->create();

        expect($step->refresh()->request_type)->toBeNull();
    });

    test('the system and sort order cannot be mass assigned', function () {
        $step = SystemStep::factory()->create();
        $otherSystem = System::factory()->create();
        $originalSystemId = $step->system_id;
        $originalSortOrder = $step->sort_order;

        $step->fill(['system_id' => $otherSystem->id, 'sort_order' => 42])->save();
        $step->refresh();

        expect($step->system_id)->toBe($originalSystemId)
            ->and($step->sort_order)->toBe($originalSortOrder);
    });
});

describe('sort order', function () {
    test('new steps are appended at the end of their system', function () {
        $system = System::factory()->create();

        $first = SystemStep::factory()->for($system)->create();
        $second = SystemStep::factory()->for($system)->create();
        $third = SystemStep::factory()->for($system)->create();

        expect([$first->sort_order, $second->sort_order, $third->sort_order])->toBe([0, 1, 2]);
    });

    test('each system has its own numbering', function () {
        $modmail = System::factory()->create();
        $discord = System::factory()->create();

        SystemStep::factory()->for($modmail)->count(3)->create();
        $firstDiscordStep = SystemStep::factory()->for($discord)->create();

        expect($firstDiscordStep->sort_order)->toBe(0);
    });

    test('new steps go after the highest existing number, even with gaps', function () {
        $system = System::factory()->create();
        SystemStep::factory()->for($system)->create(['sort_order' => 7]);

        $step = SystemStep::factory()->for($system)->create();

        expect($step->sort_order)->toBe(8);
    });

    test('an explicit sort order is kept, including zero', function () {
        $system = System::factory()->create();
        SystemStep::factory()->for($system)->count(2)->create();

        $step = SystemStep::factory()->for($system)->create(['sort_order' => 0]);

        expect($step->refresh()->sort_order)->toBe(0);
    });

    test('steps created through the relationship are appended too', function () {
        $system = System::factory()->create();
        $system->steps()->create(['title' => 'One', 'instructions' => '…']);

        $second = $system->steps()->create(['title' => 'Two', 'instructions' => '…']);

        expect($second->sort_order)->toBe(1);
    });
});

describe('scopes', function () {
    test('ordered sorts by sort order then by id', function () {
        $system = System::factory()->create();
        $third = SystemStep::factory()->for($system)->create(['sort_order' => 5, 'title' => 'Third']);
        $first = SystemStep::factory()->for($system)->create(['sort_order' => 1, 'title' => 'First']);
        $second = SystemStep::factory()->for($system)->create(['sort_order' => 5, 'title' => 'Second']);

        // Same sort order: the older step (lower id) comes first.
        expect($second->id)->toBeGreaterThan($third->id)
            ->and($system->steps()->ordered()->pluck('title')->all())->toBe(['First', 'Third', 'Second']);
    });

    test('active excludes inactive steps', function () {
        $system = System::factory()->create();
        $active = SystemStep::factory()->for($system)->create();
        SystemStep::factory()->for($system)->inactive()->create();

        expect($system->steps()->active()->pluck('id')->all())->toBe([$active->id]);
    });

    test('for request type keeps matching steps and steps for all types', function () {
        $system = System::factory()->create();
        $forAll = SystemStep::factory()->for($system)->create(['title' => 'All']);
        $forDeletion = SystemStep::factory()->for($system)->forType(RequestType::Deletion)->create(['title' => 'Deletion']);
        SystemStep::factory()->for($system)->forType(RequestType::Access)->create(['title' => 'Access']);

        expect($system->steps()->forRequestType(RequestType::Deletion)->ordered()->pluck('id')->all())
            ->toBe([$forAll->id, $forDeletion->id]);
    });

    test('for request type does not leak out of other conditions', function () {
        $system = System::factory()->create();
        $otherSystem = System::factory()->create();

        $wanted = SystemStep::factory()->for($system)->create();
        SystemStep::factory()->for($system)->inactive()->create();
        SystemStep::factory()->for($system)->inactive()->forType(RequestType::Deletion)->create();
        SystemStep::factory()->for($otherSystem)->create();
        SystemStep::factory()->for($otherSystem)->forType(RequestType::Deletion)->create();

        $ids = $system->steps()
            ->active()
            ->forRequestType(RequestType::Deletion)
            ->pluck('id')
            ->all();

        expect($ids)->toBe([$wanted->id]);
    });
});

describe('request type labels', function () {
    test('every request type has a label', function (RequestType $type, string $label) {
        expect($type->label())->toBe($label);
    })->with([
        [RequestType::Access, 'Access'],
        [RequestType::Deletion, 'Deletion'],
        [RequestType::Correction, 'Correction'],
        [RequestType::Objection, 'Objection'],
        [RequestType::Other, 'Other'],
    ]);
});
