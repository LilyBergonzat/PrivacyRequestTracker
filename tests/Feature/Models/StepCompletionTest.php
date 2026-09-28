<?php

declare(strict_types=1);

use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\SystemStep;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a completion links a system row, a step and the staff member', function () {
    $completion = StepCompletion::factory()->create();

    expect($completion->requestSystem)->toBeInstanceOf(RequestSystem::class)
        ->and($completion->systemStep)->toBeInstanceOf(SystemStep::class)
        ->and($completion->completedBy)->toBeInstanceOf(User::class)
        ->and($completion->requestSystem->stepCompletions->first()->is($completion))->toBeTrue();
});

test('the same step cannot be completed twice on the same system row', function () {
    $completion = StepCompletion::factory()->create();

    StepCompletion::factory()
        ->for($completion->requestSystem)
        ->for($completion->systemStep)
        ->create();
})->throws(UniqueConstraintViolationException::class);

test('deleting a step keeps the completion and its title snapshot', function () {
    $step = SystemStep::factory()->create(['title' => 'Delete persistent staff notes']);
    $completion = StepCompletion::factory()->for($step)->create(['step_title' => $step->title]);

    $step->delete();
    $completion->refresh();

    expect($completion->system_step_id)->toBeNull()
        ->and($completion->systemStep)->toBeNull()
        ->and($completion->step_title)->toBe('Delete persistent staff notes');
});

test('renaming a step does not rewrite the history', function () {
    $step = SystemStep::factory()->create(['title' => 'Old title']);
    $completion = StepCompletion::factory()->for($step)->create(['step_title' => $step->title]);

    $step->update(['title' => 'New title']);

    expect($completion->refresh()->step_title)->toBe('Old title');
});

test('a user who completed steps cannot be deleted', function () {
    $completion = StepCompletion::factory()->create();

    expect(fn () => $completion->completedBy->delete())->toThrow(QueryException::class);
});

test('the completion date is cast', function () {
    $completion = StepCompletion::factory()->create(['completed_at' => '2026-09-25 10:00:00']);

    expect($completion->refresh()->completed_at->toDateTimeString())->toBe('2026-09-25 10:00:00');
});
