<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestSystemStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\ActivityLogger;

beforeEach(function () {
    $this->logger = app(ActivityLogger::class);
});

describe('logging', function () {
    test('an action is logged against a request by the logged in user', function () {
        $user = User::factory()->create();
        $request = PrivacyRequest::factory()->create();
        $this->actingAs($user);

        $activity = $this->logger->log(ActivityAction::RequestCreated, $request);

        expect($activity->exists)->toBeTrue()
            ->and($activity->action)->toBe(ActivityAction::RequestCreated)
            ->and($activity->privacyRequest->is($request))->toBeTrue()
            ->and($activity->user->is($user))->toBeTrue()
            ->and($activity->refresh()->created_at)->not->toBeNull();
    });

    test('actions without a logged in user are attributed to the system', function () {
        $activity = $this->logger->log(ActivityAction::ContactPurged, PrivacyRequest::factory()->create());

        expect($activity->user_id)->toBeNull();
    });

    test('actions can be logged without a request', function () {
        $this->actingAs(User::factory()->create());

        $activity = $this->logger->log(ActivityAction::LookupPerformed, metadata: ['matches' => 2]);

        expect($activity->privacy_request_id)->toBeNull()
            ->and($activity->refresh()->metadata)->toBe(['matches' => 2]);
    });

    test('a request lists its activities', function () {
        $request = PrivacyRequest::factory()->create();

        $this->logger->log(ActivityAction::RequestCreated, $request);
        $this->logger->log(ActivityAction::IdentityVerified, $request);
        $this->logger->log(ActivityAction::RequestCreated, PrivacyRequest::factory()->create());

        expect($request->activities->pluck('action')->all())
            ->toBe([ActivityAction::RequestCreated, ActivityAction::IdentityVerified]);
    });

    test('every action has a label', function (ActivityAction $action) {
        expect($action->label())->toBeString()->not->toBeEmpty();
    })->with(ActivityAction::cases());
});

describe('metadata', function () {
    test('scalar values, references and enums are accepted', function () {
        $activity = $this->logger->log(ActivityAction::SystemResolved, PrivacyRequest::factory()->create(), [
            'system_id' => 3,
            'reference' => 'DSR-7K3QX9',
            'status' => RequestSystemStatus::NoDataFound,
            'first_time' => true,
            'previous' => null,
        ]);

        expect($activity->refresh()->metadata)->toBe([
            'system_id' => 3,
            'reference' => 'DSR-7K3QX9',
            'status' => 'no_data_found',
            'first_time' => true,
            'previous' => null,
        ]);
    });

    test('a 64 character string is still accepted', function () {
        $activity = $this->logger->log(ActivityAction::RequestCreated, metadata: ['key' => str_repeat('a', 64)]);

        expect($activity->metadata['key'])->toHaveLength(64);
    });

    test('free text and identifiers are refused and nothing is logged', function (mixed $value) {
        expect(fn () => $this->logger->log(ActivityAction::SystemResolved, metadata: ['value' => $value]))
            ->toThrow(InvalidArgumentException::class)
            ->and(Activity::count())->toBe(0);
    })->with([
        'sentence' => 'Deleted 4 threads',
        'email' => 'someone@example.com',
        'discord mention' => '<@123456789012345678>',
        'tab' => "a\tb",
        'newline' => "a\nb",
        'too long' => str_repeat('a', 65),
        'array' => [['nested' => 'value']],
        'object' => new stdClass,
        'float' => 1.5,
    ]);

    test('one bad value is enough to refuse the whole entry', function () {
        expect(fn () => $this->logger->log(ActivityAction::SystemResolved, metadata: [
            'system_id' => 3,
            'summary' => 'Deleted 4 thread logs and 2 notes',
        ]))->toThrow(InvalidArgumentException::class);

        expect(Activity::count())->toBe(0);
    });
});

describe('append only', function () {
    test('an entry cannot be updated', function () {
        $activity = Activity::factory()->create();

        expect(fn () => $activity->update(['action' => ActivityAction::RequestClosed]))
            ->toThrow(LogicException::class)
            ->and($activity->refresh()->action)->toBe(ActivityAction::RequestCreated);
    });

    test('an entry cannot be deleted through the model', function () {
        $activity = Activity::factory()->create();

        expect(fn () => $activity->delete())->toThrow(LogicException::class)
            ->and(Activity::count())->toBe(1);
    });

    test('entries can still be deleted in bulk, as the purge will do', function () {
        $request = PrivacyRequest::factory()->create();
        Activity::factory()->for($request)->count(3)->create();
        Activity::factory()->create();

        Activity::where('privacy_request_id', $request->id)->delete();

        expect(Activity::count())->toBe(1);
    });

    test('entries are deleted along with their request', function () {
        $activity = Activity::factory()->create();

        $activity->privacyRequest->delete();

        expect(Activity::count())->toBe(0);
    });

    test('there is no updated at column', function () {
        $activity = Activity::factory()->create();

        expect($activity->refresh()->getAttributes())->not->toHaveKey('updated_at');
    });
});
