<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Str;

new #[Title('Users')]
class extends Component {
    public string $name = '';
    public string $email = '';
    public string $role = 'handler';
    public ?string $generatedPassword = null;

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::orderBy('name')->get();
    }

    public function disable(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('disable', $user);

        $user->disabled_at = now();
        $user->save();
    }

    public function enable(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('enable', $user);

        $user->disabled_at = null;
        $user->save();
    }

    public function resetTwoFactor(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('resetTwoFactor', $user);

        app(DisableTwoFactorAuthentication::class)($user);
    }

    public function changeRole(int $userId, string $role): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('changeRole', $user);

        $user->role = UserRole::from($role);
        $user->save();
    }

    public function createUser(): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $user = new User;
        $password = Str::password();

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = $password;
        $user->role = UserRole::from($validated['role']);

        $user->save();

        $this->generatedPassword = $password;
        $this->reset('name', 'email', 'role');
    }

    public function closeCreateModal(): void
    {
        $this->generatedPassword = null;
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>

    <flux:modal.trigger name="new-user">
        <flux:button>{{ __('New user') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="new-user" class="md:w-96" wire:close="closeCreateModal">
        @if ($generatedPassword === null)
            <form wire:submit="createUser" class="space-y-6">
                <flux:heading size="lg">{{ __('New user') }}</flux:heading>

                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />

                <flux:select wire:model="role" :label="__('Role')">
                    @foreach(UserRole::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </form>
        @else
            <div class="space-y-6">
                <flux:heading size="lg" level="2">{{ __('User created') }}</flux:heading>

                <flux:callout variant="success">
                    {{ __('The user was created successfully!') }}
                </flux:callout>

                <flux:callout variant="warning">
                    {{ __('Auto-generated password (will only be shown once):') }}
                </flux:callout>
                <flux:input readonly copyable :value="$generatedPassword" />

                <flux:modal.close><flux:button>{{ __('Close') }}</flux:button></flux:modal.close>
            </div>
        @endif
    </flux:modal>

    <table class="w-full text-left text-sm">
        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
        <tr>
            <th class="py-2 pe-4 font-medium">
                {{ __('Name') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('E-mail') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Role') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Status') }}
            </th>
            <th class="py-2 pe-4 font-medium">
                {{ __('Actions') }}
            </th>
        </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
        @foreach($this->users as $user)
            <tr>
                <td class="py-2 pe-4">
                    {{ $user->name }}
                </td>
                <td class="py-2 pe-4">
                    {{ $user->email }}
                </td>
                <td class="py-2 pe-4">
                    <flux:badge size="sm"
                                :color="$user->isAdmin() ? 'violet' : 'zinc'">{{ $user->role->value }}</flux:badge>
                </td>
                <td class="py-2 pe-4">
                    @if ($user->isDisabled())
                        <flux:badge size="sm" color="red">{{ __('Disabled') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="green">{{ __('Enabled') }}</flux:badge>
                    @endif
                </td>
                <td>
                    @canany(['disable', 'enable', 'resetTwoFactor', 'changeRole'], $user)
                        <flux:dropdown>
                            <flux:button size="sm" icon="ellipsis-horizontal" variant="ghost"/>
                            <flux:menu>
                                @can('disable', $user)
                                    <flux:menu.item icon="lock-closed" variant="danger"
                                                    wire:click="disable({{ $user->id }})"
                                                    wire:confirm="{{ __('Disable :name?', ['name' => $user->name]) }}">
                                        {{ __('Disable') }}
                                    </flux:menu.item>
                                @endcan
                                @can('enable', $user)
                                    <flux:menu.item icon="lock-open"
                                                    wire:click="enable({{ $user->id }})"
                                                    wire:confirm="{{ __('Enable :name?', ['name' => $user->name]) }}">
                                        {{ __('Enable') }}
                                    </flux:menu.item>
                                @endcan
                                @can('resetTwoFactor', $user)
                                    <flux:menu.item icon="key"
                                                    wire:click="resetTwoFactor({{ $user->id }})"
                                                    wire:confirm="{{ __('Reset 2FA for :name?', ['name' => $user->name]) }}">
                                        {{ __('Reset 2FA') }}
                                    </flux:menu.item>
                                @endcan
                                @can('changeRole', $user)
                                    @if ($user->isAdmin())
                                        <flux:menu.item icon="user" wire:click="changeRole({{ $user->id }}, 'handler')"
                                                        wire:confirm="{{ __('Make :name handler?', ['name' => $user->name]) }}">
                                            {{ __('Make handler') }}
                                        </flux:menu.item>
                                    @else
                                        <flux:menu.item icon="shield-check"
                                                        wire:click="changeRole({{ $user->id }}, 'admin')"
                                                        wire:confirm="{{ __('Make :name admin?', ['name' => $user->name]) }}">
                                            {{ __('Make admin') }}
                                        </flux:menu.item>
                                    @endif
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    @endcanany
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
