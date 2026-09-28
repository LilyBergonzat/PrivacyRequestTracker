<?php

declare(strict_types=1);

use App\Models\System;

describe('slugs', function () {
    test('the slug is generated from the name', function () {
        $system = System::factory()->create(['name' => 'Modmail Bot']);

        expect($system->slug)->toBe('modmail-bot');
    });

    test('systems with the same name get distinct slugs', function () {
        $first = System::factory()->create(['name' => 'Modmail Bot']);
        $second = System::factory()->create(['name' => 'Modmail Bot']);
        $third = System::factory()->create(['name' => 'modmail bot']);

        expect($first->slug)->toBe('modmail-bot')
            ->and($second->slug)->toBe('modmail-bot-2')
            ->and($third->slug)->toBe('modmail-bot-3');
    });

    test('a name without usable characters falls back to a generic slug', function () {
        $first = System::factory()->create(['name' => '!!!']);
        $second = System::factory()->create(['name' => '???']);

        expect($first->slug)->toBe('system')
            ->and($second->slug)->toBe('system-2');
    });

    test('an explicit slug is kept', function () {
        $system = System::factory()->create(['name' => 'Modmail Bot', 'slug' => 'modmail']);

        expect($system->slug)->toBe('modmail');
    });

    test('an explicit slug that is already taken gets a suffix', function () {
        System::factory()->create(['slug' => 'modmail']);

        $system = System::factory()->create(['slug' => 'modmail']);

        expect($system->slug)->toBe('modmail-2');
    });

    test('renaming a system does not change its slug', function () {
        $system = System::factory()->create(['name' => 'Modmail Bot']);

        $system->name = 'Support Bot';
        $system->save();

        expect($system->refresh()->slug)->toBe('modmail-bot');
    });

    test('the slug cannot be mass assigned', function () {
        $system = System::factory()->create(['name' => 'Modmail Bot']);

        $system->fill(['slug' => 'hijacked'])->save();

        expect($system->refresh()->slug)->toBe('modmail-bot');
    });
});

describe('scopes', function () {
    test('the active scope excludes inactive systems', function () {
        $active = System::factory()->create();
        System::factory()->inactive()->create();

        expect(System::query()->active()->pluck('id')->all())->toBe([$active->id]);
    });

    test('the ordered scope sorts by sort order then by name', function () {
        System::factory()->create(['name' => 'Zeta', 'sort_order' => 1]);
        System::factory()->create(['name' => 'Beta', 'sort_order' => 2]);
        System::factory()->create(['name' => 'Alpha', 'sort_order' => 2]);
        System::factory()->create(['name' => 'Omega', 'sort_order' => 0]);

        expect(System::query()->ordered()->pluck('name')->all())
            ->toBe(['Omega', 'Zeta', 'Alpha', 'Beta']);
    });

    test('scopes can be combined', function () {
        System::factory()->create(['name' => 'Beta', 'sort_order' => 1]);
        System::factory()->inactive()->create(['name' => 'Alpha', 'sort_order' => 0]);
        System::factory()->create(['name' => 'Gamma', 'sort_order' => 0]);

        expect(System::query()->active()->ordered()->pluck('name')->all())
            ->toBe(['Gamma', 'Beta']);
    });
});

describe('defaults and casts', function () {
    test('a new system is active with no custom response period', function () {
        $system = System::factory()->create();

        expect($system->refresh()->is_active)->toBeTrue()
            ->and($system->response_period_days)->toBeNull()
            ->and($system->sort_order)->toBe(0);
    });

    test('the response period is stored as an integer', function () {
        $system = System::factory()->withResponsePeriod(14)->create();

        expect($system->refresh()->response_period_days)->toBe(14);
    });
});
