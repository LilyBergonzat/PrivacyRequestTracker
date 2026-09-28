<?php

use App\Livewire\Forms\SystemForm;
use App\Models\System;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Systems')]
class extends Component {
    public SystemForm $form;

    public bool $saved = false;

    /**
     * @return Collection<int, System>
     */
    #[Computed]
    public function systems(): Collection
    {
        return System::ordered()->get();
    }

    public function createSystem(): void
    {
        $this->authorize('admin');
        $this->form->store();

        $this->saved = true;
    }

    public function closeCreateModal(): void
    {
        $this->form->reset();
        $this->resetValidation();
        $this->saved = false;
    }

    public function sortSystem(int $systemId, int $position): void {
        $this->authorize('admin');

        abort_unless($position >= 0, 400);
        abort_unless(System::where('id', $systemId)->exists(), 404);

        DB::transaction(function () use ($systemId, $position): void {
            $ids = System::ordered()->pluck('id')->reject($systemId)->values();
            $ids->splice($position, 0, [$systemId]);

            foreach ($ids as $index => $id) {
                /** @noinspection LaravelEloquentGuardedAttributeAssignmentInspection */
                System::whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }
};
?>

<div class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Systems') }}</flux:heading>

    <flux:modal.trigger name="new-system">
        <flux:button>{{ __('New system') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-system" class="md:w-96" wire:close="closeCreateModal">
        @if (!$saved)
            <form wire:submit="createSystem" class="space-y-6">
                <flux:heading size="lg">{{ __('New system') }}</flux:heading>

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

                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </form>
        @else
            <div class="space-y-6">
                <flux:heading size="lg" level="2">{{ __('System created') }}</flux:heading>

                <flux:callout variant="success">
                    {{ __('The system was created successfully!') }}
                </flux:callout>

                <flux:modal.close>
                    <flux:button>{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        @endif
    </flux:modal>

    <table class="w-full text-left text-sm">
        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
        <tr>
            <th class="py-2 pe-4 font-medium"></th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Name') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Response time') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Status') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Actions') }}
            </th>
        </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800" wire:sort="sortSystem">
        <?php /** @var System $system */ ?>
        @forelse($this->systems as $system)
            <tr wire:sort:item="{{ $system->id }}" class="cursor-grab">
                <td class="py-2 pe-4">
                    <flux:icon.bars-2 variant="micro" class="text-zinc-400" />
                </td>
                <td class="py-2 pe-4">
                    {{ $system->name }}
                    <flux:text size="sm">{{ $system->slug }}</flux:text>
                </td>
                <td class="py-2 pe-4">
                    {{
                        $system->response_period_days !== null
                            ? trans_choice(':count day|:count days', $system->response_period_days)
                            : __('1 month')
                    }}

                    @if ($system->response_period_days === null)
                        <br />
                        <flux:text size="sm" class="inline">{{ __('GDPR default') }}</flux:text>
                    @endif
                </td>
                <td class="py-2 pe-4">
                    @if ($system->is_active)
                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                    @endif
                </td>
                <td>
                    <flux:button size="sm" :href="route('admin.systems.show', $system)" wire:navigate wire:sort:ignore>{{ __('Manage') }}</flux:button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="py-10 pe-4 text-center text-4xl font-bold italic opacity-25">
                    {{ __('No systems yet') }}
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
