<?php

declare(strict_types=1);

use App\Enums\IdentifierType;
use App\ValueObjects\SubjectIdentifier;

const IDENTIFIER_TEST_SNOWFLAKE = '123456789012345678';

test('discord ids are trimmed', function () {
    $identifier = new SubjectIdentifier(IdentifierType::DiscordId, '  ' . IDENTIFIER_TEST_SNOWFLAKE . " \n");

    expect($identifier->value)->toBe(IDENTIFIER_TEST_SNOWFLAKE);
});

test('discord mentions are normalized to the bare id', function (string $mention) {
    $identifier = new SubjectIdentifier(IdentifierType::DiscordId, $mention);

    expect($identifier->value)->toBe(IDENTIFIER_TEST_SNOWFLAKE);
})->with([
    'user mention' => '<@' . IDENTIFIER_TEST_SNOWFLAKE . '>',
    'nickname mention' => '<@!' . IDENTIFIER_TEST_SNOWFLAKE . '>',
]);

test('malformed discord ids are rejected', function (string $value) {
    new SubjectIdentifier(IdentifierType::DiscordId, $value);
})->throws(InvalidArgumentException::class)->with([
    'too short' => '1234',
    'too long' => '123456789012345678901',
    'letters' => '12345678901234567a',
    'username instead of id' => 'someone',
]);

test('emails are trimmed and lowercased', function () {
    $identifier = new SubjectIdentifier(IdentifierType::Email, '  Someone@Example.COM ');

    expect($identifier->value)->toBe('someone@example.com');
});

test('usernames are trimmed, lowercased and lose their leading @', function () {
    $identifier = new SubjectIdentifier(IdentifierType::Username, ' @SomeOne ');

    expect($identifier->value)->toBe('someone');
});

test('other values are trimmed but keep their case', function () {
    $identifier = new SubjectIdentifier(IdentifierType::Other, ' AbC-123 ', 'website_account_id');

    expect($identifier->value)->toBe('AbC-123');
});

test('empty values are rejected', function (IdentifierType $type, ?string $label) {
    new SubjectIdentifier($type, "   \t ", $label);
})->throws(InvalidArgumentException::class)->with([
    'discord id' => [IdentifierType::DiscordId, null],
    'email' => [IdentifierType::Email, null],
    'username' => [IdentifierType::Username, null],
    'other' => [IdentifierType::Other, 'website_account_id'],
]);

test('other identifiers require a label', function (?string $label) {
    new SubjectIdentifier(IdentifierType::Other, 'abc', $label);
})->throws(InvalidArgumentException::class)->with([
    'null' => null,
    'empty' => '',
    'whitespace only' => '   ',
    'no usable characters' => '!!!',
]);

test('non-other identifiers refuse a label', function () {
    new SubjectIdentifier(IdentifierType::Email, 'someone@example.com', 'website_account_id');
})->throws(InvalidArgumentException::class);

test('labels are normalized so equivalent spellings match', function (string $label) {
    $identifier = new SubjectIdentifier(IdentifierType::Other, 'abc', $label);

    expect($identifier->label)->toBe('website_account_id')
        ->and($identifier->prefix())->toBe('other:website_account_id');
})->with([
    'snake case' => 'website_account_id',
    'words' => 'Website Account ID',
    'kebab case' => 'Website-Account-ID',
    'padded' => '  website account id  ',
]);

test('the prefix is the type value for non-other identifiers', function () {
    $identifier = new SubjectIdentifier(IdentifierType::Email, 'someone@example.com');

    expect($identifier->prefix())->toBe('email');
});

test('the raw value does not leak into exception traces', function () {
    $secret = '12345678901234567x';

    try {
        new SubjectIdentifier(IdentifierType::DiscordId, $secret);
    } catch (InvalidArgumentException $exception) {
        $stringArguments = collect($exception->getTrace())
            ->flatMap(fn (array $frame): array => $frame['args'] ?? [])
            ->filter(fn (mixed $argument): bool => is_string($argument))
            ->all();

        expect($exception->getTraceAsString())->not->toContain($secret)
            ->and($stringArguments)->not->toContain($secret);

        return;
    }

    $this->fail('The malformed identifier was accepted.');
});
