<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\HashedIdentifier;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;
use SensitiveParameter;

class SubjectHasher
{
    /**
     * @param  array<string, string>  $keys
     */
    public function __construct(
        #[SensitiveParameter, Config('privacy.subject_hmac_keys')] private readonly array $keys,
        #[SensitiveParameter, Config('privacy.subject_hmac_current')] private readonly string $currentVersion,
    ) {
        if (!$this->keys || !array_key_exists($this->currentVersion, $this->keys)) {
            throw new InvalidArgumentException('Invalid HMAC configuration detected');
        }
    }

    public function hash(SubjectIdentifier $id): HashedIdentifier
    {
        return $this->hashWithVersion($id, $this->currentVersion);
    }

    /**
     * @return list<HashedIdentifier>
     */
    public function candidates(SubjectIdentifier $id): array
    {
        $candidates = [];

        foreach (array_keys($this->keys) as $version) {
            $candidates[] = $this->hashWithVersion($id, $version);
        }

        return $candidates;
    }

    private function hashWithVersion(SubjectIdentifier $id, string $version): HashedIdentifier
    {
        if (!array_key_exists($version, $this->keys)) {
            throw new InvalidArgumentException('Invalid HMAC key version');
        }

        $key = $this->keys[$version];

        return new HashedIdentifier(
            $version,
            hash_hmac('sha256', $id->prefix() . ':' . $id->value, $key)
        );
    }
}
