<?php

declare(strict_types=1);

use App\Enums\IdentifierType;
use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use App\Services\MatchingRequestFinder;
use App\Services\SubjectHasher;
use App\ValueObjects\SubjectIdentifier;

/**
 * Stores an identifier on a request the same way the creation does, with the given hasher.
 */
function attachIdentifier(PrivacyRequest $request, SubjectIdentifier $identifier, ?SubjectHasher $hasher = null): void
{
    $hashed = ($hasher ?? app(SubjectHasher::class))->hash($identifier);

    RequestIdentifier::factory()->for($request)->create([
        'identifier_type' => $identifier->prefix(),
        'hmac' => $hashed->hmac,
        'key_version' => $hashed->version,
    ]);
}

function emailIdentifier(string $value): SubjectIdentifier
{
    return new SubjectIdentifier(IdentifierType::Email, $value);
}

function discordIdentifier(string $value): SubjectIdentifier
{
    return new SubjectIdentifier(IdentifierType::DiscordId, $value);
}

beforeEach(function () {
    $this->finder = app(MatchingRequestFinder::class);
});

test('no identifiers means no matches, even when requests exist', function () {
    attachIdentifier(PrivacyRequest::factory()->create(), emailIdentifier('someone@example.com'));

    expect($this->finder->find([]))->toBeEmpty();
});

test('an earlier request with the same identifier is found', function () {
    $earlier = PrivacyRequest::factory()->create();
    attachIdentifier($earlier, emailIdentifier('someone@example.com'));

    $matches = $this->finder->find([emailIdentifier('someone@example.com')]);

    expect($matches)->toHaveCount(1)
        ->and($matches->first()->is($earlier))->toBeTrue();
});

test('the identifier is normalized before searching', function () {
    $earlier = PrivacyRequest::factory()->create();
    attachIdentifier($earlier, discordIdentifier('287654321098765432'));

    expect($this->finder->find([discordIdentifier('<@287654321098765432>')]))->toHaveCount(1);
});

test('requests of other people are not found', function () {
    attachIdentifier(PrivacyRequest::factory()->create(), emailIdentifier('someone.else@example.com'));

    expect($this->finder->find([emailIdentifier('someone@example.com')]))->toBeEmpty();
});

test('the same value with another type does not match', function () {
    attachIdentifier(PrivacyRequest::factory()->create(), new SubjectIdentifier(IdentifierType::Username, '287654321098765432'));

    expect($this->finder->find([discordIdentifier('287654321098765432')]))->toBeEmpty();
});

test('any one of the typed identifiers is enough to find a request', function () {
    $byEmail = PrivacyRequest::factory()->create();
    attachIdentifier($byEmail, emailIdentifier('someone@example.com'));

    $byDiscord = PrivacyRequest::factory()->create();
    attachIdentifier($byDiscord, discordIdentifier('287654321098765432'));

    $matches = $this->finder->find([discordIdentifier('287654321098765432'), emailIdentifier('someone@example.com')]);

    expect($matches->pluck('id')->sort()->values()->all())->toBe(collect([$byEmail->id, $byDiscord->id])->sort()->values()->all());
});

test('a request matching several identifiers is returned once', function () {
    $earlier = PrivacyRequest::factory()->create();
    attachIdentifier($earlier, emailIdentifier('someone@example.com'));
    attachIdentifier($earlier, discordIdentifier('287654321098765432'));

    expect($this->finder->find([emailIdentifier('someone@example.com'), discordIdentifier('287654321098765432')]))->toHaveCount(1);
});

test('closed requests are found too', function () {
    $closed = PrivacyRequest::factory()->closed()->create();
    attachIdentifier($closed, emailIdentifier('someone@example.com'));

    expect($this->finder->find([emailIdentifier('someone@example.com')])->first()->is($closed))->toBeTrue();
});

test('a request hashed with an old key is still found after rotation', function () {
    config([
        'privacy.subject_hmac_keys' => ['v1' => 'first-test-key'],
        'privacy.subject_hmac_current' => 'v1',
    ]);
    $earlier = PrivacyRequest::factory()->create();
    attachIdentifier($earlier, emailIdentifier('someone@example.com'), new SubjectHasher(['v1' => 'first-test-key'], 'v1'));

    config([
        'privacy.subject_hmac_keys' => ['v1' => 'first-test-key', 'v2' => 'second-test-key'],
        'privacy.subject_hmac_current' => 'v2',
    ]);
    app()->forgetInstance(SubjectHasher::class);
    app()->forgetInstance(MatchingRequestFinder::class);

    $matches = app(MatchingRequestFinder::class)->find([emailIdentifier('someone@example.com')]);

    expect($matches)->toHaveCount(1)
        ->and($matches->first()->is($earlier))->toBeTrue();
});

test('a request hashed with a key that was removed is no longer found', function () {
    $earlier = PrivacyRequest::factory()->create();
    attachIdentifier($earlier, emailIdentifier('someone@example.com'), new SubjectHasher(['v0' => 'retired-key'], 'v0'));

    expect($this->finder->find([emailIdentifier('someone@example.com')]))->toBeEmpty();
});

test('the most recent request comes first', function () {
    $older = PrivacyRequest::factory()->create(['received_at' => '2025-03-01 10:00:00']);
    $newer = PrivacyRequest::factory()->create(['received_at' => '2026-02-01 10:00:00']);
    $middle = PrivacyRequest::factory()->create(['received_at' => '2025-09-01 10:00:00']);

    foreach ([$older, $newer, $middle] as $request) {
        attachIdentifier($request, emailIdentifier('someone@example.com'));
    }

    expect($this->finder->find([emailIdentifier('someone@example.com')])->pluck('id')->all())
        ->toBe([$newer->id, $middle->id, $older->id]);
});
