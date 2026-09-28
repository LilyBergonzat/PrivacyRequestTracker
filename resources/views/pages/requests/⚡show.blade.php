<?php

use App\Enums\ActivityAction;
use App\Enums\DeadlineStatus;
use App\Enums\NotificationOutcome;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\SystemStep;
use App\Services\DeadlineCalculator;
use App\Services\PrivacyRequestWorkflow;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Request')]
class extends Component {
    #[Locked]
    public PrivacyRequest $privacyRequest;

    public string $identityNotes = '';
    public string $extendedUntil = '';
    public string $extensionReason = '';
    public string $extensionNotifiedOn = '';
    public string $notificationOutcome = '';
    public string $notificationNotes = '';
    public string $rejectionReason = '';

    /**
     * @var array<int, array{status: string, summary: string}>
     */
    public array $resolutions = [];

    #[Computed]
    public function deadlineStatus(): DeadlineStatus
    {
        return app(DeadlineCalculator::class)->status(
            $this->privacyRequest->effectiveDeadline(),
            CarbonImmutable::now()
        );
    }

    #[Computed]
    public function identityVerificationActivity(): ?Activity
    {
        return $this->privacyRequest->activities()->where(
            'action',
            ActivityAction::IdentityVerified
        )->latest('created_at')->first();
    }

    /**
     * @return Collection<int, RequestSystem>
     */
    #[Computed]
    public function requestSystems(): Collection
    {
        return $this->privacyRequest->requestSystems()
            ->with(['system', 'stepCompletions.completedBy', 'completedBy'])
            ->get()
            ->sortBy(fn(RequestSystem $row): int => $row->system->sort_order)
            ->values();
    }

    /**
     * @return Collection<int, Collection<int, SystemStep>> the steps, grouped by system id
     */
    #[Computed]
    public function stepsBySystem(): Collection
    {
        return SystemStep::whereIn('system_id', $this->requestSystems->pluck('system_id'))
            ->active()
            ->forRequestType($this->privacyRequest->type)
            ->ordered()
            ->get()
            ->groupBy('system_id');
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->privacyRequest->activities()
            ->with('user')
            ->oldest('created_at')
            ->oldest('id')
            ->get();
    }

    /**
     * @return SupportCollection<int, string>
     */
    #[Computed]
    public function stepTitles(): SupportCollection
    {
        $stepIds = $this->activities->pluck('metadata.step_id')->filter()->unique();

        return SystemStep::whereIn('id', $stepIds)->pluck('title', 'id');
    }

    #[Computed]
    public function earliestExtensionDate(): string
    {
        return $this->privacyRequest->due_at->setTimezone(config('privacy.timezone'))->addDay()->toDateString();
    }

    #[Computed]
    public function latestExtensionDate(): string
    {
        return app(DeadlineCalculator::class)->maxExtension($this->privacyRequest->received_at)
            ->setTimezone(config('privacy.timezone'))->toDateString();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function placeholderNames(): array
    {
        return $this->stepsBySystem
            ->flatten()
            ->flatMap(fn (SystemStep $step): array => $step->placeholders())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function rendering(View $view): void
    {
        $view->title(__('Request :reference', ['reference' => $this->privacyRequest->reference]));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'resolutions.*.status' => __('outcome'),
            'resolutions.*.summary' => __('summary'),
        ];
    }

    public function mount(): void
    {
        $this->identityNotes = $this->privacyRequest->identity_verification_notes ?? '';

        foreach ($this->privacyRequest->requestSystems as $row) {
            $this->resolutions[$row->id] = ['status' => '', 'summary' => $row->result_summary ?? ''];
        }

        $timezone = config('privacy.timezone');
        $this->extendedUntil = $this->privacyRequest->extended_until?->setTimezone($timezone)->toDateString() ?? '';
        $this->extensionReason = $this->privacyRequest->extension_reason ?? '';
        $this->extensionNotifiedOn = $this->privacyRequest->extension_notified_at?->setTimezone($timezone)->toDateString() ?? '';
        $this->notificationOutcome = $this->privacyRequest->notification_outcome?->value ?? '';
        $this->notificationNotes = $this->privacyRequest->notification_notes ?? '';
        $this->rejectionReason = $this->privacyRequest->rejection_reason ?? '';
    }

    public function saveIdentityNotes(PrivacyRequestWorkflow $workflow): void
    {
        $validated = $this->validate(['identityNotes' => 'nullable|string|max:5000']);

        $workflow->saveIdentityNotes($this->privacyRequest, $validated['identityNotes'] ?: null);
        Flux::toast(variant: 'success', text: __('Saved identity notes'));

        $this->afterWorkflowChange();
    }

    public function verifyIdentity(PrivacyRequestWorkflow $workflow): void
    {
        $workflow->verifyIdentity($this->privacyRequest);
        Flux::toast(variant: 'success', text: __('Verified identity'));

        $this->afterWorkflowChange();
    }

    public function toggleStep(int $rowId, int $stepId): void
    {
        try {
            $row = $this->findRow($rowId);
            $step = $row->system->steps()->findOrFail($stepId);
            $workflow = app(PrivacyRequestWorkflow::class);

            if ($row->stepCompletions()->where('system_step_id', $step->id)->exists()) {
                $workflow->uncompleteStep($row, $step);
            } else {
                $workflow->completeStep($row, $step, auth()->user());
            }
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function resolveSystem(int $rowId): void
    {
        try {
            $row = $this->findRow($rowId);
            $this->validate([
                'resolutions.' . $rowId . '.status' => ['required', Rule::enum(RequestSystemStatus::class)->except([RequestSystemStatus::Pending])],
                'resolutions.' . $rowId . '.summary' => ['required', 'string', 'max:5000'],
            ]);

            app(PrivacyRequestWorkflow::class)->resolveSystem(
                $row,
                RequestSystemStatus::from($this->resolutions[$rowId]['status']),
                $this->resolutions[$rowId]['summary'],
                auth()->user(),
            );

            Flux::toast(variant: 'success', text: __('Resolved system'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function reopenSystem(int $rowId): void
    {
        try {
            $row = $this->findRow($rowId);
            app(PrivacyRequestWorkflow::class)->reopenSystem($row);

            Flux::toast(variant: 'success', text: __('Reopened system'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function activityDetails(Activity $activity): ?string
    {
        $metadata = $activity->metadata ?? [];

        $system = isset($metadata['system_id'])
            ? $this->requestSystems->firstWhere('system_id', $metadata['system_id'])?->system->name
            : null;

        return match ($activity->action) {
            ActivityAction::StepCompleted, ActivityAction::StepUncompleted =>
                ($this->stepTitles[$metadata['step_id']] ?? __('a removed step')) . ' · ' . $system,
            ActivityAction::SystemResolved =>
                $system . ' · ' . RequestSystemStatus::from($metadata['status'])->label(),
            ActivityAction::SystemReopened => $system,
            default => null,
        };
    }

    public function extend(): void
    {
        try {
            $timezone = config('privacy.timezone');
            $validated = $this->validate([
                'extendedUntil' => ['required', 'date', 'after_or_equal:' . $this->earliestExtensionDate, 'before_or_equal:' . $this->latestExtensionDate],
                'extensionReason' => ['required', 'string', 'max:2000'],
                'extensionNotifiedOn' => ['nullable', 'date', 'before_or_equal:today'],
            ]);

            app(PrivacyRequestWorkflow::class)->extend(
                $this->privacyRequest,
                CarbonImmutable::parse($this->extendedUntil, $timezone)->endOfDay()->utc(),
                $validated['extensionReason'],
                !empty($this->extensionNotifiedOn) ? CarbonImmutable::parse($this->extensionNotifiedOn, $timezone)->startOfDay()->utc() : null,
            );

            Flux::toast(variant: 'success', text: __('Extended request'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function recordNotification(): void
    {
        try {
            $validated = $this->validate([
                'notificationOutcome' => ['required', Rule::enum(NotificationOutcome::class)],
                'notificationNotes' => ['nullable', 'string', 'max:2000'],
            ]);

            app(PrivacyRequestWorkflow::class)->recordNotification(
                $this->privacyRequest,
                NotificationOutcome::from($this->notificationOutcome),
                $this->notificationNotes ?: null,
            );

            Flux::toast(variant: 'success', text: __('Recorded notification'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function close(): void
    {
        try {
            app(PrivacyRequestWorkflow::class)->close($this->privacyRequest);

            Flux::toast(variant: 'success', text: __('Closed request'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    public function reject(): void
    {
        try {
            $validated = $this->validate([
                'rejectionReason' => ['required', 'string', 'max:2000'],
            ]);

            app(PrivacyRequestWorkflow::class)->reject($this->privacyRequest, $this->rejectionReason);

            Flux::modal('reject-request')->close();
            Flux::toast(variant: 'success', text: __('Rejected request'));
        } catch (DomainException) {
            Flux::toast(variant: 'danger', text: __('This is no longer possible, the page has been refreshed.'));
        }

        $this->afterWorkflowChange();
    }

    private function findRow(int $rowId): RequestSystem
    {
        return $this->privacyRequest->requestSystems()->findOrFail($rowId);
    }

    private function afterWorkflowChange(): void
    {
        $this->privacyRequest->refresh();
        unset($this->requestSystems, $this->stepsBySystem);
        unset($this->activities);
    }
};
?>

<div class="mx-auto max-w-4xl space-y-6">
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading level="1" size="xl">
                {{ __('Request :reference', ['reference' => $privacyRequest->reference]) }}
            </flux:heading>
            <flux:badge size="sm" color="zinc">{{ $privacyRequest->type->label() }}</flux:badge>
            <flux:badge size="sm" color="blue">{{ $privacyRequest->status->label() }}</flux:badge>
        </div>

        <flux:text>
            {{ __('Received on') }}
            <x-local-date :date="$privacyRequest->received_at"/>
            · {{ __('via :channel', ['channel' => $privacyRequest->channel->label()]) }}
            · {{ __('created by :name', ['name' => $privacyRequest->creator->name]) }}
        </flux:text>
    </div>

    @if ($privacyRequest->status->isClosed())
        @php($isRejected = $privacyRequest->status === RequestStatus::Rejected)

        <flux:callout :variant="$isRejected ? 'danger' : 'success'" :icon="$isRejected ? 'no-symbol' : 'check-circle'">
            <flux:callout.heading>
                {{ $isRejected ? __('Rejected on') : __('Closed on') }}
                <x-local-date :date="$privacyRequest->closed_at"/>
            </flux:callout.heading>

            <flux:callout.text>
                @if ($isRejected && $privacyRequest->rejection_reason)
                    <span class="mb-2 block whitespace-pre-line">{{ $privacyRequest->rejection_reason }}</span>
                @endif

                {{ __('This request and its history will be deleted on') }}
                <x-local-date :date="$privacyRequest->purge_after"/>
                .
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:card class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1">
                <flux:text size="sm">{{ __('Deadline') }}</flux:text>
                <flux:heading level="2" size="lg">
                    {{ __('Due :diff', ['diff' => $privacyRequest->effectiveDeadline()->diffForHumans()]) }}
                </flux:heading>
                <flux:text size="sm">
                    <x-local-date :date="$privacyRequest->effectiveDeadline()" with-time/>
                    @if ($privacyRequest->extended_until !== null)
                        · {{ __('extended') }}
                    @endif
                </flux:text>
            </div>

            <flux:badge size="lg" icon="clock" :color="$this->deadlineStatus->colour()">
                {{ $this->deadlineStatus->label() }}
            </flux:badge>
        </flux:card>

        @php($isExtended = $privacyRequest->extended_until !== null)
        @php($extensionNoticeLate = app(DeadlineCalculator::class)->isExtensionNoticeLate($privacyRequest->received_at, now()->toImmutable()))

        <flux:card class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading level="2" size="lg">{{ __('Deadline extension') }}</flux:heading>

                @if ($isExtended)
                    @if ($privacyRequest->extension_notified_at !== null)
                        <flux:badge size="sm" color="green" icon="check">{{ __('Requester told') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber"
                                    icon="exclamation-triangle">{{ __('Requester not told yet') }}</flux:badge>
                    @endif
                @endif
            </div>

            @if ($isExtended)
                <div class="space-y-1">
                    <flux:text>
                        {{ __('Extended until') }}
                        <strong class="text-zinc-800 dark:text-zinc-200">
                            <x-local-date :date="$privacyRequest->extended_until" with-time/>
                        </strong>
                    </flux:text>
                    <flux:text class="whitespace-pre-line">{{ $privacyRequest->extension_reason }}</flux:text>

                    @if ($privacyRequest->extension_notified_at !== null)
                        <flux:text size="sm">
                            {{ __('Requester told on') }}
                            <x-local-date :date="$privacyRequest->extension_notified_at"/>
                        </flux:text>
                    @endif
                </div>
            @else
                <flux:text>
                    {{ __('The deadline can be extended by up to two more months when a request is complex, as long as the requester is told within the first month.') }}
                </flux:text>
            @endif

            @if ($extensionNoticeLate && (! $isExtended || $privacyRequest->extension_notified_at === null))
                <flux:callout variant="warning" icon="exclamation-triangle"
                              :heading="__('The first month is over: the requester should have been told about the extension before it ended.')"/>
            @endif

            <details @if ($errors->hasAny(['extendedUntil', 'extensionReason', 'extensionNotifiedOn'])) open @endif>
                <summary class="cursor-pointer text-sm font-medium text-zinc-600 dark:text-zinc-300">
                    {{ $isExtended ? __('Edit the extension') : __('Extend the deadline') }}
                </summary>

                <div class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input type="date" wire:model="extendedUntil" :label="__('Extend until')"
                                    :min="$this->earliestExtensionDate" :max="$this->latestExtensionDate"
                                    :description="__('At the latest :date.', ['date' => CarbonImmutable::parse($this->latestExtensionDate)->isoFormat('LL')])"/>

                        <flux:input type="date" wire:model="extensionNotifiedOn" :label="__('Requester told on')"
                                    :max="now(config('privacy.timezone'))->toDateString()"
                                    :description="__('Leave empty if they have not been told yet.')"/>
                    </div>

                    <flux:textarea wire:model="extensionReason" :label="__('Reason')" rows="3"
                                   :description="__('Why more time is needed. No identifiers or message contents.')"/>

                    <div class="flex justify-end">
                        <flux:button variant="primary" wire:click="extend">
                            {{ $isExtended ? __('Update extension') : __('Extend deadline') }}
                        </flux:button>
                    </div>
                </div>
            </details>
        </flux:card>
    @endif

    <flux:card class="space-y-4">
        <flux:heading level="2" size="lg">{{ __('Identity') }}</flux:heading>

        @if ($privacyRequest->status->canVerifyIdentity())
            <form class="space-y-6">
                <flux:textarea wire:model="identityNotes" :label="__('How was the identity verified?')" rows="3"/>
                <flux:text size="sm">
                    {{ __('e.g. "Asked from the same Discord account as the one in the request".') }}<br/>
                    {{ __('Do not paste identifiers or documents here.') }}
                </flux:text>


                <div class="space-x-4">
                    <flux:button wire:click="saveIdentityNotes">{{ __('Save notes') }}</flux:button>
                    <flux:button wire:click="verifyIdentity" variant="primary"
                                 :wire:confirm="__('Are you sure you want to mark this identity as verified?')">
                        {{ __('Mark identity as verified') }}
                    </flux:button>
                </div>
            </form>
        @else
            <div class="flex gap-4 items-center my-6">
                <flux:badge color="green">{{ __('Verified') }}</flux:badge>

                <flux:text>
                    {{ __('Verified by :user on', ['user' => $this->identityVerificationActivity?->user?->name ?? __('someone')]) }}
                    <x-local-date :date="$this->identityVerificationActivity?->created_at"/>
                </flux:text>
            </div>

            <flux:text class="whitespace-pre-line">{{ $this->privacyRequest->identity_verification_notes }}</flux:text>
        @endif
    </flux:card>

    <flux:card class="space-y-4">
        <flux:heading level="2" size="lg">{{ __('Systems') }}</flux:heading>

        @if ($privacyRequest->status->canVerifyIdentity())
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('Verify the identity before working on the systems.') }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->placeholderNames !== [] && ! $privacyRequest->status->isClosed())
            <div wire:ignore class="space-y-3 rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
                <div class="flex items-start gap-3">
                    <flux:icon.lock-closed variant="micro" class="mt-1 text-zinc-400"/>
                    <div>
                        <flux:heading size="sm" level="3">{{ __('Fill placeholders') }}</flux:heading>
                        <flux:text size="sm">
                            {{ __('Type the requester\'s identifiers to fill in the commands below. Not saved or sent anywhere: it only stays in this browser tab.') }}
                        </flux:text>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->placeholderNames as $placeholderName)
                        <flux:input x-model="$store.placeholders.values.{{ strtolower($placeholderName) }}"
                                    :label="$placeholderName" class:input="font-mono"
                                    autocomplete="off" spellcheck="false"/>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="space-y-4">
            <?php /** @var RequestSystem $row */ ?>
            @foreach ($this->requestSystems as $row)
                @php($steps = $this->stepsBySystem[$row->system_id] ?? collect())
                @php($removedSteps = $row->stepCompletions->whereNull('system_step_id'))
                @php($canWork = $privacyRequest->status === RequestStatus::InProgress && ! $row->status->isResolved())
                @php($completedCount = $row->stepCompletions->whereNotNull('system_step_id')->count())

                <div wire:key="row-{{ $row->id }}"
                     class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div class="flex items-center gap-2">
                            <flux:heading level="3">{{ $row->system->name }}</flux:heading>
                            <flux:badge size="sm"
                                        :color="$row->status->colour()">{{ $row->status->label() }}</flux:badge>
                        </div>

                        <flux:text size="sm">
                            {{ __('Due') }}
                            <x-local-date :date="$row->due_at"/>
                            · {{ __(':done of :total steps', ['done' => $completedCount, 'total' => $steps->count()]) }}
                        </flux:text>
                    </div>

                    <details class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700"
                             @if (! $row->status->isResolved()) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-zinc-500 dark:text-zinc-400">
                            {{ __('Steps') }}
                        </summary>

                        <ul class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($steps as $step)
                                @php($completion = $row->stepCompletions->firstWhere('system_step_id', $step->id))

                                <li wire:key="step-{{ $row->id }}-{{ $step->id }}" class="flex items-start gap-3 py-3">
                                    <flux:checkbox :checked="$completion !== null" :disabled="! $canWork"
                                                   wire:click="toggleStep({{ $row->id }}, {{ $step->id }})"/>

                                    <div class="min-w-0 flex-1 space-y-1">
                                        <details>
                                            <summary @class([
                                                'cursor-pointer text-sm font-medium',
                                                'text-zinc-500 dark:text-zinc-400' => $completion !== null,
                                            ])>{{ $step->title }}</summary>
                                            <x-markdown :content="$step->instructions" class="mt-2" fill-placeholders/>
                                        </details>

                                        @if ($completion)
                                            <flux:text size="sm">
                                                {{ __('Done by :name', ['name' => $completion->completedBy?->name ?? __('someone')]) }}
                                                ·
                                                <x-local-date :date="$completion->completed_at" with-time/>
                                            </flux:text>
                                        @endif
                                    </div>
                                </li>
                            @endforeach

                            @foreach ($removedSteps as $stepCompletion)
                                <li wire:key="removed-{{ $row->id }}-{{ $stepCompletion->id }}"
                                    class="flex items-start gap-3 py-3">
                                    <flux:checkbox checked disabled/>

                                    <div class="min-w-0 flex-1 space-y-1">
                                        <flux:text class="text-sm font-medium">
                                            {{ __(':title (step removed)', ['title' => $stepCompletion->step_title]) }}
                                        </flux:text>
                                        <flux:text size="sm">
                                            {{ __('Done by :name', ['name' => $stepCompletion->completedBy?->name ?? __('someone')]) }}
                                            ·
                                            <x-local-date :date="$stepCompletion->completed_at" with-time/>
                                        </flux:text>
                                    </div>
                                </li>
                            @endforeach

                            @if ($steps->isEmpty() && $removedSteps->isEmpty())
                                <li class="py-3">
                                    <flux:text size="sm">{{ __('No steps for this type of request.') }}</flux:text>
                                </li>
                            @endif
                        </ul>
                    </details>

                    @if ($canWork)
                        @php($resolveConfirmation = $completedCount < $steps->count()
                            ? __('Only :done of :total steps are checked. Resolve anyway?', ['done' => $completedCount, 'total' => $steps->count()])
                            : null)

                        <div
                            class="space-y-4 border-t border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-zinc-700 dark:bg-white/5">
                            <flux:heading level="4" size="sm">{{ __('Resolve this system') }}</flux:heading>

                            <flux:select wire:model="resolutions.{{ $row->id }}.status" :label="__('Outcome')"
                                         :placeholder="__('Choose...')" class="max-w-xs">
                                @foreach (RequestSystemStatus::cases() as $option)
                                    @if ($option !== RequestSystemStatus::Pending)
                                        <flux:select.option
                                            :value="$option->value">{{ $option->label() }}</flux:select.option>
                                    @endif
                                @endforeach
                            </flux:select>

                            <flux:textarea wire:model="resolutions.{{ $row->id }}.summary" :label="__('Summary')"
                                           rows="3"
                                           :description="__('What was found or deleted. No identifiers or message contents.')"/>

                            <div class="flex justify-end">
                                <flux:button variant="primary" wire:click="resolveSystem({{ $row->id }})"
                                             :wire:confirm="$resolveConfirmation">
                                    {{ __('Resolve') }}
                                </flux:button>
                            </div>
                        </div>
                    @elseif ($row->status->isResolved())
                        <div
                            class="flex flex-wrap items-start justify-between gap-4 border-t border-zinc-200 bg-zinc-50 px-4 py-4 dark:border-zinc-700 dark:bg-white/5">
                            <div class="min-w-0 flex-1 space-y-1">
                                <flux:text
                                    class="whitespace-pre-line text-zinc-800 dark:text-zinc-200">{{ $row->result_summary }}</flux:text>
                                <flux:text size="sm">
                                    {{ __('Resolved by :name', ['name' => $row->completedBy?->name ?? __('someone')]) }}
                                    ·
                                    <x-local-date :date="$row->completed_at" with-time/>
                                </flux:text>
                            </div>

                            @if ($privacyRequest->status->canReopen())
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left"
                                             wire:click="reopenSystem({{ $row->id }})"
                                             wire:confirm="{{ __('Reopen :system?', ['system' => $row->system->name]) }}">
                                    {{ __('Reopen') }}
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </flux:card>

    @php($outcome = $privacyRequest->notification_outcome)
    @php($outcomeColour = match ($outcome?->value) {
        'sent' => 'green',
        'unreachable' => 'amber',
        default => 'zinc',
    })

    @if (! $privacyRequest->status->isClosed())
        <flux:card class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading level="2" size="lg">{{ __('Requester notification') }}</flux:heading>

                @if ($outcome !== null)
                    <flux:badge size="sm" :color="$outcomeColour">{{ $outcome->label() }}</flux:badge>
                @endif
            </div>

            <div class="space-y-1 rounded-lg bg-zinc-50 p-4 dark:bg-white/5">
                <flux:text size="sm">{{ __('How to reach them') }}</flux:text>
                <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">
                    {{ $privacyRequest->contact_method->label() }}
                    @if ($privacyRequest->contact_details)
                        · {{ $privacyRequest->contact_details }}
                    @endif
                </flux:text>
                <flux:text
                    size="sm">{{ __('Temporary: deleted automatically when the request is closed.') }}</flux:text>
            </div>

            <div class="space-y-4">
                @if ($outcome !== null && $privacyRequest->notified_at !== null)
                    <flux:text size="sm">
                        {{ __('Recorded on') }}
                        <x-local-date :date="$privacyRequest->notified_at" with-time/>
                    </flux:text>
                @endif

                <flux:select wire:model="notificationOutcome" :label="__('Outcome')" :placeholder="__('Choose...')"
                             class="max-w-xs">
                    @foreach (\App\Enums\NotificationOutcome::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:textarea wire:model="notificationNotes" :label="__('Notes')" rows="3"
                               :description="__('What was sent, or what was tried if they could not be reached. No identifiers or message contents.')"/>

                <div class="flex justify-end">
                    <flux:button wire:click="recordNotification">
                        {{ $outcome !== null ? __('Update notification') : __('Record notification') }}
                    </flux:button>
                </div>
            </div>

            <flux:separator/>

            @php($closeBlocker = match (true) {
                $privacyRequest->status !== RequestStatus::AwaitingNotification => __('Resolve every system first.'),
                $outcome === null => __('Record the notification first.'),
                default => null,
            })

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col items-start gap-1">
                    @if ($outcome === null)
                        <flux:button variant="ghost" icon="no-symbol" disabled>{{ __('Reject request…') }}</flux:button>
                        <flux:text size="sm">{{ __('Record the notification first: the requester must be told why.') }}</flux:text>
                    @else
                        <flux:modal.trigger name="reject-request">
                            <flux:button variant="ghost" icon="no-symbol">{{ __('Reject request…') }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="flex flex-col items-start gap-1 sm:items-end">
                    <flux:button variant="primary" icon="check-circle" wire:click="close"
                                 :disabled="$closeBlocker !== null"
                                 wire:confirm="{{ __('Close this request? The contact details will be deleted.') }}">
                        {{ __('Close request') }}
                    </flux:button>

                    @if ($closeBlocker)
                        <flux:text size="sm" class="sm:text-end">{{ $closeBlocker }}</flux:text>
                    @endif
                </div>
            </div>
        </flux:card>

        <flux:modal name="reject-request" class="md:w-120">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Reject this request') }}</flux:heading>
                    <flux:text>{{ __('The request will be closed as rejected, and the contact details will be deleted.') }}</flux:text>
                </div>

                <flux:textarea wire:model="rejectionReason" :label="__('Why is this request rejected?')" rows="4"
                               :description="__('No identifiers or message contents.')"/>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" wire:click="reject">
                        {{ __('Reject') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @else
        <flux:card class="space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading level="2" size="lg">{{ __('Requester notification') }}</flux:heading>

                @if ($outcome !== null)
                    <flux:badge size="sm" :color="$outcomeColour">{{ $outcome->label() }}</flux:badge>
                @endif
            </div>

            @if ($outcome !== null)
                @if ($privacyRequest->notified_at !== null)
                    <flux:text size="sm">
                        {{ __('Recorded on') }}
                        <x-local-date :date="$privacyRequest->notified_at" with-time/>
                    </flux:text>
                @endif

                @if ($privacyRequest->notification_notes)
                    <flux:text class="whitespace-pre-line">{{ $privacyRequest->notification_notes }}</flux:text>
                @endif
            @else
                <flux:text>{{ __('No notification was recorded.') }}</flux:text>
            @endif
        </flux:card>
    @endif

    <flux:card class="space-y-4">
        <flux:heading level="2" size="lg">{{ __('Activity') }}</flux:heading>

        <ol class="space-y-4">
            @foreach ($this->activities as $activity)
                <li wire:key="activity-{{ $activity->id }}" class="flex gap-3">
                    <div
                        class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon :name="$activity->action->icon()" variant="micro" class="text-zinc-500"/>
                    </div>

                    <div class="min-w-0 flex-1">
                        <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">
                            {{ $activity->action->label() }}
                        </flux:text>

                        @if ($details = $this->activityDetails($activity))
                            <flux:text size="sm">{{ $details }}</flux:text>
                        @endif

                        <flux:text size="sm">
                            {{ $activity->user?->name ?? __('System') }} ·
                            <x-local-date :date="$activity->created_at" with-time/>
                        </flux:text>
                    </div>
                </li>
            @endforeach
        </ol>
    </flux:card>
</div>
