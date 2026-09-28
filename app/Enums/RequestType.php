<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestType: string
{
    case Access = 'access';
    case Deletion = 'deletion';
    case Correction = 'correction';
    case Objection = 'objection';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Access => __('Access'),
            self::Deletion => __('Deletion'),
            self::Correction => __('Correction'),
            self::Objection => __('Objection'),
            self::Other => __('Other'),
        };
    }
}
