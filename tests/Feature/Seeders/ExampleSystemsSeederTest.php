<?php

declare(strict_types=1);

use App\Models\System;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExampleSystemsSeeder;

test('the example systems are imported', function () {
    $this->seed(ExampleSystemsSeeder::class);

    $system = System::where('slug', 'modmail-bot')->sole();

    expect($system->name)->toBe('Modmail bot')
        ->and($system->steps()->count())->toBe(8);
});

test('running the seeder again does not duplicate anything', function () {
    $this->seed(ExampleSystemsSeeder::class);
    $this->seed(ExampleSystemsSeeder::class);

    expect(System::where('slug', 'modmail-bot')->count())->toBe(1)
        ->and(System::sole()->steps()->count())->toBe(8);
});

test('running the seeder again keeps local customizations', function () {
    $this->seed(ExampleSystemsSeeder::class);
    System::sole()->update(['response_period_days' => 14, 'name' => 'Our Modmail']);

    $this->seed(ExampleSystemsSeeder::class);

    $system = System::sole();

    expect($system->response_period_days)->toBe(14)
        ->and($system->name)->toBe('Our Modmail');
});

test('the default seeder does not import the example systems', function () {
    config(['privacy.admin_email' => 'admin@example.com']);

    $this->seed(DatabaseSeeder::class);

    expect(System::count())->toBe(0);
});
