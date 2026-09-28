<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('it prints a new 256-bit hex key ready to paste', function () {
    config(['privacy.subject_hmac_keys' => []]);

    $exitCode = Artisan::call('privacy:key:generate');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toMatch('/New key: v1:[0-9a-f]{64}\b/')
        ->toContain('New current: v1');
});

test('it suggests the version after the highest existing one', function () {
    config(['privacy.subject_hmac_keys' => ['v1' => 'a', 'v3' => 'b', 'v2' => 'c']]);

    Artisan::call('privacy:key:generate');

    expect(Artisan::output())->toMatch('/New key: v4:[0-9a-f]{64}\b/')
        ->toContain('New current: v4');
});

test('it generates a different key every time', function () {
    Artisan::call('privacy:key:generate');
    $first = Artisan::output();

    Artisan::call('privacy:key:generate');
    $second = Artisan::output();

    expect($first)->not->toBe($second);
});

test('it does not write the key anywhere', function () {
    $envBefore = file_exists(base_path('.env')) ? file_get_contents(base_path('.env')) : null;
    $keysBefore = config('privacy.subject_hmac_keys');

    Artisan::call('privacy:key:generate');

    expect(file_exists(base_path('.env')) ? file_get_contents(base_path('.env')) : null)->toBe($envBefore)
        ->and(config('privacy.subject_hmac_keys'))->toBe($keysBefore);
});
