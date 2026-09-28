<?php

declare(strict_types=1);

use App\Enums\RequestSystemStatus;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a request has many system rows, each pointing to its system', function () {
    $request = PrivacyRequest::factory()->create();
    $system = System::factory()->create();
    RequestSystem::factory()->for($request)->for($system)->create();
    RequestSystem::factory()->for($request)->create();

    expect($request->requestSystems)->toHaveCount(2)
        ->and($request->requestSystems->first()->system->is($system))->toBeTrue()
        ->and($request->requestSystems->first()->privacyRequest->is($request))->toBeTrue();
});

test('a new row is pending, in memory and in the database', function () {
    $row = RequestSystem::factory()->create();

    expect($row->status)->toBe(RequestSystemStatus::Pending)
        ->and($row->refresh()->status)->toBe(RequestSystemStatus::Pending)
        ->and($row->completed_by)->toBeNull()
        ->and($row->completed_at)->toBeNull();
});

test('only pending rows are unresolved', function (RequestSystemStatus $status, bool $resolved) {
    expect($status->isResolved())->toBe($resolved);
})->with([
    [RequestSystemStatus::Pending, false],
    [RequestSystemStatus::Done, true],
    [RequestSystemStatus::NoDataFound, true],
    [RequestSystemStatus::NotApplicable, true],
]);

test('every status has a label', function (RequestSystemStatus $status) {
    expect($status->label())->toBeString()->not->toBeEmpty();
})->with(RequestSystemStatus::cases());

test('a resolved row records who resolved it and when', function () {
    $row = RequestSystem::factory()->resolved(RequestSystemStatus::NoDataFound)->create();

    expect($row->refresh()->status)->toBe(RequestSystemStatus::NoDataFound)
        ->and($row->completedBy)->toBeInstanceOf(User::class)
        ->and($row->completed_at)->not->toBeNull();
});

test('the result summary is encrypted in the database', function () {
    $row = RequestSystem::factory()->create();
    $row->forceFill(['result_summary' => 'Deleted 4 thread logs and 2 notes'])->save();

    $raw = DB::table('request_systems')->where('id', $row->id)->value('result_summary');

    expect($raw)->not->toContain('Deleted')
        ->and($row->refresh()->result_summary)->toBe('Deleted 4 thread logs and 2 notes');
});

test('nothing can be mass assigned', function () {
    $row = RequestSystem::factory()->create();

    expect(fn () => $row->fill(['status' => RequestSystemStatus::Done, 'result_summary' => 'Sneaky']))
        ->toThrow(MassAssignmentException::class);

    expect($row->refresh()->status)->toBe(RequestSystemStatus::Pending);
});

test('a system appears only once per request', function () {
    $row = RequestSystem::factory()->create();

    RequestSystem::factory()->for($row->privacyRequest)->for($row->system)->create();
})->throws(UniqueConstraintViolationException::class);

test('rows and their step completions are deleted along with their request', function () {
    $row = RequestSystem::factory()->create();
    StepCompletion::factory()->for($row)->create();

    $row->privacyRequest->delete();

    expect(RequestSystem::count())->toBe(0)
        ->and(StepCompletion::count())->toBe(0);
});

test('a system used by a request cannot be deleted', function () {
    $row = RequestSystem::factory()->create();

    expect(fn () => $row->system->delete())->toThrow(QueryException::class)
        ->and(System::find($row->system_id))->not->toBeNull();
});
