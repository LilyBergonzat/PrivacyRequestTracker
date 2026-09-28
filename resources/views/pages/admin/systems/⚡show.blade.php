<?php

use App\Enums\RequestType;
use App\Livewire\Forms\StepForm;
use App\Livewire\Forms\SystemForm;
use App\Models\System;
use App\Models\SystemStep;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;

new #[Title('System')]
class extends Component {
    public SystemForm $form;
    public StepForm $stepForm;

    #[Locked]
    public System $system;

    /**
     * @return Collection<int, SystemStep>
     */
    #[Computed]
    public function steps(): Collection
    {
        return $this->system->steps()->ordered()->get();
    }

    public function mount(System $system): void
    {
        $this->form->setSystem($system);
    }

    public function save(): void
    {
        $this->authorize('admin');
        $this->form->update();
        Flux::toast(variant: 'success', text: __('The system was saved successfully!'));
    }

    public function newStep(): void
    {
        $this->stepForm->reset();
        $this->stepForm->resetValidation();

        Flux::modal('step-form')->show();
    }

    public function editStep(int $stepId): void
    {
        $this->stepForm->resetValidation();
        $step = $this->system->steps()->findOrFail($stepId);
        $this->stepForm->setStep($step);

        Flux::modal('step-form')->show();
    }

    public function saveStep(): void
    {
        $this->authorize('admin');

        if ($this->stepForm->step !== null) {
            $this->stepForm->update();
        } else {
            $this->stepForm->store($this->system);
        }

        Flux::modal('step-form')->close();
        Flux::toast(variant: 'success', text: __('The step was saved successfully!'));
    }

    public function deleteStep(int $stepId): void
    {
        $this->authorize('admin');

        $step = $this->system->steps()->findOrFail($stepId);

        $step->delete();

        Flux::toast(variant: 'success', text: __('The step was deleted successfully!'));
    }

    public function sortStep(int $stepId, int $position): void
    {
        $this->authorize('admin');

        abort_unless($position >= 0, 400);
        abort_unless($this->system->steps()->where('id', $stepId)->exists(), 404);

        DB::transaction(function () use ($stepId, $position): void {
            $ids = $this->system->steps()->ordered()->pluck('id')->reject($stepId)->values();
            $ids->splice($position, 0, [$stepId]);

            foreach ($ids as $index => $id) {
                $this->system->steps()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }
};
?>

<div class="space-y-6 max-w-2xl mx-auto">
    <flux:heading size="xl" level="1" class="mb-0">
        {{ __('System: :name', ['name' => $form->system->name]) }}
    </flux:heading>
    <flux:text size="sm">{{ $form->system->slug }}</flux:text>

    @if ($form->system->description)
        <x-markdown :content="$form->system->description" />
    @endif

    <flux:button variant="ghost" icon="arrow-left" :href="route('admin.systems.index')" wire:navigate>
        {{ __('Back to systems') }}
    </flux:button>

    <flux:modal name="step-form" class="md:w-160">
        <form wire:submit="saveStep" class="space-y-6">
            <flux:heading size="lg">
                @if ($stepForm->step === null)
                    {{ __('New step') }}
                @else
                    {{ __('Edit step') }}
                @endif
            </flux:heading>

            <flux:select wire:model="stepForm.request_type" :label="__('Request type')">
                <flux:select.option value="">{{ __('All types') }}</flux:select.option>

                <?php /** @var RequestType $option */ ?>
                @foreach(RequestType::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="stepForm.title" :label="__('Title')" required/>
            <flux:textarea
                wire:model="stepForm.instructions"
                rows="10"
                :label="__('Instructions')"
                class="font-mono"
                wire:model.live.debounce.500ms="stepForm.instructions"
                required
            />
            <flux:text
                size="sm">{{ __('Don\'t paste real identifiers or personal data here, use placeholders instead.') }}</flux:text>

            <div class="p-4 rounded-lg bg-white/50 dark:bg-white/5">
                <x-markdown :content="$stepForm->instructions" />
            </div>

            <flux:switch wire:model="stepForm.is_active" :label="__('Active')"/>

            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </form>
    </flux:modal>

    <form wire:submit="save" class="space-y-6">
        <flux:heading size="lg">{{ __('Edit system') }}</flux:heading>

        <flux:input wire:model="form.name" :label="__('Name')" required/>
        <flux:textarea wire:model="form.description" :label="__('Description')"/>

        <flux:input
            wire:model="form.response_period_days"
            type="number"
            min="1"
            step="1"
            :placeholder="__('Default: 1 month')"
            :label="__('Response required under')"
            :label:trailing="__('day(s)')"
        />

        <flux:switch wire:model="form.is_active" :label="__('Active')"
                     :description="__('Inactive systems are hidden from new requests but keep their history.')"/>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>


    <flux:heading size="lg">{{ __('Steps') }}</flux:heading>

    <flux:button wire:click="newStep">{{ __('New step') }}</flux:button>

    <table class="w-full text-left text-sm">
        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
        <tr>
            <th class="py-2 pe-4 font-medium"></th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Title') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Type') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Status') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Actions') }}
            </th>
        </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800" wire:sort="sortStep">
        <?php /** @var SystemStep $step */ ?>
        @forelse($this->steps as $step)
            <tr wire:sort:item="{{ $step->id }}" class="cursor-grab">
                <td class="py-2 pe-4">
                    <flux:icon.bars-2 variant="micro" class="text-zinc-400"/>
                </td>
                <td class="py-2 pe-4">
                    <details wire:sort:ignore>
                        <summary class="cursor-pointer">{{ $loop->iteration }}. {{ $step->title }}</summary>
                        <x-markdown :content="$step->instructions" class="mt-2" />
                    </details>
                </td>
                <td class="py-2 pe-4">
                    <flux:badge size="sm" color="zinc">
                        {{ $step->request_type?->label() ?? __('All types') }}
                    </flux:badge>
                </td>
                <td class="py-2 pe-4">
                    @if ($step->is_active)
                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                    @endif
                </td>
                <td>
                    <flux:dropdown>
                        <flux:button size="sm" icon="ellipsis-horizontal" variant="ghost" wire:sort:ignore/>
                        <flux:menu>
                            <flux:menu.item icon="pencil"
                                            wire:click="editStep({{ $step->id }})">
                                {{ __('Edit') }}
                            </flux:menu.item>
                            <flux:menu.item icon="trash" variant="danger"
                                            wire:click="deleteStep({{ $step->id }})"
                                            wire:confirm="{{ __('Delete step :title?', ['title' => $step->title]) }}">
                                {{ __('Delete') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="py-10 pe-4 text-center text-4xl font-bold italic opacity-25">
                    {{ __('No steps yet') }}
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
