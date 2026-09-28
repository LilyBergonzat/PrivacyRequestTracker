<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\IdentifierType;
use App\Enums\RequestStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\SubjectHasher;
use App\ValueObjects\SubjectIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const LOOKUP_DISCORD_ID = '287654321098765432';

beforeEach(function () {
    $this->user = User::factory()->create();
});

function lookupPage(): Testable
{
    return Livewire::actingAs(test()->user)->test('pages::requests.lookup');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function requestKnownBy(SubjectIdentifier $identifier, array $attributes = []): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create($attributes);
    $hashed = app(SubjectHasher::class)->hash($identifier);

    $request->identifiers()->create([
        'identifier_type' => $identifier->prefix(),
        'hmac' => $hashed->hmac,
        'key_version' => $hashed->version,
    ]);

    return $request;
}

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('requests.lookup'))->assertRedirect(route('login'));
    });

    test('any staff member can use the lookup', function () {
        $this->actingAs($this->user)
            ->get(route('requests.lookup'))
            ->assertOk()
            ->assertSee('Search');
    });

    test('lookup is not mistaken for a request reference', function () {
        expect(route('requests.lookup'))->toEndWith('/requests/lookup');
    });

    test('the page is linked in the sidebar', function () {
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee(route('requests.lookup'));
    });
});

describe('searching', function () {
    test('nothing is shown before searching', function () {
        lookupPage()
            ->assertSet('resultIds', null)
            ->assertDontSee('No request found');
    });

    test('earlier requests of the person are listed, newest first', function () {
        $identifier = new SubjectIdentifier(IdentifierType::DiscordId, LOOKUP_DISCORD_ID);
        $older = requestKnownBy($identifier, ['received_at' => '2025-03-01 10:00:00']);
        $newer = requestKnownBy($identifier, ['received_at' => '2026-01-10 10:00:00']);
        $someoneElse = requestKnownBy(new SubjectIdentifier(IdentifierType::DiscordId, '111111111111111111'));

        lookupPage()
            ->set('value', LOOKUP_DISCORD_ID)
            ->call('search')
            ->assertHasNoErrors()
            ->assertSee('2 earlier requests')
            ->assertSeeInOrder([$newer->reference, $older->reference])
            ->assertDontSee($someoneElse->reference)
            ->assertSee(route('requests.show', $newer));
    });

    test('the identifier is normalized before searching', function () {
        $request = requestKnownBy(new SubjectIdentifier(IdentifierType::Email, 'someone@example.com'));

        lookupPage()
            ->set('type', 'email')
            ->set('value', '  Someone@Example.COM ')
            ->call('search')
            ->assertSee($request->reference);
    });

    test('other identifiers are searched with their label', function () {
        $request = requestKnownBy(new SubjectIdentifier(IdentifierType::Other, 'abc-123', 'website_account_id'));

        lookupPage()
            ->set('type', 'other')
            ->set('label', 'Website Account ID')
            ->set('value', 'abc-123')
            ->call('search')
            ->assertHasNoErrors()
            ->assertSee($request->reference);
    });

    test('closed requests are found, with their closing date', function () {
        config(['privacy.timezone' => 'UTC']);
        $request = requestKnownBy(new SubjectIdentifier(IdentifierType::DiscordId, LOOKUP_DISCORD_ID));
        $request->forceFill(['status' => RequestStatus::Completed, 'closed_at' => '2026-02-01 10:00:00'])->save();

        lookupPage()
            ->set('value', LOOKUP_DISCORD_ID)
            ->call('search')
            ->assertSee($request->reference)
            ->assertSee('Completed')
            ->assertSee('February 1, 2026');
    });

    test('an unknown person gives an empty result', function () {
        lookupPage()
            ->set('value', LOOKUP_DISCORD_ID)
            ->call('search')
            ->assertSet('resultIds', [])
            ->assertSee('No request found for this identifier.');
    });

    test('the results cannot be tampered with from the browser', function () {
        lookupPage()->set('resultIds', [1]);
    })->throws(CannotUpdateLockedPropertyException::class);
});

describe('validation', function () {
    test('invalid input is rejected without searching or logging', function (array $input, string $field) {
        lookupPage()
            ->set($input)
            ->call('search')
            ->assertHasErrors($field)
            ->assertSet('resultIds', null);

        expect(Activity::count())->toBe(0);
    })->with([
        'missing value' => [['value' => ''], 'value'],
        'unknown type' => [['type' => 'phone', 'value' => '123'], 'type'],
        'other without label' => [['type' => 'other', 'label' => '', 'value' => 'abc'], 'label'],
        'malformed discord id' => [['type' => 'discord_id', 'value' => '12345'], 'value'],
    ]);

    test('error messages use readable names and never repeat the identifier', function () {
        $errors = lookupPage()
            ->set('value', '99999-not-an-id')
            ->call('search')
            ->errors();

        expect($errors->first('value'))->toBe('This identifier is not valid.')
            ->and(json_encode($errors->toArray()))->not->toContain('99999-not-an-id');
    });
});

describe('privacy', function () {
    test('the value is cleared after searching, the type is kept', function () {
        lookupPage()
            ->set('type', 'email')
            ->set('value', 'someone@example.com')
            ->call('search')
            ->assertSet('value', '')
            ->assertSet('type', 'email');
    });

    test('each search is logged with the number of matches only', function () {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 10:00:00', 'UTC'));
        requestKnownBy(new SubjectIdentifier(IdentifierType::DiscordId, LOOKUP_DISCORD_ID));

        lookupPage()->set('value', LOOKUP_DISCORD_ID)->call('search');

        $activity = Activity::sole();

        expect($activity->action)->toBe(ActivityAction::LookupPerformed)
            ->and($activity->privacy_request_id)->toBeNull()
            ->and($activity->user_id)->toBe($this->user->id)
            ->and($activity->metadata)->toBe(['matches' => 1]);
    });

    test('a search with no match is logged too', function () {
        lookupPage()->set('value', LOOKUP_DISCORD_ID)->call('search');

        expect(Activity::sole()->metadata)->toBe(['matches' => 0]);
    });

    test('the searched identifier is stored nowhere', function () {
        lookupPage()->set('value', LOOKUP_DISCORD_ID)->call('search');

        $dump = collect(Schema::getTableListing())
            ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR))
            ->implode("\n");

        expect($dump)->not->toContain(LOOKUP_DISCORD_ID);
    });
});
