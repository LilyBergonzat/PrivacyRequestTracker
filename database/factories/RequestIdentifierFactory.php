<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestIdentifier>
 */
class RequestIdentifierFactory extends Factory
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
            'identifier_type' => 'discord_id',
            'hmac' => hash('sha256', fake()->uuid()),
            'key_version' => 'v1',
        ];
    }
}
