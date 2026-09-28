<?php

declare(strict_types=1);

namespace App\Enums;

enum DeadlineStatus: string
{
    private const STATUS_CONCERNING = [self::Overdue, self::Soon];

    case Overdue = 'overdue';
    case Soon = 'soon';
    case OnTrack = 'on_track';

    public function label(): string
    {
        return match ($this) {
            self::Overdue => __('Overdue'),
            self::Soon => __('Soon'),
            self::OnTrack => __('On Track'),
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Overdue => 'red',
            self::Soon => 'amber',
            self::OnTrack => 'zinc',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Overdue => '🔴',
            self::Soon => '🟠',
            self::OnTrack => '🟢',
        };
    }

    public function isConcerning(): bool
    {
        return in_array($this, self::STATUS_CONCERNING, true);
    }
}
