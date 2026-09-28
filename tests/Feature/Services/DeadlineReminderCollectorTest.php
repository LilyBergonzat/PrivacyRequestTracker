<?php

declare(strict_types=1);

use App\Enums\DeadlineStatus;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Services\DeadlineReminderCollector;
use App\ValueObjects\DeadlineReminder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['privacy.timezone' => 'UTC', 'privacy.deadline_warning_days' => 7]);
    $this->now = CarbonImmutable::parse('2026-02-10 10:00:00', 'UTC');
    $this->collector = app(DeadlineReminderCollector::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reminderRequest(string $dueAt, RequestStatus $status = RequestStatus::InProgress, array $attributes = []): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create(['due_at' => $dueAt, 'type' => RequestType::Deletion]);
    $request->forceFill(['status' => $status, ...$attributes])->save();

    return $request;
}

test('nothing is collected when every deadline is far away', function () {
    reminderRequest('2026-03-10 23:59:59');

    expect($this->collector->collect($this->now))->toBe([]);
});

test('overdue and soon requests are collected, on track ones are not', function () {
    $overdue = reminderRequest('2026-02-05 23:59:59');
    $soon = reminderRequest('2026-02-14 23:59:59');
    $onTrack = reminderRequest('2026-03-10 23:59:59');

    $references = array_map(fn (DeadlineReminder $reminder): string => $reminder->reference, $this->collector->collect($this->now));

    expect($references)->toContain($overdue->reference, $soon->reference)
        ->not->toContain($onTrack->reference);
});

test('overdue requests come first, then by deadline', function () {
    $soonLater = reminderRequest('2026-02-16 23:59:59');
    $overdueRecent = reminderRequest('2026-02-08 23:59:59');
    $soonFirst = reminderRequest('2026-02-11 23:59:59');
    $overdueOldest = reminderRequest('2026-01-20 23:59:59');

    $references = array_map(fn (DeadlineReminder $reminder): string => $reminder->reference, $this->collector->collect($this->now));

    expect($references)->toBe([
        $overdueOldest->reference,
        $overdueRecent->reference,
        $soonFirst->reference,
        $soonLater->reference,
    ]);
});

test('a reminder carries only the reference, type, deadline, status and link', function () {
    $request = reminderRequest('2026-02-05 23:59:59', attributes: [
        'notes' => 'Secret notes',
        'contact_details' => 'someone@example.com',
    ]);

    [$reminder] = $this->collector->collect($this->now);

    expect($reminder->reference)->toBe($request->reference)
        ->and($reminder->type)->toBe(RequestType::Deletion)
        ->and($reminder->deadline->toDateTimeString())->toBe('2026-02-05 23:59:59')
        ->and($reminder->status)->toBe(DeadlineStatus::Overdue)
        ->and($reminder->url)->toBe(route('requests.show', $request))
        ->and(array_keys(get_object_vars($reminder)))->toBe(['reference', 'type', 'deadline', 'status', 'url'])
        ->and(serialize($reminder))->not->toContain('Secret notes')
        ->not->toContain('someone@example.com');
});

test('the effective deadline is used, not just the legal one', function () {
    $systemFirst = reminderRequest('2026-03-10 23:59:59');
    RequestSystem::factory()->for($systemFirst)->create(['due_at' => '2026-02-12 23:59:59']);

    $extended = reminderRequest('2026-02-05 23:59:59', attributes: ['extended_until' => '2026-04-01 23:59:59']);

    $reminders = $this->collector->collect($this->now);

    expect($reminders)->toHaveCount(1)
        ->and($reminders[0]->reference)->toBe($systemFirst->reference)
        ->and($reminders[0]->deadline->toDateString())->toBe('2026-02-12')
        ->and($reminders[0]->status)->toBe(DeadlineStatus::Soon);
});

test('a resolved system no longer brings the deadline forward', function () {
    $request = reminderRequest('2026-03-10 23:59:59');
    RequestSystem::factory()->for($request)->resolved(RequestSystemStatus::Done)->create(['due_at' => '2026-02-01 23:59:59']);

    expect($this->collector->collect($this->now))->toBe([]);
});

test('closed and rejected requests are never reminded', function (RequestStatus $status) {
    reminderRequest('2026-01-20 23:59:59', $status, ['closed_at' => '2026-01-19 10:00:00']);

    expect($this->collector->collect($this->now))->toBe([]);
})->with([RequestStatus::Completed, RequestStatus::Rejected]);

test('every open status is reminded', function (RequestStatus $status) {
    reminderRequest('2026-02-05 23:59:59', $status);

    expect($this->collector->collect($this->now))->toHaveCount(1);
})->with([
    RequestStatus::Received,
    RequestStatus::VerifyingIdentity,
    RequestStatus::InProgress,
    RequestStatus::AwaitingNotification,
]);

test('the given date is used, not the current time', function () {
    reminderRequest('2026-02-14 23:59:59');

    expect($this->collector->collect(CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC')))->toBe([])
        ->and($this->collector->collect($this->now)[0]->status)->toBe(DeadlineStatus::Soon)
        ->and($this->collector->collect(CarbonImmutable::parse('2026-02-20 10:00:00', 'UTC'))[0]->status)->toBe(DeadlineStatus::Overdue);
});

test('only overdue and soon statuses are concerning', function (DeadlineStatus $status, bool $concerning) {
    expect($status->isConcerning())->toBe($concerning);
})->with([
    [DeadlineStatus::Overdue, true],
    [DeadlineStatus::Soon, true],
    [DeadlineStatus::OnTrack, false],
]);
