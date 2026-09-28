<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\System;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<System>
 */
class SystemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'response_period_days' => null,
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
     * Indicate that the model requires a specific response period.
     */
    public function withResponsePeriod(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'response_period_days' => $days,
        ]);
    }
}
