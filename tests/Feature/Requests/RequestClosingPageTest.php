<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\NotificationOutcome;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    config(['privacy.timezone' => 'UTC', 'privacy.retention_months' => 24]);
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

    $this->handler = User::factory()->create(['name' => 'Lily Handler']);

    $this->request = PrivacyRequest::factory()->create([
        'received_at' => '2026-01-15 10:00:00',
        'due_at' => '2026-02-15 23:59:59',
        'contact_details' => 'DM requester.person on Discord',
    ]);
    $this->request->forceFill(['status' => RequestStatus::InProgress])->save();

    $this->row = RequestSystem::factory()->for($this->request)->create();
});

function closingPage(): Testable
{
    return Livewire::actingAs(test()->handler)
        ->test('pages::requests.show', ['privacyRequest' => test()->request->fresh()]);
}

function resolveEverySystem(): void
{
    test()->row->forceFill([
        'status' => RequestSystemStatus::Done,
        'result_summary' => 'Done',
        'completed_by' => test()->handler->id,
        'completed_at' => now(),
    ])->save();

    test()->request->forceFill(['status' => RequestStatus::AwaitingNotification])->save();
}

describe('extension', function () {
    test('the form only allows dates between the day after the legal deadline and three months', function () {
        closingPage()
            ->assertSeeHtml('min="2026-02-16"')
            ->assertSeeHtml('max="2026-04-15"')
            ->assertSee('At the latest April 15, 2026.');
    });

    test('the deadline can be extended from the page', function () {
        closingPage()
            ->set('extendedUntil', '2026-03-31')
            ->set('extensionReason', 'Many systems to check')
            ->set('extensionNotifiedOn', '2026-01-20')
            ->call('extend')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show')
            ->assertSee('Extended until')
            ->assertSee('Requester told');

        $this->request->refresh();

        expect($this->request->extended_until->toDateTimeString())->toBe('2026-03-31 23:59:59')
            ->and($this->request->extension_reason)->toBe('Many systems to check')
            ->and($this->request->extension_notified_at->toDateString())->toBe('2026-01-20');
    });

    test('an extension without telling the requester is flagged', function () {
        closingPage()
            ->set('extendedUntil', '2026-03-31')
            ->set('extensionReason', 'Many systems to check')
            ->call('extend')
            ->assertHasNoErrors()
            ->assertSee('Requester not told yet');

        expect($this->request->refresh()->extension_notified_at)->toBeNull();
    });

    test('the dates are converted from the organization time zone', function () {
        config(['privacy.timezone' => 'Europe/Paris']);

        closingPage()
            ->set('extendedUntil', '2026-03-31')
            ->set('extensionReason', 'Reason')
            ->call('extend')
            ->assertHasNoErrors();

        expect($this->request->refresh()->extended_until->toDateTimeString())->toBe('2026-03-31 21:59:59');
    });

    test('the extension form is filled with the current extension', function () {
        $this->request->forceFill([
            'extended_until' => '2026-03-31 23:59:59',
            'extension_reason' => 'Many systems',
            'extension_notified_at' => '2026-01-18 00:00:00',
        ])->save();

        closingPage()
            ->assertSet('extendedUntil', '2026-03-31')
            ->assertSet('extensionReason', 'Many systems')
            ->assertSet('extensionNotifiedOn', '2026-01-18');
    });

    test('invalid extensions are rejected', function (array $input, string $field) {
        closingPage()
            ->set(['extendedUntil' => '2026-03-31', 'extensionReason' => 'Reason', 'extensionNotifiedOn' => '', ...$input])
            ->call('extend')
            ->assertHasErrors($field);

        expect($this->request->refresh()->extended_until)->toBeNull();
    })->with([
        'missing date' => [['extendedUntil' => ''], 'extendedUntil'],
        'on the legal deadline' => [['extendedUntil' => '2026-02-15'], 'extendedUntil'],
        'past three months' => [['extendedUntil' => '2026-04-16'], 'extendedUntil'],
        'missing reason' => [['extensionReason' => ''], 'extensionReason'],
        'told in the future' => [['extensionNotifiedOn' => '2026-02-01'], 'extensionNotifiedOn'],
    ]);

    test('a late notice of the extension is warned about', function () {
        $this->travelTo(CarbonImmutable::parse('2026-02-20 10:00:00', 'UTC'));

        closingPage()->assertSee('The first month is over');
    });

    test('no late notice warning within the first month', function () {
        closingPage()->assertDontSee('The first month is over');
    });
});

describe('notification', function () {
    test('the contact details are shown as a temporary reminder', function () {
        closingPage()
            ->assertSee('DM requester.person on Discord')
            ->assertSee('Temporary: deleted automatically when the request is closed.');
    });

    test('the notification can be recorded from the page', function () {
        closingPage()
            ->set('notificationOutcome', NotificationOutcome::Unreachable->value)
            ->set('notificationNotes', 'Left the server, DMs closed')
            ->call('recordNotification')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show')
            ->assertSee('Unreachable')
            ->assertSee('Update notification');

        $this->request->refresh();

        expect($this->request->notification_outcome)->toBe(NotificationOutcome::Unreachable)
            ->and($this->request->notification_notes)->toBe('Left the server, DMs closed');
    });

    test('a page opened after the notification was recorded shows it in the form', function () {
        $this->request->forceFill([
            'notification_outcome' => NotificationOutcome::Sent,
            'notification_notes' => 'Sent through Modmail',
        ])->save();

        closingPage()
            ->assertSet('notificationOutcome', 'sent')
            ->assertSet('notificationNotes', 'Sent through Modmail');
    });

    test('an outcome is required', function (string $outcome) {
        closingPage()
            ->set('notificationOutcome', $outcome)
            ->call('recordNotification')
            ->assertHasErrors('notificationOutcome');

        expect($this->request->refresh()->notification_outcome)->toBeNull();
    })->with(['missing' => '', 'unknown' => 'shouted']);
});

describe('closing', function () {
    test('closing is blocked until every system is resolved', function () {
        closingPage()->assertSee('Resolve every system first.');
    });

    test('closing is blocked until the notification is recorded', function () {
        resolveEverySystem();

        closingPage()->assertSee('Record the notification first.');
    });

    test('a request can be closed from the page, which purges the contact details', function () {
        resolveEverySystem();
        $this->request->forceFill(['notification_outcome' => NotificationOutcome::Sent])->save();

        closingPage()
            ->call('close')
            ->assertDispatched('toast-show')
            ->assertSee('Closed on')
            ->assertSee('This request and its history will be deleted on')
            ->assertSee('January 20, 2028')
            ->assertDontSee('DM requester.person on Discord')
            ->assertDontSee('Deadline extension')
            ->assertDontSee('Close request');

        $this->request->refresh();

        expect($this->request->status)->toBe(RequestStatus::Completed)
            ->and($this->request->contact_details)->toBeNull();
    });

    test('closing too early from a stale page shows a message instead of failing', function () {
        closingPage()
            ->call('close')
            ->assertOk()
            ->assertDispatched('toast-show');

        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);
    });
});

describe('rejecting', function () {
    test('the reject button is disabled until the notification is recorded', function () {
        closingPage()
            ->assertSee('Record the notification first: the requester must be told why.')
            ->assertDontSeeHtml('<flux:modal.trigger');
    });

    test('a request can be rejected from the page with its reason', function () {
        $this->request->forceFill(['notification_outcome' => NotificationOutcome::Sent])->save();

        closingPage()
            ->set('rejectionReason', 'Identity could not be verified')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show')
            ->assertDispatched('modal-close')
            ->assertSee('Rejected on')
            ->assertSee('Identity could not be verified');

        $this->request->refresh();

        expect($this->request->status)->toBe(RequestStatus::Rejected)
            ->and($this->request->contact_details)->toBeNull()
            ->and(Activity::where('action', ActivityAction::RequestRejected)->exists())->toBeTrue();
    });

    test('a reason is required to reject', function () {
        $this->request->forceFill(['notification_outcome' => NotificationOutcome::Sent])->save();

        closingPage()
            ->set('rejectionReason', '')
            ->call('reject')
            ->assertHasErrors('rejectionReason');

        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);
    });

    test('rejecting without a notification from a stale page shows a message instead of failing', function () {
        closingPage()
            ->set('rejectionReason', 'Abusive')
            ->call('reject')
            ->assertOk()
            ->assertDispatched('toast-show');

        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);
    });
});

describe('closed requests', function () {
    test('a closed request shows a read-only summary of the notification', function () {
        $this->request->forceFill([
            'status' => RequestStatus::Completed,
            'closed_at' => '2026-01-19 10:00:00',
            'purge_after' => '2028-01-19',
            'notification_outcome' => NotificationOutcome::Sent,
            'notification_notes' => 'Sent through Modmail',
            'notified_at' => '2026-01-19 09:00:00',
        ])->save();

        closingPage()
            ->assertSee('Sent through Modmail')
            ->assertDontSee('Record notification')
            ->assertDontSee('Update notification')
            ->assertDontSee('Reject request');
    });
});
