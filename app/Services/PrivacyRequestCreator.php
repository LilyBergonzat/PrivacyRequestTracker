<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\User;
use App\ValueObjects\NewPrivacyRequest;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrivacyRequestCreator
{
    public function __construct(
        private readonly SubjectHasher $hasher,
        private readonly DeadlineCalculator $calculator,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function create(NewPrivacyRequest $data, User $creator): PrivacyRequest
    {
        return DB::transaction(function () use ($data, $creator): PrivacyRequest {
            $privacyRequest = new PrivacyRequest($data->getData());

            $privacyRequest->created_by = $creator->id;
            $privacyRequest->due_at = $this->calculator->legalDeadline($data->receivedAt);

            $privacyRequest->save();

            $hashedIdentifiers = array_reduce(
                $data->identifiers,
                function (array $carry, SubjectIdentifier $identifier) {
                    $hashedIdentifier = $this->hasher->hash($identifier);

                    $carry[$identifier->prefix() . ':' . $hashedIdentifier->hmac] = [
                        'identifier_type' => $identifier->prefix(),
                        'hmac' => $hashedIdentifier->hmac,
                        'key_version' => $hashedIdentifier->version,
                    ];

                    return $carry;
                },
                []
            );

            foreach ($hashedIdentifiers as $row) {
                $privacyRequest->identifiers()->create($row);
            }

            foreach ($data->systems as $system) {
                $requestSystem = new RequestSystem;

                $requestSystem->system_id = $system->id;
                $requestSystem->due_at = $this->calculator->systemDeadline($data->receivedAt, $system->response_period_days);

                $privacyRequest->requestSystems()->save($requestSystem);
            }

            $this->logger->log(ActivityAction::RequestCreated, $privacyRequest, [
                'identifiers' => count($hashedIdentifiers),
                'systems' => count($data->systems),
            ]);

            return $privacyRequest;
        });
    }
}
