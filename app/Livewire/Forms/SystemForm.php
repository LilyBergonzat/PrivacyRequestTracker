<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\System;
use Livewire\Form;
use LogicException;

class SystemForm extends Form
{
    public string $name = '';

    public ?string $description = null;

    public ?int $response_period_days = null;

    public bool $is_active = true;

    public ?System $system = null;

    public function setSystem(System $system): SystemForm
    {
        $this->system = $system;

        $this->name = $system->name;
        $this->description = $system->description;
        $this->response_period_days = $system->response_period_days;
        $this->is_active = $system->is_active;

        return $this;
    }

    public function store(): System
    {
        $validated = $this->validate();
        $system = System::create($validated);

        $this->reset();

        return $system;
    }

    public function update(): SystemForm
    {
        if ($this->system === null) {
            throw new LogicException('No system to update.');
        }

        $validated = $this->validate();
        $this->system->fill($validated)->save();

        return $this;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['string', 'nullable'],
            'response_period_days' => ['integer', 'min:1', 'max:31', 'nullable'],
            'is_active' => ['boolean'],
        ];
    }
}
