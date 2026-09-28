<?php

declare(strict_types=1);

return [
    'require_two_factor' => (bool) env('PRIVACY_REQUIRE_TWO_FACTOR', true),
    'admin_email' => env('ADMIN_EMAIL'),
    'admin_name' => env('ADMIN_NAME') ?: 'Admin',
    'subject_hmac_keys' => array_reduce(array_map(
        fn (string $key) => explode(':', trim($key), 2),
        explode(',', trim((string) env('SUBJECT_HMAC_KEYS', '')))
    ), function ($carry, $keyParts) {
        if (count($keyParts) > 1) {
            $carry[$keyParts[0]] = $keyParts[1];
        }

        return $carry;
    }, []),
    'subject_hmac_current' => trim((string) env('SUBJECT_HMAC_CURRENT', '')),
    'timezone' => env('PRIVACY_TIMEZONE', 'UTC'),
    'deadline_warning_days' => (int) env('PRIVACY_DEADLINE_WARNING_DAYS', 7),
    'retention_months' => (int) env('PRIVACY_RETENTION_MONTHS', 24),
    'notifications' => [
        'mail' => (bool) env('PRIVACY_NOTIFY_MAIL', false),
        'discord_webhook_url' => env('PRIVACY_DISCORD_WEBHOOK_URL'),
    ],
];
