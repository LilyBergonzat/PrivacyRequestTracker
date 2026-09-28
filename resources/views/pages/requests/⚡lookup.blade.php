<?php

use App\Enums\ActivityAction;
use App\Enums\IdentifierType;
use App\Models\PrivacyRequest;
use App\Services\ActivityLogger;
use App\Services\MatchingRequestFinder;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lookup')]
class extends Component {
    public string $type = 'discord_id';
    public string $label = '';
    public string $value = '';

    /**
     * @var list<int>|null
     */
    #[Locked]
    public ?array $resultIds = null;

    /**
     * @return Collection<int, PrivacyRequest>
     */
    #[Computed]
    public function results(): Collection
    {
        return PrivacyRequest::whereIn('id', $this->resultIds ?? [])->latest('received_at')->get();
    }

    public function search(MatchingRequestFinder $finder, ActivityLogger $logger): void
    {
        $this->validate([
            'type' => ['required', Rule::enum(IdentifierType::class)],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['required_if:type,other', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $identifier = new SubjectIdentifier(
                IdentifierType::from($this->type),
                $this->value,
                $this->type === IdentifierType::Other->value ? $this->label : null,
            );
        } catch (InvalidArgumentException) {
            $this->addError('value', __('This identifier is not valid.'));
            return;
        }

        $this->resultIds = $finder->find([$identifier])->pluck('id')->all();
        $this->value = '';

        $logger->log(ActivityAction::LookupPerformed, metadata: ['matches' => count($this->resultIds)]);
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'value' => __('identifier'),
        ];
    }
};
?>

<div class="mx-auto max-w-4xl space-y-6">
    <div class="space-y-1">
        <flux:heading level="1" size="xl">{{ __('Lookup') }}</flux:heading>
        <flux:text>{{ __('Check whether someone has made a request before, without creating a new one.') }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="search" class="space-y-4">
            <div class="flex flex-wrap items-start gap-3">
                <flux:select wire:model.live="type" :label="__('Type')" class="w-40">
                    @foreach (IdentifierType::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($type === 'other')
                    <flux:input wire:model="label" :label="__('Label')" class="w-48" :placeholder="__('e.g. website account ID')"/>
                @endif

                <flux:input wire:model="value" :label="__('Identifier')" class="min-w-48 flex-1"
                            autocomplete="off" spellcheck="false"/>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text size="sm" class="flex items-center gap-2">
                    <flux:icon.lock-closed variant="micro"/>
                    {{ __('The identifier is hashed to search, then cleared. It is never stored, and the log only keeps the number of matches.') }}
                </flux:text>

                <flux:button type="submit" variant="primary" icon="magnifying-glass">{{ __('Search') }}</flux:button>
            </div>
        </form>
    </flux:card>

    @if ($resultIds !== null)
        <flux:card class="space-y-4">
            <flux:heading level="2" size="lg">
                {{ trans_choice(':count earlier request|:count earlier requests', $this->results->count()) }}
            </flux:heading>

            @if ($this->results->isEmpty())
                <div class="flex flex-col items-center gap-2 py-8 text-center">
                    <flux:icon.magnifying-glass class="size-8 text-zinc-300 dark:text-zinc-600"/>
                    <flux:text>{{ __('No request found for this identifier.') }}</flux:text>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-2 pe-4 font-medium">{{ __('Request') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Type') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Received') }}</th>
                            <th class="py-2 font-medium">{{ __('Closed') }}</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <?php /** @var PrivacyRequest $request */ ?>
                        @foreach ($this->results as $request)
                            <tr wire:key="result-{{ $request->id }}">
                                <td class="py-3 pe-4">
                                    <flux:link :href="route('requests.show', $request)" wire:navigate class="font-medium">
                                        {{ $request->reference }}
                                    </flux:link>
                                </td>
                                <td class="py-3 pe-4">{{ $request->type->label() }}</td>
                                @php($statusColour = match ($request->status->value) {
                                    'completed' => 'green',
                                    'rejected' => 'red',
                                    default => 'blue',
                                })
                                <td class="py-3 pe-4">
                                    <flux:badge size="sm" :color="$statusColour">{{ $request->status->label() }}</flux:badge>
                                </td>
                                <td class="py-3 pe-4"><x-local-date :date="$request->received_at"/></td>
                                <td class="py-3">
                                    @if ($request->closed_at)
                                        <x-local-date :date="$request->closed_at"/>
                                    @else
                                        <flux:text size="sm">{{ __('Still open') }}</flux:text>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </flux:card>
    @endif
</div>
