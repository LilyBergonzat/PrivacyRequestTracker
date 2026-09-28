<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RequestType;
use App\Models\System;
use App\Models\SystemStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemStep>
 */
class SystemStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'system_id' => System::factory(),
            'title' => fake()->sentence(4),
            'instructions' => fake()->paragraph(),
            'request_type' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model is for a specific request type.
     */
    public function forType(RequestType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'request_type' => $type,
        ]);
    }
}
