<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Handler = 'handler';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Handler => __('Handler'),
        };
    }
}
