<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\ContactMethod;
use App\Enums\IdentifierType;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Enums\RequestType;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\User;
use App\Services\DeadlineCalculator;
use App\Services\PrivacyRequestCreator;
use App\Services\SubjectHasher;
use App\ValueObjects\NewPrivacyRequest;
use App\ValueObjects\SubjectIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

const CREATOR_TEST_DISCORD_ID = '287654321098765432';
const CREATOR_TEST_EMAIL = 'requester.person@example.org';

/**
 * @param  list<SubjectIdentifier>|null  $identifiers
 * @param  list<System>|null  $systems
 */
function newRequestData(?array $identifiers = null, ?array $systems = null, ?CarbonImmutable $receivedAt = null): NewPrivacyRequest
{
    return new NewPrivacyRequest(
        type: RequestType::Deletion,
        channel: RequestChannel::Modmail,
        receivedAt: $receivedAt ?? CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC'),
        contactMethod: ContactMethod::Discord,
        contactDetails: 'Reach them on Discord as @requester.person',
        notes: 'Asked in Modmail thread, verified by voice',
        identifiers: $identifiers ?? [
            new SubjectIdentifier(IdentifierType::DiscordId, CREATOR_TEST_DISCORD_ID),
            new SubjectIdentifier(IdentifierType::Email, CREATOR_TEST_EMAIL),
        ],
        systems: $systems ?? [System::factory()->create()],
    );
}

beforeEach(function () {
    $this->creator = User::factory()->create();
    $this->actingAs($this->creator);
    $this->service = app(PrivacyRequestCreator::class);
});

describe('the request', function () {
    test('it is created with its details, its creator and the legal deadline', function () {
        $request = $this->service->create(newRequestData(), $this->creator)->refresh();

        expect($request)->toBeInstanceOf(PrivacyRequest::class)
            ->and($request->reference)->toStartWith('DSR-')
            ->and($request->status)->toBe(RequestStatus::Received)
            ->and($request->type)->toBe(RequestType::Deletion)
            ->and($request->channel)->toBe(RequestChannel::Modmail)
            ->and($request->contact_method)->toBe(ContactMethod::Discord)
            ->and($request->contact_details)->toBe('Reach them on Discord as @requester.person')
            ->and($request->notes)->toBe('Asked in Modmail thread, verified by voice')
            ->and($request->received_at->toDateTimeString())->toBe('2026-01-15 10:00:00')
            ->and($request->due_at->toDateTimeString())->toBe(app(DeadlineCalculator::class)->legalDeadline(CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC'))->toDateTimeString())
            ->and($request->creator->is($this->creator))->toBeTrue();
    });

    test('the creation is logged without personal data', function () {
        $request = $this->service->create(newRequestData(), $this->creator);

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::RequestCreated)
            ->and($activity->privacyRequest->is($request))->toBeTrue()
            ->and($activity->user->is($this->creator))->toBeTrue()
            ->and($activity->metadata)->toBe(['identifiers' => 2, 'systems' => 1]);
    });
});

describe('identifiers', function () {
    test('identifiers are stored as hashes with their type and key version', function () {
        $request = $this->service->create(newRequestData(), $this->creator);
        $expected = app(SubjectHasher::class)->hash(new SubjectIdentifier(IdentifierType::Email, CREATOR_TEST_EMAIL));

        $email = $request->identifiers()->where('identifier_type', 'email')->sole();

        expect($request->identifiers)->toHaveCount(2)
            ->and($email->hmac)->toBe($expected->hmac)
            ->and($email->key_version)->toBe($expected->version);
    });

    test('the same identifier typed twice is stored once', function () {
        $request = $this->service->create(newRequestData(identifiers: [
            new SubjectIdentifier(IdentifierType::Email, 'Someone@Example.com'),
            new SubjectIdentifier(IdentifierType::Email, ' someone@example.com '),
        ]), $this->creator);

        expect($request->identifiers)->toHaveCount(1)
            ->and(Activity::sole()->metadata['identifiers'])->toBe(1);
    });

    test('other identifiers keep their full label in the type', function () {
        $request = $this->service->create(newRequestData(identifiers: [
            new SubjectIdentifier(IdentifierType::Other, 'abc-123', 'Website Account ID'),
            new SubjectIdentifier(IdentifierType::Other, 'abc-123', 'Patreon ID'),
        ]), $this->creator);

        $expected = app(SubjectHasher::class)->hash(new SubjectIdentifier(IdentifierType::Other, 'abc-123', 'website_account_id'));

        expect($request->identifiers()->pluck('identifier_type')->sort()->values()->all())
            ->toBe(['other:patreon_id', 'other:website_account_id'])
            ->and($request->identifiers()->where('identifier_type', 'other:website_account_id')->sole()->hmac)
            ->toBe($expected->hmac);
    });

    test('the same value with different types is stored twice', function () {
        $request = $this->service->create(newRequestData(identifiers: [
            new SubjectIdentifier(IdentifierType::DiscordId, CREATOR_TEST_DISCORD_ID),
            new SubjectIdentifier(IdentifierType::Username, CREATOR_TEST_DISCORD_ID),
        ]), $this->creator);

        expect($request->identifiers)->toHaveCount(2);
    });
});

describe('systems', function () {
    test('each system gets a pending row with its own deadline', function () {
        $modmail = System::factory()->withResponsePeriod(14)->create();
        $website = System::factory()->create();

        $request = $this->service->create(newRequestData(systems: [$modmail, $website]), $this->creator);

        $rows = $request->requestSystems()->get()->keyBy('system_id');

        expect($rows)->toHaveCount(2)
            ->and($rows[$modmail->id]->status)->toBe(RequestSystemStatus::Pending)
            ->and($rows[$modmail->id]->due_at->toDateTimeString())->toBe('2026-01-29 23:59:59')
            ->and($rows[$website->id]->due_at->toDateTimeString())->toBe('2026-02-15 23:59:59');
    });
});

describe('safety', function () {
    test('a request needs at least one identifier and one system', function (bool $withIdentifiers, bool $withSystems) {
        newRequestData(
            identifiers: $withIdentifiers ? null : [],
            systems: $withSystems ? null : [],
        );
    })->throws(InvalidArgumentException::class)->with([
        'no identifiers' => [false, true],
        'no systems' => [true, false],
    ]);

    test('nothing is left behind when the creation fails halfway', function () {
        $missingSystem = System::factory()->make();
        $missingSystem->id = 999_999;

        expect(fn () => $this->service->create(newRequestData(systems: [$missingSystem]), $this->creator))
            ->toThrow(QueryException::class);

        expect(PrivacyRequest::count())->toBe(0)
            ->and(RequestIdentifier::count())->toBe(0)
            ->and(RequestSystem::count())->toBe(0)
            ->and(Activity::count())->toBe(0);
    });

    test('the received date given by the caller is not modified', function () {
        $receivedAt = CarbonImmutable::parse('2026-01-31 10:00:00', 'UTC');

        $this->service->create(newRequestData(receivedAt: $receivedAt), $this->creator);

        expect($receivedAt->toDateTimeString())->toBe('2026-01-31 10:00:00');
    });

    test('raw identifiers and free text are stored nowhere in plaintext', function () {
        $logPath = storage_path('logs/laravel.log');
        $logSizeBefore = File::exists($logPath) ? File::size($logPath) : 0;

        $this->service->create(newRequestData(), $this->creator);

        $dump = collect(Schema::getTableListing())
            ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR))
            ->implode("\n");

        $newLogs = File::exists($logPath) ? substr(File::get($logPath), $logSizeBefore) : '';

        foreach ([CREATOR_TEST_DISCORD_ID, CREATOR_TEST_EMAIL, 'requester.person', 'verified by voice'] as $secret) {
            expect($dump)->not->toContain($secret)
                ->and($newLogs)->not->toContain($secret);
        }

        expect(PrivacyRequest::count())->toBe(1);
    });
});
