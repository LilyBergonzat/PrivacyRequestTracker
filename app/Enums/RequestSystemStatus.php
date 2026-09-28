<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestSystemStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case NoDataFound = 'no_data_found';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Done => __('Done'),
            self::NoDataFound => __('No Data Found'),
            self::NotApplicable => __('Not Applicable'),
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Done => 'green',
            self::NoDataFound => 'blue',
            self::NotApplicable => 'zinc',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
