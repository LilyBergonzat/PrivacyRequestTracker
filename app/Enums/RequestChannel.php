<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestChannel: string
{
    case Modmail = 'modmail';
    case DiscordDm = 'discord_dm';
    case Email = 'email';
    case Website = 'website';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Modmail => __('Modmail'),
            self::DiscordDm => __('Discord DM'),
            self::Email => __('Email'),
            self::Website => __('Website'),
            self::Other => __('Other'),
        };
    }
}
