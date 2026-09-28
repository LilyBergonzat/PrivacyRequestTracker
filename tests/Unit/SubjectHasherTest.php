<?php

declare(strict_types=1);

use App\Enums\IdentifierType;
use App\Services\SubjectHasher;
use App\ValueObjects\HashedIdentifier;
use App\ValueObjects\SubjectIdentifier;

const HASHER_TEST_KEYS = ['v1' => 'first-test-key', 'v2' => 'second-test-key'];

test('the hmac uses the current key over "prefix:value"', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v2');

    $hashed = $hasher->hash(new SubjectIdentifier(IdentifierType::Email, 'someone@example.com'));

    expect($hashed->version)->toBe('v2')
        ->and($hashed->hmac)->toBe(hash_hmac('sha256', 'email:someone@example.com', 'second-test-key'));
});

test('the hmac is 64 lowercase hex characters and does not contain the raw value', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v1');

    $hashed = $hasher->hash(new SubjectIdentifier(IdentifierType::Email, 'someone@example.com'));

    expect($hashed->hmac)->toMatch('/^[0-9a-f]{64}$/')
        ->not->toContain('someone');
});

test('the same value written differently gives the same hash', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v1');

    $first = $hasher->hash(new SubjectIdentifier(IdentifierType::Email, 'Someone@Example.com'));
    $second = $hasher->hash(new SubjectIdentifier(IdentifierType::Email, '  someone@example.COM '));

    expect($first->hmac)->toBe($second->hmac);
});

test('a discord mention gives the same hash as the bare id', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v1');

    $mention = $hasher->hash(new SubjectIdentifier(IdentifierType::DiscordId, '<@!123456789012345678>'));
    $bare = $hasher->hash(new SubjectIdentifier(IdentifierType::DiscordId, '123456789012345678'));

    expect($mention->hmac)->toBe($bare->hmac);
});

test('the same value with different types gives different hashes', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v1');
    $value = '123456789012345678';

    $hashes = [
        $hasher->hash(new SubjectIdentifier(IdentifierType::DiscordId, $value))->hmac,
        $hasher->hash(new SubjectIdentifier(IdentifierType::Username, $value))->hmac,
        $hasher->hash(new SubjectIdentifier(IdentifierType::Other, $value, 'website_account_id'))->hmac,
        $hasher->hash(new SubjectIdentifier(IdentifierType::Other, $value, 'patreon_id'))->hmac,
    ];

    expect(array_unique($hashes))->toHaveCount(4);
});

test('equivalent other labels give the same hash', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v1');

    $first = $hasher->hash(new SubjectIdentifier(IdentifierType::Other, 'abc', 'Website Account ID'));
    $second = $hasher->hash(new SubjectIdentifier(IdentifierType::Other, 'abc', 'website_account_id'));

    expect($first->hmac)->toBe($second->hmac);
});

test('different keys give different hashes', function () {
    $identifier = new SubjectIdentifier(IdentifierType::Email, 'someone@example.com');

    $withFirstKey = (new SubjectHasher(HASHER_TEST_KEYS, 'v1'))->hash($identifier);
    $withSecondKey = (new SubjectHasher(HASHER_TEST_KEYS, 'v2'))->hash($identifier);

    expect($withFirstKey->hmac)->not->toBe($withSecondKey->hmac);
});

test('candidates contain one hash per configured key', function () {
    $hasher = new SubjectHasher(HASHER_TEST_KEYS, 'v2');

    $candidates = $hasher->candidates(new SubjectIdentifier(IdentifierType::Email, 'someone@example.com'));

    expect($candidates)->toHaveCount(2)
        ->each->toBeInstanceOf(HashedIdentifier::class)
        ->and(array_map(fn (HashedIdentifier $candidate): string => $candidate->version, $candidates))
        ->toBe(['v1', 'v2']);
});

test('a hash made with an old key is still found after rotation', function () {
    $identifier = new SubjectIdentifier(IdentifierType::DiscordId, '123456789012345678');

    $storedBeforeRotation = (new SubjectHasher(['v1' => 'first-test-key'], 'v1'))->hash($identifier);

    $candidatesAfterRotation = (new SubjectHasher(HASHER_TEST_KEYS, 'v2'))->candidates($identifier);

    expect($candidatesAfterRotation)->toContainEqual($storedBeforeRotation);
});

test('an invalid configuration is rejected', function (array $keys, string $currentVersion) {
    new SubjectHasher($keys, $currentVersion);
})->throws(InvalidArgumentException::class)->with([
    'no keys' => [[], 'v1'],
    'unknown current version' => [HASHER_TEST_KEYS, 'v3'],
    'empty current version' => [HASHER_TEST_KEYS, ''],
]);
