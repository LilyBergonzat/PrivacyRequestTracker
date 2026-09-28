<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ContactMethod;
use App\Enums\RequestChannel;
use App\Enums\RequestType;
use App\Models\System;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class NewPrivacyRequest
{
    /**
     * @param  list<SubjectIdentifier>  $identifiers
     * @param  list<System>  $systems
     */
    public function __construct(
        public RequestType $type,
        public RequestChannel $channel,
        public CarbonImmutable $receivedAt,
        public ContactMethod $contactMethod,
        public ?string $contactDetails,
        public ?string $notes,
        public array $identifiers,
        public array $systems,
    ) {
        if (empty($this->identifiers) || empty($this->systems)) {
            throw new InvalidArgumentException('You have to specify at least one identifier and at least one system');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'type' => $this->type,
            'channel' => $this->channel,
            'received_at' => $this->receivedAt,
            'contact_method' => $this->contactMethod,
            'contact_details' => $this->contactDetails,
            'notes' => $this->notes,
        ];
    }
}
