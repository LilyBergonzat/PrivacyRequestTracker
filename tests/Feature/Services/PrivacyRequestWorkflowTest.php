<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\System;
use App\Models\SystemStep;
use App\Models\User;
use App\Services\PrivacyRequestWorkflow;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->workflow = app(PrivacyRequestWorkflow::class);
});

function requestWithStatus(RequestStatus $status): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create();
    $request->forceFill(['status' => $status])->save();

    return $request;
}

describe('identity notes', function () {
    test('saving notes starts the verification', function () {
        $request = PrivacyRequest::factory()->create();

        $this->workflow->saveIdentityNotes($request, 'Asked from the same Discord account');

        $request->refresh();

        expect($request->status)->toBe(RequestStatus::VerifyingIdentity)
            ->and($request->identity_verification_notes)->toBe('Asked from the same Discord account');
    });

    test('notes can be updated while verifying, and cleared', function () {
        $request = requestWithStatus(RequestStatus::VerifyingIdentity);

        $this->workflow->saveIdentityNotes($request, 'First note');
        $this->workflow->saveIdentityNotes($request, null);

        expect($request->refresh()->identity_verification_notes)->toBeNull()
            ->and($request->status)->toBe(RequestStatus::VerifyingIdentity);
    });

    test('saving notes after verification keeps the status', function () {
        $request = requestWithStatus(RequestStatus::InProgress);

        $this->workflow->saveIdentityNotes($request, 'Added later');

        expect($request->refresh()->status)->toBe(RequestStatus::InProgress);
    });

    test('saving notes is not logged', function () {
        $this->workflow->saveIdentityNotes(PrivacyRequest::factory()->create(), 'Note');

        expect(Activity::count())->toBe(0);
    });

    test('notes cannot be edited on a closed request', function (RequestStatus $status) {
        $request = requestWithStatus($status);

        expect(fn () => $this->workflow->saveIdentityNotes($request, 'Too late'))->toThrow(DomainException::class)
            ->and($request->refresh()->identity_verification_notes)->toBeNull();
    })->with([RequestStatus::Completed, RequestStatus::Rejected]);
});

describe('verifying the identity', function () {
    test('verifying moves the request in progress and logs who did it', function (RequestStatus $from) {
        $request = requestWithStatus($from);

        $this->workflow->verifyIdentity($request);

        $activity = Activity::sole();

        expect($request->refresh()->status)->toBe(RequestStatus::InProgress)
            ->and($activity->action)->toBe(ActivityAction::IdentityVerified)
            ->and($activity->privacyRequest->is($request))->toBeTrue()
            ->and($activity->user->is($this->user))->toBeTrue();
    })->with([RequestStatus::Received, RequestStatus::VerifyingIdentity]);

    test('the identity cannot be verified twice or on a closed request', function (RequestStatus $status) {
        $request = requestWithStatus($status);

        expect(fn () => $this->workflow->verifyIdentity($request))->toThrow(DomainException::class)
            ->and($request->refresh()->status)->toBe($status)
            ->and(Activity::count())->toBe(0);
    })->with([
        RequestStatus::InProgress,
        RequestStatus::AwaitingNotification,
        RequestStatus::Completed,
        RequestStatus::Rejected,
    ]);

    test('only received and verifying requests can have their identity verified', function (RequestStatus $status, bool $expected) {
        expect($status->canVerifyIdentity())->toBe($expected);
    })->with([
        [RequestStatus::Received, true],
        [RequestStatus::VerifyingIdentity, true],
        [RequestStatus::InProgress, false],
        [RequestStatus::AwaitingNotification, false],
        [RequestStatus::Completed, false],
        [RequestStatus::Rejected, false],
    ]);
});

/**
 * A request in progress with one pending system row, and two steps on that system.
 *
 * @return array{request: PrivacyRequest, row: RequestSystem, step: SystemStep, otherStep: SystemStep}
 */
function requestInProgressWithSystem(): array
{
    $request = requestWithStatus(RequestStatus::InProgress);
    $system = System::factory()->create();
    $row = RequestSystem::factory()->for($request)->for($system)->create();

    return [
        'request' => $request,
        'row' => $row,
        'step' => SystemStep::factory()->for($system)->create(['title' => 'Delete threads']),
        'otherStep' => SystemStep::factory()->for($system)->create(['title' => 'Delete notes']),
    ];
}

describe('steps', function () {
    beforeEach(function () {
        ['request' => $this->request, 'row' => $this->row, 'step' => $this->step, 'otherStep' => $this->otherStep] = requestInProgressWithSystem();
    });

    test('completing a step records who, when and the title at that time', function () {
        $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

        $this->workflow->completeStep($this->row, $this->step, $this->user);

        $completion = StepCompletion::sole();

        expect($completion->requestSystem->is($this->row))->toBeTrue()
            ->and($completion->system_step_id)->toBe($this->step->id)
            ->and($completion->step_title)->toBe('Delete threads')
            ->and($completion->completed_by)->toBe($this->user->id)
            ->and($completion->completed_at->toDateTimeString())->toBe('2026-01-20 10:00:00');
    });

    test('completing a step is logged without personal data', function () {
        $this->workflow->completeStep($this->row, $this->step, $this->user);

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::StepCompleted)
            ->and($activity->metadata)->toBe(['system_id' => $this->row->system_id, 'step_id' => $this->step->id]);
    });

    test('completing the same step twice does nothing the second time', function () {
        $this->workflow->completeStep($this->row, $this->step, $this->user);
        $this->workflow->completeStep($this->row, $this->step, $this->user);

        expect(StepCompletion::count())->toBe(1)
            ->and(Activity::count())->toBe(1);
    });

    test('a step can be uncompleted, and it is logged', function () {
        $this->workflow->completeStep($this->row, $this->step, $this->user);
        $this->workflow->completeStep($this->row, $this->otherStep, $this->user);

        $this->workflow->uncompleteStep($this->row, $this->step);

        expect(StepCompletion::sole()->system_step_id)->toBe($this->otherStep->id)
            ->and(Activity::latest('id')->first()->action)->toBe(ActivityAction::StepUncompleted);
    });

    test('uncompleting a step that was not completed does nothing', function () {
        $this->workflow->uncompleteStep($this->row, $this->step);

        expect(Activity::count())->toBe(0);
    });

    test('a step of another system cannot be completed on this row', function () {
        $foreignStep = SystemStep::factory()->create();

        expect(fn () => $this->workflow->completeStep($this->row, $foreignStep, $this->user))->toThrow(DomainException::class)
            ->and(StepCompletion::count())->toBe(0);
    });

    test('a step of another system cannot be uncompleted on this row', function () {
        $foreignStep = SystemStep::factory()->create();

        expect(fn () => $this->workflow->uncompleteStep($this->row, $foreignStep))->toThrow(DomainException::class);
    });

    test('steps cannot change on a resolved row', function () {
        $this->row->forceFill(['status' => RequestSystemStatus::Done])->save();

        expect(fn () => $this->workflow->completeStep($this->row, $this->step, $this->user))->toThrow(DomainException::class)
            ->and(fn () => $this->workflow->uncompleteStep($this->row, $this->step))->toThrow(DomainException::class);
    });

    test('steps cannot change unless the request is in progress', function (RequestStatus $status) {
        $this->request->forceFill(['status' => $status])->save();
        $this->row->refresh();

        expect(fn () => $this->workflow->completeStep($this->row, $this->step, $this->user))->toThrow(DomainException::class)
            ->and(fn () => $this->workflow->uncompleteStep($this->row, $this->step))->toThrow(DomainException::class)
            ->and(StepCompletion::count())->toBe(0);
    })->with([
        'identity not verified' => RequestStatus::Received,
        'verifying identity' => RequestStatus::VerifyingIdentity,
        'awaiting notification' => RequestStatus::AwaitingNotification,
        'completed' => RequestStatus::Completed,
        'rejected' => RequestStatus::Rejected,
    ]);
});

describe('resolving systems', function () {
    beforeEach(function () {
        ['request' => $this->request, 'row' => $this->row] = requestInProgressWithSystem();
    });

    test('resolving records the outcome, the summary, who and when', function () {
        $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

        $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Deleted 4 threads', $this->user);

        $this->row->refresh();

        expect($this->row->status)->toBe(RequestSystemStatus::Done)
            ->and($this->row->result_summary)->toBe('Deleted 4 threads')
            ->and($this->row->completed_by)->toBe($this->user->id)
            ->and($this->row->completed_at->toDateTimeString())->toBe('2026-01-20 10:00:00');
    });

    test('resolving is logged with the status but never the summary', function () {
        $this->workflow->resolveSystem($this->row, RequestSystemStatus::NoDataFound, 'Nothing found in logs', $this->user);

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::SystemResolved)
            ->and($activity->metadata)->toBe(['system_id' => $this->row->system_id, 'status' => 'no_data_found']);
    });

    test('resolving the last system moves the request to awaiting notification', function () {
        $second = RequestSystem::factory()->for($this->request)->create();

        $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Done', $this->user);
        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);

        $this->workflow->resolveSystem($second, RequestSystemStatus::NotApplicable, 'Not used by them', $this->user);
        expect($this->request->refresh()->status)->toBe(RequestStatus::AwaitingNotification);
    });

    test('a system cannot be resolved as pending', function () {
        expect(fn () => $this->workflow->resolveSystem($this->row, RequestSystemStatus::Pending, 'Hmm', $this->user))
            ->toThrow(DomainException::class)
            ->and($this->row->refresh()->completed_by)->toBeNull();
    });

    test('a resolved system cannot be resolved again', function () {
        $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'First', $this->user);

        expect(fn () => $this->workflow->resolveSystem($this->row, RequestSystemStatus::NotApplicable, 'Second', $this->user))
            ->toThrow(DomainException::class)
            ->and($this->row->refresh()->result_summary)->toBe('First');
    });

    test('a system cannot be resolved before the identity is verified', function () {
        $this->request->forceFill(['status' => RequestStatus::VerifyingIdentity])->save();
        $this->row->refresh();

        expect(fn () => $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Done', $this->user))
            ->toThrow(DomainException::class);
    });
});

describe('reopening systems', function () {
    beforeEach(function () {
        ['request' => $this->request, 'row' => $this->row] = requestInProgressWithSystem();
        $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Deleted 4 threads', $this->user);
        $this->row->refresh();
    });

    test('reopening makes the system pending again and keeps the summary', function () {
        $this->workflow->reopenSystem($this->row);

        $this->row->refresh();

        expect($this->row->status)->toBe(RequestSystemStatus::Pending)
            ->and($this->row->completed_by)->toBeNull()
            ->and($this->row->completed_at)->toBeNull()
            ->and($this->row->result_summary)->toBe('Deleted 4 threads')
            ->and(Activity::latest('id')->first()->action)->toBe(ActivityAction::SystemReopened);
    });

    test('reopening a system sends an awaiting request back in progress', function () {
        expect($this->request->refresh()->status)->toBe(RequestStatus::AwaitingNotification);

        $this->workflow->reopenSystem($this->row);

        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);
    });

    test('a pending system cannot be reopened', function () {
        $this->workflow->reopenSystem($this->row);

        expect(fn () => $this->workflow->reopenSystem($this->row->refresh()))->toThrow(DomainException::class);
    });

    test('a system of a closed request cannot be reopened', function (RequestStatus $status) {
        $this->request->forceFill(['status' => $status])->save();
        $this->row->refresh();

        expect(fn () => $this->workflow->reopenSystem($this->row))->toThrow(DomainException::class)
            ->and($this->row->refresh()->status)->toBe(RequestSystemStatus::Done);
    })->with([RequestStatus::Completed, RequestStatus::Rejected]);
});
