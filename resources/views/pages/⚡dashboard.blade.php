<?php

use App\Enums\DeadlineStatus;
use App\Enums\RequestStatus;
use App\Models\PrivacyRequest;
use App\Services\DeadlineCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')]
class extends Component {
    /**
     * @return Collection<int, PrivacyRequest>
     */
    #[Computed]
    public function openRequests(): Collection
    {
        return PrivacyRequest::open()
            ->with('requestSystems')
            ->get()
            ->sortBy(fn(PrivacyRequest $request): CarbonImmutable => $request->effectiveDeadline())
            ->values();
    }

    #[Computed]
    public function overdueCount(): int
    {
        $calculator = app(DeadlineCalculator::class);

        return $this->openRequests
            ->filter(fn(PrivacyRequest $request): bool => $calculator->status(
                    $request->effectiveDeadline(),
                    now()->toImmutable()
                ) === DeadlineStatus::Overdue)
            ->count();
    }

    #[Computed]
    public function closedRecentlyCount(): int
    {
        return PrivacyRequest::where('closed_at', '>=', now()->subDays(30))->count();
    }

    public function deadlineStatusOf(PrivacyRequest $request): DeadlineStatus
    {
        return app(DeadlineCalculator::class)->status($request->effectiveDeadline(), now()->toImmutable());
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading level="1" size="xl">{{ __('Dashboard') }}</flux:heading>

        <div class="flex gap-4">
            @can('admin')
                <flux:button variant="primary" icon="arrow-down-tray" :href="route('admin.requests.export')" download>
                    {{ __('Export requests') }}
                </flux:button>
            @endcan

            <flux:button variant="primary" icon="plus" :href="route('requests.create')" wire:navigate>
                {{ __('New request') }}
            </flux:button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Open requests') }}</flux:text>
            <flux:heading size="xl">{{ $this->openRequests->count() }}</flux:heading>
        </flux:card>

        <flux:card @class(['space-y-1', 'border-red-300! dark:border-red-500/50!' => $this->overdueCount > 0])>
            <flux:text size="sm">{{ __('Overdue') }}</flux:text>
            <flux:heading size="xl" @class(['text-red-600! dark:text-red-400!' => $this->overdueCount > 0])>
                {{ $this->overdueCount }}
            </flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm">{{ __('Closed in the last 30 days') }}</flux:text>
            <flux:heading size="xl">{{ $this->closedRecentlyCount }}</flux:heading>
        </flux:card>
    </div>

    <flux:card class="space-y-4">
        <flux:heading level="2" size="lg">{{ __('Open requests') }}</flux:heading>

        @if ($this->openRequests->isEmpty())
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <flux:icon.check-badge class="size-10 text-zinc-300 dark:text-zinc-600"/>
                <flux:heading>{{ __('Nothing to do right now.') }}</flux:heading>
                <flux:text>{{ __('New requests will show up here, the most urgent first.') }}</flux:text>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="py-2 pe-4 font-medium">{{ __('Request') }}</th>
                        <th class="py-2 pe-4 font-medium">{{ __('Type') }}</th>
                        <th class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                        <th class="py-2 pe-4 font-medium">{{ __('Systems') }}</th>
                        <th class="py-2 font-medium">{{ __('Deadline') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <?php /** @var PrivacyRequest $request */ ?>
                    @foreach ($this->openRequests as $request)
                        @php($deadline = $request->effectiveDeadline())
                        @php($deadlineStatus = $this->deadlineStatusOf($request))
                        @php($resolvedCount = $request->requestSystems->filter(fn ($row): bool => $row->status->isResolved())->count())

                        <tr wire:key="request-{{ $request->id }}">
                            <td class="py-3 pe-4">
                                <flux:link :href="route('requests.show', $request)" wire:navigate class="font-medium">
                                    {{ $request->reference }}
                                </flux:link>
                                <flux:text size="sm">
                                    {{ __('Received') }} <x-local-date :date="$request->received_at"/>
                                </flux:text>
                            </td>
                            <td class="py-3 pe-4">{{ $request->type->label() }}</td>
                            <td class="py-3 pe-4">
                                <flux:badge size="sm" color="blue">{{ $request->status->label() }}</flux:badge>
                            </td>
                            <td class="py-3 pe-4">
                                {{ __(':resolved of :total resolved', ['resolved' => $resolvedCount, 'total' => $request->requestSystems->count()]) }}
                            </td>
                            <td class="py-3">
                                <flux:badge size="sm" icon="clock" :color="$deadlineStatus->colour()">
                                    {{ ucfirst($deadline->diffForHumans()) }}
                                </flux:badge>
                                <flux:text size="sm">
                                    <x-local-date :date="$deadline"/>
                                    @if ($request->extended_until !== null)
                                        · {{ __('extended') }}
                                    @endif
                                </flux:text>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </flux:card>
</div>
