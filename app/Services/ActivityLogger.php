<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(ActivityAction $action, ?PrivacyRequest $request = null, array $metadata = []): Activity
    {
        foreach ($metadata as &$metadatum) {
            $isCorrectString = is_string($metadatum) && mb_strlen($metadatum) < 65 && !preg_match('#[\s@]#', $metadatum);

            if ($isCorrectString || is_int($metadatum) || is_bool($metadatum) || is_null($metadatum)) {
                continue;
            }

            if ($metadatum instanceof BackedEnum) {
                $metadatum = $metadatum->value;

                continue;
            }

            throw new InvalidArgumentException('Invalid activity metadata');
        }

        unset($metadatum);

        $user = Auth::user();
        $activity = new Activity;

        $activity->privacy_request_id = $request?->id;
        $activity->user_id = $user instanceof User ? $user->id : null;
        $activity->action = $action;
        $activity->metadata = $metadata;

        $activity->save();

        return $activity;
    }
}
