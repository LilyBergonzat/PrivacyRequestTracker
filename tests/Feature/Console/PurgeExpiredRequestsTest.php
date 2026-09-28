<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\System;
use App\Services\PrivacyRequestPurger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    config(['privacy.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2028-01-20 03:00:00', 'UTC'));
    $this->purger = app(PrivacyRequestPurger::class);
});

/**
 * A closed request with everything that can hang off it: identifiers, a system row, a ticked step and log entries.
 *
 * @param  array<string, mixed>  $attributes
 */
function closedRequestWithHistory(string $purgeAfter, RequestStatus $status = RequestStatus::Completed, array $attributes = []): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create(['type' => RequestType::Deletion]);
    $request->forceFill([
        'status' => $status,
        'closed_at' => '2026-01-20 10:00:00',
        'purge_after' => $purgeAfter,
        ...$attributes,
    ])->save();

    RequestIdentifier::factory()->for($request)->count(2)->create();
    $row = RequestSystem::factory()->for($request)->create();
    StepCompletion::factory()->for($row)->create();
    Activity::factory()->for($request)->count(3)->create();

    return $request;
}

describe('purging expired requests', function () {
    test('an expired request is deleted with everything attached to it', function () {
        $request = closedRequestWithHistory('2028-01-19');

        expect($this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20')))->toBe(1)
            ->and(PrivacyRequest::find($request->id))->toBeNull()
            ->and(RequestIdentifier::count())->toBe(0)
            ->and(RequestSystem::count())->toBe(0)
            ->and(StepCompletion::count())->toBe(0)
            ->and(Activity::where('privacy_request_id', $request->id)->count())->toBe(0);
    });

    test('a request is purged on its purge date, not before', function (string $purgeAfter, bool $purged) {
        $request = closedRequestWithHistory($purgeAfter);

        $this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20'));

        expect(PrivacyRequest::find($request->id) === null)->toBe($purged);
    })->with([
        'the day before' => ['2028-01-19', true],
        'the same day' => ['2028-01-20', true],
        'the day after' => ['2028-01-21', false],
    ]);

    test('each purge leaves a single trace with only the reference and type', function () {
        $request = closedRequestWithHistory('2028-01-19', attributes: ['notes' => 'Secret notes']);
        $reference = $request->reference;

        $this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20'));

        $trace = Activity::sole();

        expect($trace->action)->toBe(ActivityAction::RequestPurged)
            ->and($trace->privacy_request_id)->toBeNull()
            ->and($trace->user_id)->toBeNull()
            ->and($trace->metadata)->toBe(['reference' => $reference, 'type' => 'deletion']);
    });

    test('rejected requests are purged too', function () {
        closedRequestWithHistory('2028-01-19', RequestStatus::Rejected);

        expect($this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20')))->toBe(1);
    });

    test('open requests are never purged, even with a purge date', function (RequestStatus $status) {
        $request = closedRequestWithHistory('2027-01-01', $status, ['closed_at' => null]);

        expect($this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20')))->toBe(0)
            ->and(PrivacyRequest::find($request->id))->not->toBeNull()
            ->and(RequestIdentifier::count())->toBe(2);
    })->with([
        RequestStatus::Received,
        RequestStatus::VerifyingIdentity,
        RequestStatus::InProgress,
        RequestStatus::AwaitingNotification,
    ]);

    test('closed requests without a purge date are kept', function () {
        $request = closedRequestWithHistory('2028-01-19');
        $request->forceFill(['purge_after' => null])->save();

        expect($this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20')))->toBe(0);
    });

    test('only the expired requests are purged, the others stay intact', function () {
        closedRequestWithHistory('2028-01-10');
        closedRequestWithHistory('2028-01-15');
        $kept = closedRequestWithHistory('2028-06-01');

        expect($this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20')))->toBe(2)
            ->and(PrivacyRequest::pluck('id')->all())->toBe([$kept->id])
            ->and(RequestIdentifier::count())->toBe(2)
            ->and(RequestSystem::count())->toBe(1)
            ->and(StepCompletion::count())->toBe(1)
            ->and(Activity::where('privacy_request_id', $kept->id)->count())->toBe(3)
            ->and(Activity::where('action', ActivityAction::RequestPurged)->count())->toBe(2);
    });

    test('systems used by purged requests are kept', function () {
        $system = System::factory()->create();
        $request = closedRequestWithHistory('2028-01-19');
        RequestSystem::factory()->for($request)->for($system)->create();

        $this->purger->purgeExpired(CarbonImmutable::parse('2028-01-20'));

        expect(System::find($system->id))->not->toBeNull();
    });

    test('the count matches what would be purged, without deleting anything', function () {
        closedRequestWithHistory('2028-01-10');
        closedRequestWithHistory('2028-06-01');

        expect($this->purger->countExpired(CarbonImmutable::parse('2028-01-20')))->toBe(1)
            ->and(PrivacyRequest::count())->toBe(2);
    });
});

describe('leftover contact details', function () {
    test('contact details left on a closed request are purged and logged', function () {
        $request = closedRequestWithHistory('2028-06-01', attributes: ['contact_details' => 'someone@example.com']);

        expect($this->purger->purgeLeftoverContactDetails())->toBe(1)
            ->and($request->refresh()->contact_details)->toBeNull()
            ->and(Activity::where('privacy_request_id', $request->id)->latest('id')->first()->action)
            ->toBe(ActivityAction::ContactPurged);
    });

    test('contact details of open requests are kept', function () {
        $request = PrivacyRequest::factory()->create(['contact_details' => 'someone@example.com']);

        expect($this->purger->purgeLeftoverContactDetails())->toBe(0)
            ->and($request->refresh()->contact_details)->toBe('someone@example.com');
    });

    test('nothing happens when no contact details are left', function () {
        closedRequestWithHistory('2028-06-01');

        expect($this->purger->purgeLeftoverContactDetails())->toBe(0)
            ->and($this->purger->countLeftoverContactDetails())->toBe(0);
    });
});

describe('the command', function () {
    test('the command purges and reports what it did', function () {
        closedRequestWithHistory('2028-01-10');
        closedRequestWithHistory('2028-01-15');
        closedRequestWithHistory('2028-06-01', attributes: ['contact_details' => 'someone@example.com']);

        $this->artisan('privacy:purge')
            ->expectsOutputToContain('Purged 2 requests.')
            ->expectsOutputToContain('Cleared leftover contact details on 1 request.')
            ->assertSuccessful();

        expect(PrivacyRequest::count())->toBe(1);
    });

    test('a dry run only reports, and deletes nothing', function () {
        closedRequestWithHistory('2028-01-10');
        $withContact = closedRequestWithHistory('2028-06-01', attributes: ['contact_details' => 'someone@example.com']);

        $this->artisan('privacy:purge --dry-run')
            ->expectsOutputToContain('No entries were deleted.')
            ->expectsOutputToContain('Would have purged 1 request.')
            ->expectsOutputToContain('Would have cleared leftover contact details on 1 request.')
            ->assertSuccessful();

        expect(PrivacyRequest::count())->toBe(2)
            ->and($withContact->refresh()->contact_details)->toBe('someone@example.com')
            ->and(Activity::where('action', ActivityAction::RequestPurged)->count())->toBe(0);
    });

    test('the purge day is the organization day', function () {
        config(['privacy.timezone' => 'Pacific/Auckland']);
        $this->travelTo(CarbonImmutable::parse('2028-01-19 14:00:00', 'UTC'));
        closedRequestWithHistory('2028-01-20');

        $this->artisan('privacy:purge')->expectsOutputToContain('Purged 1 request.');

        expect(PrivacyRequest::count())->toBe(0);
    });

    test('the purge is scheduled every night in the organization time zone', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'privacy:purge'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 3 * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});
