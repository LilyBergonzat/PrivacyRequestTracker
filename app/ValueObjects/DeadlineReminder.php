<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\DeadlineStatus;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Services\DeadlineCalculator;
use Carbon\CarbonImmutable;

final readonly class DeadlineReminder
{
    public function __construct(
        public string $reference,
        public RequestType $type,
        public CarbonImmutable $deadline,
        public DeadlineStatus $status,
        public string $url,
    ) {}

    public static function fromRequest(PrivacyRequest $request, CarbonImmutable $now): DeadlineReminder
    {
        return new DeadlineReminder(
            $request->reference,
            $request->type,
            $request->effectiveDeadline(),
            app(DeadlineCalculator::class)->status($request->effectiveDeadline(), $now),
            route('requests.show', $request),
        );
    }

    public function summary(): string
    {
        return implode(' · ', [
            $this->reference,
            $this->type->label(),
            __(
                $this->status === DeadlineStatus::Overdue
                    ? 'overdue since :date'
                    : 'due :date',
                ['date' => $this->deadline->timezone(config('privacy.timezone'))->isoFormat('LL')]
            ),
        ]);
    }
}
