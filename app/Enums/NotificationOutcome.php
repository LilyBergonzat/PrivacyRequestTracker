<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationOutcome: string
{
    case Sent = 'sent';
    case Unreachable = 'unreachable';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Sent'),
            self::Unreachable => __('Unreachable'),
            self::NotRequired => __('Not Required'),
        };
    }
}
