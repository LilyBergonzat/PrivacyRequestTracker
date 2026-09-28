<?php

declare(strict_types=1);

use App\Enums\IdentifierType;
use App\Models\PrivacyRequest;
use App\Models\RequestIdentifier;
use App\Services\SubjectHasher;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a request has many identifiers', function () {
    $request = PrivacyRequest::factory()->create();
    RequestIdentifier::factory()->for($request)->count(2)->create();
    RequestIdentifier::factory()->create();

    expect($request->identifiers)->toHaveCount(2)
        ->and($request->identifiers->first()->privacyRequest->is($request))->toBeTrue();
});

test('a hashed identifier can be stored without the raw value', function () {
    $request = PrivacyRequest::factory()->create();
    $hashed = app(SubjectHasher::class)->hash(new SubjectIdentifier(IdentifierType::Email, 'someone@example.com'));

    $request->identifiers()->create([
        'identifier_type' => 'email',
        'hmac' => $hashed->hmac,
        'key_version' => $hashed->version,
    ]);

    $row = (array) DB::table('request_identifiers')->first();

    expect($row['hmac'])->toBe($hashed->hmac)
        ->and(implode('|', $row))->not->toContain('someone');
});

test('the same identifier cannot be stored twice on one request', function () {
    $identifier = RequestIdentifier::factory()->create();

    RequestIdentifier::factory()->for($identifier->privacyRequest)->create([
        'identifier_type' => $identifier->identifier_type,
        'hmac' => $identifier->hmac,
    ]);
})->throws(UniqueConstraintViolationException::class);

test('the same identifier can appear on several requests', function () {
    $identifier = RequestIdentifier::factory()->create();

    RequestIdentifier::factory()->create([
        'identifier_type' => $identifier->identifier_type,
        'hmac' => $identifier->hmac,
    ]);

    expect(RequestIdentifier::where('hmac', $identifier->hmac)->count())->toBe(2);
});

test('identifiers are deleted along with their request', function () {
    $identifier = RequestIdentifier::factory()->create();

    $identifier->privacyRequest->delete();

    expect(RequestIdentifier::count())->toBe(0);
});
