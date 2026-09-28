<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeadlineStatus;
use App\Models\PrivacyRequest;
use App\ValueObjects\DeadlineReminder;
use Carbon\CarbonImmutable;

class DeadlineReminderCollector
{
    public function __construct(
        private readonly DeadlineCalculator $deadlineCalculator,
    ) {}

    /**
     * @return list<DeadlineReminder>
     */
    public function collect(CarbonImmutable $now): array
    {
        $requests = PrivacyRequest::open()->with('requestSystems')->get()->map(
            function (PrivacyRequest $row) use ($now): ?DeadlineReminder {
                return $this->deadlineCalculator->status($row->effectiveDeadline(), $now)->isConcerning()
                    ? DeadlineReminder::fromRequest($row, $now)
                    : null;
            }
        )->filter(fn (?DeadlineReminder $row): bool => $row !== null)->values()->all();

        $statusToInt = fn (DeadlineStatus $status): int => $status === DeadlineStatus::Overdue ? 1 : 0;
        $datesToCompare = fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->isBefore($b) ? 1 : ($a->isAfter($b) ? -1 : 0);
        usort($requests, function (DeadlineReminder $a, DeadlineReminder $b) use ($statusToInt, $datesToCompare) {
            $compareValue = $statusToInt($b->status) - $statusToInt($a->status);

            return $compareValue === 0 ? $datesToCompare($b->deadline, $a->deadline) : $compareValue;
        });

        return $requests;
    }
}
