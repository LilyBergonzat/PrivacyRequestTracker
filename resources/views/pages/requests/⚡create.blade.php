<?php

use App\Enums\ContactMethod;
use App\Enums\IdentifierType;
use App\Enums\RequestChannel;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\System;
use App\Services\MatchingRequestFinder;
use App\Services\PrivacyRequestCreator;
use App\ValueObjects\NewPrivacyRequest;
use App\ValueObjects\SubjectIdentifier;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New Privacy Request')]
class extends Component {
    private const array EMPTY_IDENTIFIER = ['type' => 'discord_id', 'label' => '', 'value' => ''];

    public string $type = '';
    public string $channel = '';
    public string $receivedAt = '';
    /**
     * @var list<array{type: string, label: string, value: string}>
     */
    public array $identifiers = [];
    public string $contactMethod = 'none';
    public string $contactDetails = '';
    public string $notes = '';
    /**
     * @var list<int>
     */
    public array $selectedSystems = [];

    /** @var list<int> */
    #[Locked]
    public array $earlierRequestIds = [];

    public bool $duplicatesConfirmed = false;

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'identifiers.*.type' => __('identifier type'),
            'identifiers.*.value' => __('identifier'),
            'identifiers.*.label' => __('label'),
            'selectedSystems' => __('systems'),
            'selectedSystems.*' => __('system'),
        ];
    }

    public function mount(): void
    {
        $this->receivedAt = now(config('privacy.timezone'))->toDateString();
        $this->identifiers = [self::EMPTY_IDENTIFIER];
        $this->selectedSystems = System::active()->pluck('id')->toArray();
    }

    #[Computed]
    public function systems(): Collection
    {
        return System::active()->ordered()->get();
    }

    /**
     * @return Collection<int, PrivacyRequest>
     */
    #[Computed]
    public function earlierRequests(): Collection
    {
        return PrivacyRequest::whereIn('id', $this->earlierRequestIds)->latest('received_at')->get();
    }

    public function addIdentifier(): void
    {
        $this->identifiers[] = self::EMPTY_IDENTIFIER;
        $this->updatedIdentifiers();
    }

    public function removeIdentifier(int $index): void
    {
        array_splice($this->identifiers, $index, 1);
        $this->updatedIdentifiers();
    }

    public function createRequest(PrivacyRequestCreator $creator, MatchingRequestFinder $finder): void
    {
        $validated = $this->validate([
            'type' => ['required', Rule::enum(RequestType::class)],
            'channel' => ['required', Rule::enum(RequestChannel::class)],
            'contactMethod' => ['required', Rule::enum(ContactMethod::class)],
            'receivedAt' => ['required', 'date', 'before_or_equal:today'],
            'identifiers' => ['required', 'array', 'min:1'],
            'identifiers.*.type' => ['required', Rule::enum(IdentifierType::class)],
            'identifiers.*.value' => ['required', 'string', 'max:255'],
            'identifiers.*.label' => ['required_if:identifiers.*.type,other', 'nullable', 'string', 'max:64'],
            'contactDetails' => ['required_unless:contactMethod,none', 'nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'selectedSystems' => ['required', 'array', 'min:1'],
            'selectedSystems.*' => ['integer', Rule::exists('systems', 'id')->where('is_active', true)],
        ]);

        $identifiers = [];

        foreach ($validated['identifiers'] as $index => $row) {
            try {
                $identifiers[] = new SubjectIdentifier(
                    IdentifierType::from($row['type']),
                    $row['value'],
                    $row['type'] === IdentifierType::Other->value ? $row['label'] : null,
                );
            } catch (InvalidArgumentException) {
                $this->addError("identifiers.{$index}.value", __('This identifier is not valid.'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        if (!$this->duplicatesConfirmed) {
            $earlier = $finder->find($identifiers);

            if ($earlier->isNotEmpty()) {
                $this->earlierRequestIds = $earlier->pluck('id')->all();

                return;
            }
        }

        $privacyRequestData = new NewPrivacyRequest(
            type: RequestType::from($validated['type']),
            channel: RequestChannel::from($validated['channel']),
            receivedAt: CarbonImmutable::parse($this->receivedAt, config('privacy.timezone'))->startOfDay()->utc(),
            contactMethod: ContactMethod::from($validated['contactMethod']),
            contactDetails: $validated['contactDetails'] ?: null,
            notes: $validated['notes'] ?: null,
            identifiers: $identifiers,
            systems: System::whereIn('id', $this->selectedSystems)->get()->all(),
        );

        $privacyRequest = $creator->create($privacyRequestData, auth()->user());

        Flux::toast(variant: 'success', text: __('The request was created successfully!'));
        $this->redirectRoute('requests.show', $privacyRequest, navigate: true);
    }

    public function updatedIdentifiers(): void
    {
        $this->duplicatesConfirmed = false;
        $this->earlierRequestIds = app(MatchingRequestFinder::class)
            ->find($this->validSubjectIdentifiers())
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<SubjectIdentifier>
     */
    private function validSubjectIdentifiers(): array
    {
        $identifiers = [];

        foreach ($this->identifiers as $row) {
            try {
                $identifiers[] = new SubjectIdentifier(
                    IdentifierType::from($row['type']),
                    $row['value'],
                    $row['type'] === 'other' ? $row['label'] : null,
                );
            } catch (InvalidArgumentException|ValueError) {
                // Incomplete or invalid rows are simply skipped until the form is submitted.
            }
        }

        return $identifiers;
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New request') }}</flux:heading>

    <form wire:submit="createRequest" class="space-y-6">
        <flux:card class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Request') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="type" :label="__('Type')" :placeholder="__('Choose...')" required>
                    @foreach(RequestType::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="channel" :label="__('Channel')" :placeholder="__('Choose...')" required>
                    @foreach(RequestChannel::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input type="date" wire:model="receivedAt" :label="__('Received on')"/>
            </div>

            <flux:textarea wire:model="notes" :label="__('Notes')"/>
            <flux:text size="sm">{{ __('Don\'t paste identifiers or message contents here.') }}</flux:text>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Identifiers') }}</flux:heading>
            <flux:text size="sm">{{ __('Identifiers are hashed and never stored in plain text.') }}</flux:text>

            @foreach ($identifiers as $index => $identifier)
                <div wire:key="identifier-{{ $index }}" class="flex items-start gap-3">
                    <flux:select wire:model.live="identifiers.{{ $index }}.type" :label="__('Type')"
                                 :placeholder="__('Choose...')" class="w-40">
                        @foreach(IdentifierType::cases() as $option)
                            <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($identifier['type'] === 'other')
                        <flux:input wire:model.live.blur="identifiers.{{ $index }}.label" :label="__('Label')" class="w-40"
                                    required/>
                    @endif

                    <flux:input wire:model.live.blur="identifiers.{{ $index }}.value" :label="__('Value')" class="flex-1"
                                required/>

                    @if (count($identifiers) > 1)
                        <flux:button variant="ghost" icon="x-mark" wire:click="removeIdentifier({{ $index }})"/>
                    @endif
                </div>
            @endforeach

            <flux:button size="sm" icon="plus" wire:click="addIdentifier">{{ __('Add identifier') }}</flux:button>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Contact') }}</flux:heading>
            <flux:text size="sm">{{ __('Temporary: deleted automatically when the request is closed.') }}</flux:text>

            <div class="flex items-start gap-3">
                <flux:select wire:model="contactMethod" :label="__('Contact Method')" :placeholder="__('Choose...')"
                             class="w-40" required>
                    @foreach(ContactMethod::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="contactDetails" :label="__('Contact Details')" class="flex-1"/>
            </div>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Systems') }}</flux:heading>

            <flux:checkbox.group wire:model="selectedSystems">
                <flux:checkbox.all :label="__('All systems')"/>
                @foreach ($this->systems as $system)
                    <flux:checkbox :value="$system->id" :label="$system->name" class="ml-6"/>
                @endforeach
            </flux:checkbox.group>
        </flux:card>

        @if ($this->earlierRequests->isNotEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>
                    {{ trans_choice('This person has :count earlier request.|This person has :count earlier requests.', $this->earlierRequests->count()) }}
                </flux:callout.heading>

                <flux:callout.text>
                    <ul class="list-disc ps-4">
                        @foreach ($this->earlierRequests as $earlier)
                            <li>
                                <strong>
                                    <flux:link :href="route('requests.show', $earlier)" target="_blank">{{ $earlier->reference }}</flux:link>
                                </strong>
                                ({{ $earlier->type->label() }}, {{ strtolower($earlier->status->label()) }}),
                                {{ __('received on') }}
                                <x-local-date :date="$earlier->received_at" />
                            </li>
                        @endforeach
                    </ul>
                </flux:callout.text>

                <x-slot name="actions" class="w-full justify-end pr-2">
                    <div x-data="duplicateConfirmation">
                        <flux:button type="submit" x-on:click="confirmDuplicates" variant="primary">
                            {{ __('Create anyway') }}
                        </flux:button>
                    </div>
                </x-slot>
            </flux:callout>
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" icon="x-circle" :heading="__('Some fields need your attention above.')" />
        @endif

        @if ($this->earlierRequests->isEmpty())
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">
                    {{ __('Create request') }}
                </flux:button>
            </div>
        @endif
    </form>
</div>
