<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Random\RandomException;

#[Signature('privacy:key:generate')]
#[Description('Generates and prints a new key')]
class PrivacyKeyGenerate extends Command
{
    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(): int
    {
        $newKey = bin2hex(random_bytes(32));
        $keyVersions = array_merge([0], array_map(
            fn ($key) => (int) substr((string) $key, 1),
            array_filter(
                array_keys(config('privacy.subject_hmac_keys')),
                fn ($key) => (bool) preg_match('/^v\d+$/', (string) $key)
            )
        ));

        $this->info('New key: v' . max($keyVersions) + 1 . ':' . $newKey);
        $this->info('New current: v' . max($keyVersions) + 1);

        return self::SUCCESS;
    }
}
