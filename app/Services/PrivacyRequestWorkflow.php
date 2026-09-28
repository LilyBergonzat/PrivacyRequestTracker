<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\NotificationOutcome;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\SystemStep;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class PrivacyRequestWorkflow
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly DeadlineCalculator $deadlineCalculator,
    ) {}

    public function saveIdentityNotes(PrivacyRequest $request, ?string $notes): void
    {
        if ($request->status->isClosed()) {
            throw new DomainException('You cannot edit identity notes on a closed request');
        }

        $request->identity_verification_notes = $notes;

        if ($request->status === RequestStatus::Received) {
            $request->status = RequestStatus::VerifyingIdentity;
        }

        $request->save();
    }

    public function verifyIdentity(PrivacyRequest $request): void
    {
        if (!$request->status->canVerifyIdentity()) {
            throw new DomainException('You cannot verify the identity on a request that\'s past this step');
        }

        $request->status = RequestStatus::InProgress;

        $request->save();
        $this->activityLogger->log(ActivityAction::IdentityVerified, $request);
    }

    public function completeStep(RequestSystem $row, SystemStep $step, User $by): void
    {
        $this->ensureInProgress($row->privacyRequest);

        if ($row->status->isResolved()) {
            throw new DomainException('Cannot complete a step from a resolved row');
        }

        if (!$row->system->is($step->system)) {
            throw new DomainException('Cannot complete a step from another system');
        }

        if ($row->stepCompletions()->where('system_step_id', $step->id)->exists()) {
            return;
        }

        $completion = new StepCompletion;

        $completion->system_step_id = $step->id;
        $completion->step_title = $step->title;
        $completion->completed_by = $by->id;
        $completion->completed_at = now();

        $row->stepCompletions()->save($completion);
        $this->activityLogger->log(ActivityAction::StepCompleted, $row->privacyRequest, [
            'system_id' => $row->system_id,
            'step_id' => $step->id,
        ]);
    }

    public function uncompleteStep(RequestSystem $row, SystemStep $step): void
    {
        $this->ensureInProgress($row->privacyRequest);

        if ($row->status->isResolved()) {
            throw new DomainException('Cannot complete a step from a resolved row');
        }

        if (!$row->system->is($step->system)) {
            throw new DomainException('Cannot uncomplete a step from another system');
        }

        $amountDeleted = $row->stepCompletions()->where('system_step_id', $step->id)->delete();

        if ($amountDeleted === 0) {
            return;
        }

        $this->activityLogger->log(ActivityAction::StepUncompleted, $row->privacyRequest, [
            'system_id' => $row->system_id,
            'step_id' => $step->id,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function resolveSystem(RequestSystem $row, RequestSystemStatus $status, string $summary, User $by): void
    {
        $this->ensureInProgress($row->privacyRequest);

        if ($row->status->isResolved()) {
            throw new DomainException('Cannot complete a system from a resolved row');
        }

        if ($status === RequestSystemStatus::Pending) {
            throw new DomainException('Cannot resolve a system as pending');
        }

        DB::transaction(function () use ($row, $status, $summary, $by): void {
            $row->status = $status;
            $row->result_summary = $summary;
            $row->completed_by = $by->id;
            $row->completed_at = CarbonImmutable::now();

            $row->save();

            $request = $row->privacyRequest;

            $this->activityLogger->log(ActivityAction::SystemResolved, $request, [
                'system_id' => $row->system_id,
                'status' => $status,
            ]);

            if ($request->requestSystems()->get()->every(fn (RequestSystem $r): bool => $r->status->isResolved())) {
                $request->status = RequestStatus::AwaitingNotification;
                $request->save();
            }
        });
    }

    /**
     * @throws Throwable
     */
    public function reopenSystem(RequestSystem $row): void
    {
        if (!$row->privacyRequest->status->canReopen()) {
            throw new DomainException('Cannot reopen a system unless the request is in progress or awaiting notification');
        }

        if (!$row->status->isResolved()) {
            throw new DomainException('Cannot reopen a system that\'s not resolved');
        }

        DB::transaction(function () use ($row): void {
            $row->status = RequestSystemStatus::Pending;
            $row->completed_by = $row->completed_at = null;

            $row->save();

            if ($row->privacyRequest->status === RequestStatus::AwaitingNotification) {
                $row->privacyRequest->status = RequestStatus::InProgress;
                $row->privacyRequest->save();
            }

            $this->activityLogger->log(ActivityAction::SystemReopened, $row->privacyRequest, ['system_id' => $row->system_id]);
        });
    }

    public function extend(PrivacyRequest $request, CarbonImmutable $until, string $reason, ?CarbonImmutable $notifiedAt): void
    {
        if ($request->status->isClosed()) {
            throw new DomainException('Cannot extend a closed request');
        }

        if (!$until->isAfter($request->due_at)) {
            throw new DomainException('Cannot extend a request until before its deadline');
        }

        if ($until->isAfter($this->deadlineCalculator->maxExtension($request->received_at))) {
            throw new DomainException('Cannot extend a request past its maximum extension limit');
        }

        $request->extended_until = $until;
        $request->extension_reason = $reason;
        $request->extension_notified_at = $notifiedAt;

        $request->save();
        $this->activityLogger->log(ActivityAction::RequestExtended, $request);
    }

    public function recordNotification(PrivacyRequest $request, NotificationOutcome $outcome, ?string $notes): void
    {
        if ($request->status->isClosed()) {
            throw new DomainException('Cannot notify about the outcome of a closed request');
        }

        $request->notification_outcome = $outcome;
        $request->notification_notes = $notes;
        $request->notified_at = CarbonImmutable::now();

        $request->save();

        $this->activityLogger->log(ActivityAction::RequesterNotified, $request, ['outcome' => $outcome]);
    }

    /**
     * @throws Throwable
     */
    public function close(PrivacyRequest $request): void
    {
        if ($request->status !== RequestStatus::AwaitingNotification || $request->notification_outcome === null) {
            throw new DomainException('Cannot close a request without trying to notify the requester');
        }

        DB::transaction(function () use ($request): void {
            $this->closeAs($request, RequestStatus::Completed);
        });
    }

    /**
     * @throws Throwable
     */
    public function reject(PrivacyRequest $request, string $reason): void
    {
        if ($request->status->isClosed() || $request->notification_outcome === null) {
            throw new DomainException(
                $request->status->isClosed()
                    ? 'Cannot reject a closed request'
                    : 'Cannot reject a request without trying to notify the requester'
            );
        }

        DB::transaction(function () use ($request, $reason): void {
            $request->rejection_reason = $reason;
            $this->closeAs($request, RequestStatus::Rejected);
        });
    }

    private function ensureInProgress(PrivacyRequest $request): void
    {
        if ($request->status !== RequestStatus::InProgress) {
            throw new DomainException('This request is not in progress.');
        }
    }

    private function closeAs(PrivacyRequest $request, RequestStatus $status): void
    {
        $hadContactDetails = $request->contact_details !== null;

        $request->status = $status;
        $request->closed_at = now();
        $request->purge_after = now()->addMonthsNoOverflow(config('privacy.retention_months'));
        $request->contact_details = null;
        $request->save();

        $this->activityLogger->log(
            $status === RequestStatus::Completed ? ActivityAction::RequestClosed : ActivityAction::RequestRejected,
            $request,
        );

        if ($hadContactDetails) {
            $this->activityLogger->log(ActivityAction::ContactPurged, $request);
        }
    }
}
