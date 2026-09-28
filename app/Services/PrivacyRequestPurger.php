<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Models\PrivacyRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrivacyRequestPurger
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws Throwable
     */
    public function purgeLeftoverContactDetails(): int
    {
        $requests = $this->getRequestsWithLeftoverContactDetailsQuery()->get()->all();

        foreach ($requests as $request) {
            DB::transaction(function () use ($request): void {
                $request->contact_details = null;
                $request->save();

                $this->activityLogger->log(ActivityAction::ContactPurged, $request);
            });
        }

        return count($requests);
    }

    /**
     * @throws Throwable
     */
    public function purgeExpired(CarbonImmutable $today): int
    {
        $requests = $this->getExpiredRequestsQuery($today)->get()->all();

        foreach ($requests as $request) {
            DB::transaction(function () use ($request): void {
                $reference = $request->reference;
                $type = $request->type;

                $request->delete();

                $this->activityLogger->log(ActivityAction::RequestPurged, metadata: [
                    'reference' => $reference,
                    'type' => $type,
                ]);
            });
        }

        return count($requests);
    }

    public function countExpired(CarbonImmutable $today): int
    {
        return $this->getExpiredRequestsQuery($today)->count();
    }

    public function countLeftoverContactDetails(): int
    {
        return $this->getRequestsWithLeftoverContactDetailsQuery()->count();
    }

    /**
     * @return Builder<PrivacyRequest>
     */
    private function getExpiredRequestsQuery(CarbonImmutable $today): Builder
    {
        return PrivacyRequest::query()->whereDate(
            'purge_after',
            '<=',
            $today->toDateString()
        )->whereIn('status', [RequestStatus::Completed, RequestStatus::Rejected]);
    }

    /**
     * @return Builder<PrivacyRequest>
     */
    private function getRequestsWithLeftoverContactDetailsQuery(): Builder
    {
        return PrivacyRequest::query()->whereIn(
            'status',
            [RequestStatus::Completed, RequestStatus::Rejected]
        )->whereNotNull('contact_details');
    }
}
