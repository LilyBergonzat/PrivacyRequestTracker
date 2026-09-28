<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;
use SensitiveParameter;

enum IdentifierType: string
{
    case DiscordId = 'discord_id';
    case Email = 'email';
    case Username = 'username';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DiscordId => __('Discord ID'),
            self::Email => __('Email'),
            self::Username => __('Username'),
            self::Other => __('Other'),
        };
    }

    public function normalize(#[SensitiveParameter] string $value): string
    {
        $formattedValue = match ($this) {
            self::DiscordId => str_replace(['<', '>', '@', '!'], '', trim($value)),
            self::Email => mb_strtolower(trim($value)),
            self::Username => mb_strtolower(ltrim(trim($value), '@')),
            self::Other => trim($value),
        };

        if ($formattedValue === '') {
            throw new InvalidArgumentException('Malformed identifier');
        }

        if ($this === self::DiscordId && !preg_match('/^\d{17,20}$/', $formattedValue)) {
            throw new InvalidArgumentException('Malformed Discord snowflake');
        }

        return $formattedValue;
    }
}
