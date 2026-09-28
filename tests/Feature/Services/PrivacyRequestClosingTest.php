<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\NotificationOutcome;
use App\Enums\RequestStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\PrivacyRequestWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->workflow = app(PrivacyRequestWorkflow::class);
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));
});

/**
 * A request received on January 15, 2026, legally due on February 15, with the given status.
 *
 * @param  array<string, mixed>  $attributes
 */
function closingRequest(RequestStatus $status, array $attributes = []): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create([
        'received_at' => '2026-01-15 10:00:00',
        'due_at' => '2026-02-15 23:59:59',
        'contact_details' => 'DM requester.person on Discord',
    ]);

    $request->forceFill(['status' => $status, ...$attributes])->save();

    return $request->refresh();
}

describe('extending', function () {
    test('a request can be extended with a reason', function () {
        $request = closingRequest(RequestStatus::InProgress);

        $this->workflow->extend($request, CarbonImmutable::parse('2026-03-31 23:59:59', 'UTC'), 'Many systems to check', null);

        $request->refresh();

        expect($request->extended_until->toDateTimeString())->toBe('2026-03-31 23:59:59')
            ->and($request->extension_reason)->toBe('Many systems to check')
            ->and($request->extension_notified_at)->toBeNull()
            ->and($request->effectiveDeadline()->toDateTimeString())->toBe('2026-03-31 23:59:59')
            ->and(Activity::sole()->action)->toBe(ActivityAction::RequestExtended);
    });

    test('the date the requester was told can be recorded, now or later', function () {
        $request = closingRequest(RequestStatus::InProgress);
        $until = CarbonImmutable::parse('2026-03-31 23:59:59', 'UTC');

        $this->workflow->extend($request, $until, 'Many systems', null);
        $this->workflow->extend($request, $until, 'Many systems', CarbonImmutable::parse('2026-01-21 09:00:00', 'UTC'));

        expect($request->refresh()->extension_notified_at->toDateTimeString())->toBe('2026-01-21 09:00:00');
    });

    test('an extension must go past the legal deadline and stay within three months', function (string $until) {
        $request = closingRequest(RequestStatus::InProgress);

        expect(fn () => $this->workflow->extend($request, CarbonImmutable::parse($until, 'UTC'), 'Reason', null))
            ->toThrow(DomainException::class)
            ->and($request->refresh()->extended_until)->toBeNull()
            ->and(Activity::count())->toBe(0);
    })->with([
        'before the legal deadline' => ['2026-02-01 23:59:59'],
        'exactly the legal deadline' => ['2026-02-15 23:59:59'],
        'past three months' => ['2026-04-16 23:59:59'],
    ]);

    test('the very last day allowed can be chosen', function () {
        config(['privacy.timezone' => 'UTC']);
        $request = closingRequest(RequestStatus::InProgress);

        $this->workflow->extend($request, CarbonImmutable::parse('2026-04-15 23:59:59', 'UTC'), 'Reason', null);

        expect($request->refresh()->extended_until->toDateString())->toBe('2026-04-15');
    });

    test('a closed request cannot be extended', function (RequestStatus $status) {
        $request = closingRequest($status);

        expect(fn () => $this->workflow->extend($request, CarbonImmutable::parse('2026-03-31 23:59:59', 'UTC'), 'Reason', null))
            ->toThrow(DomainException::class);
    })->with([RequestStatus::Completed, RequestStatus::Rejected]);
});

describe('notifying the requester', function () {
    test('the outcome, the notes and the date are recorded', function () {
        $request = closingRequest(RequestStatus::AwaitingNotification);

        $this->workflow->recordNotification($request, NotificationOutcome::Unreachable, 'Left the server, DMs closed');

        $request->refresh();

        expect($request->notification_outcome)->toBe(NotificationOutcome::Unreachable)
            ->and($request->notification_notes)->toBe('Left the server, DMs closed')
            ->and($request->notified_at->toDateTimeString())->toBe('2026-01-20 10:00:00');
    });

    test('the notification is logged with the outcome but never the notes', function () {
        $request = closingRequest(RequestStatus::AwaitingNotification);

        $this->workflow->recordNotification($request, NotificationOutcome::Sent, 'Sent through Modmail');

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::RequesterNotified)
            ->and($activity->metadata)->toBe(['outcome' => 'sent']);
    });

    test('the notification can be recorded before rejecting, at any open status', function (RequestStatus $status) {
        $request = closingRequest($status);

        $this->workflow->recordNotification($request, NotificationOutcome::Sent, null);

        expect($request->refresh()->notification_outcome)->toBe(NotificationOutcome::Sent);
    })->with([
        RequestStatus::Received,
        RequestStatus::VerifyingIdentity,
        RequestStatus::InProgress,
    ]);

    test('the notification of a closed request cannot be changed', function () {
        $request = closingRequest(RequestStatus::Completed, ['notification_outcome' => NotificationOutcome::Sent]);

        expect(fn () => $this->workflow->recordNotification($request, NotificationOutcome::Unreachable, null))
            ->toThrow(DomainException::class)
            ->and($request->refresh()->notification_outcome)->toBe(NotificationOutcome::Sent);
    });

    test('every outcome has a label', function (NotificationOutcome $outcome) {
        expect($outcome->label())->not->toBeEmpty();
    })->with(NotificationOutcome::cases());
});

describe('closing', function () {
    test('closing completes the request, sets the purge date and purges the contact details', function () {
        config(['privacy.retention_months' => 24]);
        $request = closingRequest(RequestStatus::AwaitingNotification, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->close($request);

        $request->refresh();

        expect($request->status)->toBe(RequestStatus::Completed)
            ->and($request->closed_at->toDateTimeString())->toBe('2026-01-20 10:00:00')
            ->and($request->purge_after->toDateString())->toBe('2028-01-20')
            ->and($request->contact_details)->toBeNull()
            ->and(DB::table('privacy_requests')->where('id', $request->id)->value('contact_details'))->toBeNull();
    });

    test('closing is logged, along with the contact purge', function () {
        $request = closingRequest(RequestStatus::AwaitingNotification, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->close($request);

        expect(Activity::orderBy('id')->pluck('action')->all())
            ->toBe([ActivityAction::RequestClosed, ActivityAction::ContactPurged]);
    });

    test('no contact purge is logged when there was nothing to purge', function () {
        $request = closingRequest(RequestStatus::AwaitingNotification, [
            'notification_outcome' => NotificationOutcome::NotRequired,
            'contact_details' => null,
        ]);

        $this->workflow->close($request);

        expect(Activity::pluck('action')->all())->toBe([ActivityAction::RequestClosed]);
    });

    test('the retention period in force when closing is used', function () {
        config(['privacy.retention_months' => 6]);
        $request = closingRequest(RequestStatus::AwaitingNotification, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->close($request);
        config(['privacy.retention_months' => 36]);

        expect($request->refresh()->purge_after->toDateString())->toBe('2026-07-20');
    });

    test('a request cannot be closed before every system is resolved', function (RequestStatus $status) {
        $request = closingRequest($status, ['notification_outcome' => NotificationOutcome::Sent]);

        expect(fn () => $this->workflow->close($request))->toThrow(DomainException::class)
            ->and($request->refresh()->status)->toBe($status)
            ->and($request->contact_details)->not->toBeNull();
    })->with([
        RequestStatus::Received,
        RequestStatus::VerifyingIdentity,
        RequestStatus::InProgress,
        RequestStatus::Completed,
        RequestStatus::Rejected,
    ]);

    test('a request cannot be closed before the requester notification is recorded', function () {
        $request = closingRequest(RequestStatus::AwaitingNotification);

        expect(fn () => $this->workflow->close($request))->toThrow(DomainException::class)
            ->and($request->refresh()->status)->toBe(RequestStatus::AwaitingNotification);
    });
});

describe('rejecting', function () {
    test('rejecting closes the request with its reason and purges the contact details', function () {
        config(['privacy.retention_months' => 24]);
        $request = closingRequest(RequestStatus::VerifyingIdentity, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->reject($request, 'Identity could not be verified');

        $request->refresh();

        expect($request->status)->toBe(RequestStatus::Rejected)
            ->and($request->rejection_reason)->toBe('Identity could not be verified')
            ->and($request->closed_at)->not->toBeNull()
            ->and($request->purge_after->toDateString())->toBe('2028-01-20')
            ->and($request->contact_details)->toBeNull()
            ->and(Activity::orderBy('id')->pluck('action')->all())
            ->toBe([ActivityAction::RequestRejected, ActivityAction::ContactPurged]);
    });

    test('a request can be rejected at any open status', function (RequestStatus $status) {
        $request = closingRequest($status, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->reject($request, 'Abusive request');

        expect($request->refresh()->status)->toBe(RequestStatus::Rejected);
    })->with([
        RequestStatus::Received,
        RequestStatus::VerifyingIdentity,
        RequestStatus::InProgress,
        RequestStatus::AwaitingNotification,
    ]);

    test('a request cannot be rejected before the requester is told why', function () {
        $request = closingRequest(RequestStatus::InProgress);

        expect(fn () => $this->workflow->reject($request, 'Reason'))->toThrow(DomainException::class)
            ->and($request->refresh()->status)->toBe(RequestStatus::InProgress);
    });

    test('a closed request cannot be rejected afterwards', function (RequestStatus $status) {
        $request = closingRequest($status, [
            'notification_outcome' => NotificationOutcome::Sent,
            'closed_at' => '2026-01-18 10:00:00',
            'purge_after' => '2028-01-18',
        ]);

        expect(fn () => $this->workflow->reject($request, 'Changed my mind'))->toThrow(DomainException::class);

        $request->refresh();

        expect($request->status)->toBe($status)
            ->and($request->purge_after->toDateString())->toBe('2028-01-18')
            ->and($request->rejection_reason)->toBeNull();
    })->with([RequestStatus::Completed, RequestStatus::Rejected]);

    test('the rejection reason is encrypted in the database', function () {
        $request = closingRequest(RequestStatus::InProgress, ['notification_outcome' => NotificationOutcome::Sent]);

        $this->workflow->reject($request, 'Identity could not be verified');

        expect(DB::table('privacy_requests')->where('id', $request->id)->value('rejection_reason'))
            ->not->toContain('Identity');
    });
});
