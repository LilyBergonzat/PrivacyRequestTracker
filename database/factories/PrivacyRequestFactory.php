<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactMethod;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => RequestType::Deletion,
            'channel' => RequestChannel::Modmail,
            'received_at' => now(),
            'due_at' => now()->addMonth(),
            'contact_method' => ContactMethod::None,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the model is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequestStatus::Completed,
            'closed_at' => now(),
        ]);
    }
}
