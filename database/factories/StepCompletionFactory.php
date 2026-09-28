<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RequestSystem;
use App\Models\StepCompletion;
use App\Models\SystemStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepCompletion>
 */
class StepCompletionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_system_id' => RequestSystem::factory(),
            'system_step_id' => SystemStep::factory(),
            'step_title' => fake()->sentence(3),
            'completed_by' => User::factory(),
            'completed_at' => now(),
        ];
    }
}
