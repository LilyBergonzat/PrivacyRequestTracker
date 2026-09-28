<?php

declare(strict_types=1);

use App\Enums\ContactMethod;
use App\Enums\DeadlineStatus;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const REQUEST_ENCRYPTED_COLUMNS = [
    'extension_reason',
    'identity_verification_notes',
    'rejection_reason',
    'notes',
    'contact_details',
];

describe('reference', function () {
    test('a reference is generated with an unambiguous alphabet', function () {
        $request = PrivacyRequest::factory()->create();

        expect($request->reference)->toMatch('/^DSR-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/');
    });

    test('generated references are unique', function () {
        $references = PrivacyRequest::factory()->count(50)->create()->pluck('reference');

        expect($references->unique())->toHaveCount(50);
    });

    test('an explicit reference is kept', function () {
        $request = PrivacyRequest::factory()->create(['reference' => 'DSR-TEST22']);

        expect($request->reference)->toBe('DSR-TEST22');
    });

    test('the reference cannot be mass assigned', function () {
        $request = PrivacyRequest::factory()->create();
        $reference = $request->reference;

        $request->fill(['reference' => 'DSR-HACKED'])->save();

        expect($request->refresh()->reference)->toBe($reference);
    });
});

describe('encryption', function () {
    test('free text columns are encrypted in the database and readable through the model', function (string $column) {
        $request = PrivacyRequest::factory()->create();
        $request->forceFill([$column => 'Secret note about someone@example.com'])->save();

        $raw = DB::table('privacy_requests')->where('id', $request->id)->value($column);

        expect($raw)->toBeString()
            ->not->toContain('Secret note')
            ->not->toContain('someone@example.com')
            ->and($request->refresh()->{$column})->toBe('Secret note about someone@example.com');
    })->with(REQUEST_ENCRYPTED_COLUMNS);

    test('long free text survives the round trip', function () {
        $longText = str_repeat('A long note with accents: éàü. ', 200);

        $request = PrivacyRequest::factory()->create();
        $request->forceFill(['notes' => $longText])->save();

        expect($request->refresh()->notes)->toBe($longText);
    });

    test('empty free text columns stay null', function () {
        $request = PrivacyRequest::factory()->create();

        foreach (REQUEST_ENCRYPTED_COLUMNS as $column) {
            expect(DB::table('privacy_requests')->where('id', $request->id)->value($column))->toBeNull();
        }
    });
});

describe('defaults and casts', function () {
    test('a new request is received, in memory and in the database', function () {
        $request = PrivacyRequest::factory()->create();

        expect($request->status)->toBe(RequestStatus::Received)
            ->and($request->refresh()->status)->toBe(RequestStatus::Received);
    });

    test('enums and dates are cast', function () {
        $request = PrivacyRequest::factory()->create([
            'type' => RequestType::Access,
            'channel' => RequestChannel::Email,
            'contact_method' => ContactMethod::Discord,
            'received_at' => '2026-01-31 10:00:00',
        ])->refresh();

        expect($request->type)->toBe(RequestType::Access)
            ->and($request->channel)->toBe(RequestChannel::Email)
            ->and($request->contact_method)->toBe(ContactMethod::Discord)
            ->and($request->received_at->toDateTimeString())->toBe('2026-01-31 10:00:00')
            ->and($request->due_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($request->closed_at)->toBeNull()
            ->and($request->purge_after)->toBeNull();
    });

    test('status, deadlines and creator cannot be mass assigned', function () {
        $request = PrivacyRequest::factory()->create();
        $otherUser = User::factory()->create();
        $before = $request->only(['status', 'due_at', 'closed_at', 'created_by']);

        $request->fill([
            'status' => RequestStatus::Completed,
            'due_at' => now()->addYear(),
            'closed_at' => now(),
            'created_by' => $otherUser->id,
        ])->save();

        expect($request->refresh()->only(['status', 'due_at', 'closed_at', 'created_by']))->toEqual($before);
    });
});

describe('creator', function () {
    test('a request belongs to the staff member who created it', function () {
        $user = User::factory()->create();

        $request = PrivacyRequest::factory()->for($user, 'creator')->create();

        expect($request->creator->is($user))->toBeTrue();
    });

    test('a user who created requests cannot be deleted', function () {
        $request = PrivacyRequest::factory()->create();

        expect(fn () => $request->creator->delete())->toThrow(QueryException::class)
            ->and(User::find($request->created_by))->not->toBeNull();
    });
});

describe('statuses', function () {
    test('only completed and rejected requests are closed', function (RequestStatus $status, bool $closed) {
        expect($status->isClosed())->toBe($closed);
    })->with([
        [RequestStatus::Received, false],
        [RequestStatus::VerifyingIdentity, false],
        [RequestStatus::InProgress, false],
        [RequestStatus::AwaitingNotification, false],
        [RequestStatus::Completed, true],
        [RequestStatus::Rejected, true],
    ]);

    test('the closed factory state gives a closed request', function () {
        $request = PrivacyRequest::factory()->closed()->create();

        expect($request->status->isClosed())->toBeTrue()
            ->and($request->closed_at)->not->toBeNull();
    });

    test('every enum case has a label', function (UnitEnum $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    })->with([
        ...RequestStatus::cases(),
        ...RequestChannel::cases(),
        ...ContactMethod::cases(),
        ...DeadlineStatus::cases(),
    ]);
});

describe('effective deadline', function () {
    beforeEach(function () {
        $this->request = PrivacyRequest::factory()->create(['due_at' => '2026-02-15 23:59:59']);
    });

    test('it is the earliest deadline among the systems still pending', function () {
        RequestSystem::factory()->for($this->request)->create(['due_at' => '2026-02-15 23:59:59']);
        RequestSystem::factory()->for($this->request)->create(['due_at' => '2026-01-29 23:59:59']);
        RequestSystem::factory()->for($this->request)->resolved(RequestSystemStatus::Done)->create(['due_at' => '2026-01-20 23:59:59']);

        expect($this->request->refresh()->effectiveDeadline()->toDateTimeString())->toBe('2026-01-29 23:59:59');
    });

    test('once every system is resolved, the legal deadline remains for the notification', function () {
        RequestSystem::factory()->for($this->request)->resolved(RequestSystemStatus::NoDataFound)->create(['due_at' => '2026-01-29 23:59:59']);

        expect($this->request->refresh()->effectiveDeadline()->toDateTimeString())->toBe('2026-02-15 23:59:59');
    });

    test('an extension replaces every other deadline', function () {
        RequestSystem::factory()->for($this->request)->create(['due_at' => '2026-01-29 23:59:59']);
        $this->request->forceFill(['extended_until' => '2026-04-15 23:59:59'])->save();

        expect($this->request->refresh()->effectiveDeadline()->toDateTimeString())->toBe('2026-04-15 23:59:59');
    });
});
