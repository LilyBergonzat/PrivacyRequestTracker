<?php

declare(strict_types=1);

namespace App\ValueObjects;

use SensitiveParameter;

final readonly class HashedIdentifier
{
    public function __construct(
        public string $version,
        #[SensitiveParameter] public string $hmac,
    ) {}
}
