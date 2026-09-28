<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactMethod: string
{
    case Email = 'email';
    case Discord = 'discord';
    case None = 'none';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Discord => __('Discord'),
            self::None => __('None'),
            self::Other => __('Other'),
        };
    }
}
