<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RequestSystemStatus;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestSystem>
 */
class RequestSystemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'privacy_request_id' => PrivacyRequest::factory(),
            'system_id' => System::factory(),
            'due_at' => now()->addMonth(),
        ];
    }

    /**
     * Indicate that the model is resolved.
     */
    public function resolved(RequestSystemStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'completed_at' => now(),
            'completed_by' => User::factory(),
        ]);
    }
}
