<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeadlineStatus;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;

class DeadlineCalculator
{
    public function __construct(
        #[Config('privacy.timezone')] private readonly string $timezone,
        #[Config('privacy.deadline_warning_days')] private readonly int $deadlineWarningDays,
    ) {}

    public function legalDeadline(CarbonImmutable $receivedAt): CarbonImmutable
    {
        return $this->inOrganizationTimezone($receivedAt)->addMonthNoOverflow()->endOfDay()->utc();
    }

    public function systemDeadline(CarbonImmutable $receivedAt, ?int $responsePeriodDays): CarbonImmutable
    {
        $legalDeadline = $this->legalDeadline($receivedAt);

        if ($responsePeriodDays === null) {
            return $legalDeadline;
        }

        return $this->inOrganizationTimezone($receivedAt)->addDays($responsePeriodDays)->endOfDay()->utc()->min($legalDeadline);
    }

    public function maxExtension(CarbonImmutable $receivedAt): CarbonImmutable
    {
        return $this->inOrganizationTimezone($receivedAt)->addMonthsNoOverflow(3)->endOfDay()->utc();
    }

    public function isExtensionNoticeLate(CarbonImmutable $receivedAt, CarbonImmutable $now): bool
    {
        return $this->legalDeadline($receivedAt)->lt($now);
    }

    public function status(CarbonImmutable $deadline, CarbonImmutable $now): DeadlineStatus
    {
        if ($now->greaterThan($deadline)) {
            return DeadlineStatus::Overdue;
        }

        return $deadline->diff($now)->days <= $this->deadlineWarningDays
            ? DeadlineStatus::Soon
            : DeadlineStatus::OnTrack;
    }

    private function inOrganizationTimezone(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone($this->timezone);
    }
}
