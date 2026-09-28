<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
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
            'user_id' => User::factory(),
            'action' => ActivityAction::RequestCreated,
            'metadata' => [],
        ];
    }
}
