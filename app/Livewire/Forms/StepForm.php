<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Enums\RequestType;
use App\Models\System;
use App\Models\SystemStep;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Form;
use LogicException;

class StepForm extends Form
{
    public ?string $request_type = null;

    public string $title = '';

    public string $instructions = '';

    public bool $is_active = true;

    public ?SystemStep $step = null;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'request_type' => ['nullable', Rule::enum(RequestType::class)],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:65535'],
            'is_active' => ['boolean'],
        ];
    }

    public function setStep(SystemStep $step): StepForm
    {
        $this->step = $step;

        $this->request_type = $step->request_type?->value;
        $this->title = $step->title;
        $this->instructions = $step->instructions;
        $this->is_active = $step->is_active;

        return $this;
    }

    public function store(System $system): SystemStep
    {
        $validated = $this->validate();
        $validated['request_type'] = $validated['request_type'] ?: null;
        $step = $system->steps()->create($validated);

        $this->reset();

        return $step;
    }

    public function update(): StepForm
    {
        if ($this->step === null) {
            throw new LogicException('No step to update.');
        }

        $validated = $this->validate();
        $validated['request_type'] = $validated['request_type'] ?: null;
        $this->step->fill($validated)->save();

        return $this;
    }
}
