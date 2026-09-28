<?php

declare(strict_types=1);

use App\Enums\ContactMethod;
use App\Enums\IdentifierType;
use App\Enums\RequestChannel;
use App\Enums\RequestType;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\User;
use App\Services\SubjectHasher;
use App\ValueObjects\SubjectIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const CREATE_REQUEST_COMPONENT = 'pages::requests.create';
const CREATE_REQUEST_DISCORD_ID = '287654321098765432';

beforeEach(function () {
    $this->handler = User::factory()->create();
    $this->modmail = System::factory()->create(['name' => 'Modmail Bot', 'sort_order' => 0]);
    $this->website = System::factory()->create(['name' => 'Website', 'sort_order' => 1]);
    $this->retired = System::factory()->inactive()->create(['name' => 'Retired System']);
});

function createRequestPage(): Testable
{
    return Livewire::actingAs(test()->handler)->test(CREATE_REQUEST_COMPONENT);
}

/**
 * A form filled with valid values, ready to be submitted.
 */
function filledCreateRequestPage(): Testable
{
    return createRequestPage()->set([
        'type' => RequestType::Deletion->value,
        'channel' => RequestChannel::Modmail->value,
        'receivedAt' => '2026-01-15',
        'identifiers' => [
            ['type' => 'discord_id', 'label' => '', 'value' => CREATE_REQUEST_DISCORD_ID],
            ['type' => 'email', 'label' => '', 'value' => 'requester@example.org'],
        ],
        'contactMethod' => ContactMethod::Discord->value,
        'contactDetails' => 'DM requester.person',
        'notes' => 'Asked in a Modmail thread',
    ]);
}

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('requests.create'))->assertRedirect(route('login'));
    });

    test('handlers and admins can open the page', function (bool $admin) {
        $user = $admin ? User::factory()->admin()->create() : $this->handler;

        $this->actingAs($user)->get(route('requests.create'))->assertOk();
    })->with(['handler' => false, 'admin' => true]);

    test('staff without two-factor authentication are sent to set it up', function () {
        $this->actingAs(User::factory()->withoutTwoFactor()->create())
            ->get(route('requests.create'))
            ->assertRedirect(route('security.edit'));
    });

    test('the page is linked in the sidebar', function () {
        $this->actingAs($this->handler)
            ->get(route('dashboard'))
            ->assertSee(route('requests.create'));
    });
});

describe('initial state', function () {
    test('the form starts with today, one empty identifier and every active system', function () {
        config(['privacy.timezone' => 'Europe/Paris']);

        createRequestPage()
            ->assertSet('receivedAt', now('Europe/Paris')->toDateString())
            ->assertSet('identifiers', [['type' => 'discord_id', 'label' => '', 'value' => '']])
            ->assertSet('selectedSystems', [$this->modmail->id, $this->website->id])
            ->assertSee('Modmail Bot')
            ->assertSee('Website')
            ->assertDontSee('Retired System');
    });

    test('identifier rows can be added and removed', function () {
        createRequestPage()
            ->call('addIdentifier')
            ->call('addIdentifier')
            ->set('identifiers.1.value', 'second')
            ->set('identifiers.2.value', 'third')
            ->call('removeIdentifier', 1)
            ->assertCount('identifiers', 2)
            ->assertSet('identifiers.1.value', 'third');
    });
});

describe('creating', function () {
    test('a valid form creates the request and opens its page', function () {
        $component = filledCreateRequestPage()
            ->call('createRequest')
            ->assertHasNoErrors();

        $request = PrivacyRequest::sole();

        $component->assertRedirect(route('requests.show', $request));

        expect($request->type)->toBe(RequestType::Deletion)
            ->and($request->channel)->toBe(RequestChannel::Modmail)
            ->and($request->contact_method)->toBe(ContactMethod::Discord)
            ->and($request->contact_details)->toBe('DM requester.person')
            ->and($request->notes)->toBe('Asked in a Modmail thread')
            ->and($request->created_by)->toBe($this->handler->id)
            ->and($request->identifiers()->pluck('identifier_type')->sort()->values()->all())->toBe(['discord_id', 'email']);
    });

    test('only the selected systems are added to the request', function () {
        filledCreateRequestPage()
            ->set('selectedSystems', [$this->website->id])
            ->call('createRequest')
            ->assertHasNoErrors();

        expect(RequestSystem::pluck('system_id')->all())->toBe([$this->website->id]);
    });

    test('the received date is the start of that day in the organization time zone', function () {
        config(['privacy.timezone' => 'Europe/Paris']);

        filledCreateRequestPage()->call('createRequest');

        expect(PrivacyRequest::sole()->received_at->toDateTimeString())->toBe('2026-01-14 23:00:00');
    });

    test('an other identifier is created with its label', function () {
        filledCreateRequestPage()
            ->set('identifiers', [['type' => 'other', 'label' => 'Website Account ID', 'value' => 'abc-123']])
            ->call('createRequest')
            ->assertHasNoErrors();

        expect(PrivacyRequest::sole()->identifiers()->sole()->identifier_type)->toBe('other:website_account_id');
    });

    test('contact details are optional when the requester does not need to be contacted', function () {
        filledCreateRequestPage()
            ->set('contactMethod', ContactMethod::None->value)
            ->set('contactDetails', '')
            ->set('notes', '')
            ->call('createRequest')
            ->assertHasNoErrors();

        $request = PrivacyRequest::sole();

        expect($request->contact_details)->toBeNull()
            ->and($request->notes)->toBeNull();
    });

    test('raw identifiers are stored nowhere in plaintext', function () {
        filledCreateRequestPage()->call('createRequest')->assertHasNoErrors();

        $dump = collect(Schema::getTableListing())
            ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR))
            ->implode("\n");

        expect($dump)->not->toContain(CREATE_REQUEST_DISCORD_ID)
            ->not->toContain('requester@example.org');
    });
});

describe('validation', function () {
    test('invalid input is rejected and nothing is created', function (array $input, string $field) {
        filledCreateRequestPage()
            ->set($input)
            ->call('createRequest')
            ->assertHasErrors($field)
            ->assertNoRedirect();

        expect(PrivacyRequest::count())->toBe(0);
    })->with([
        'missing type' => [['type' => ''], 'type'],
        'unknown type' => [['type' => 'bogus'], 'type'],
        'missing channel' => [['channel' => ''], 'channel'],
        'received in the future' => [['receivedAt' => '2099-01-01'], 'receivedAt'],
        'not a date' => [['receivedAt' => 'yesterday-ish'], 'receivedAt'],
        'no identifiers' => [['identifiers' => []], 'identifiers'],
        'empty identifier value' => [['identifiers' => [['type' => 'email', 'label' => '', 'value' => '']]], 'identifiers.0.value'],
        'unknown identifier type' => [['identifiers' => [['type' => 'phone', 'label' => '', 'value' => '123']]], 'identifiers.0.type'],
        'other identifier without label' => [['identifiers' => [['type' => 'other', 'label' => '', 'value' => 'abc']]], 'identifiers.0.label'],
        'missing contact details' => [['contactDetails' => ''], 'contactDetails'],
        'no systems' => [['selectedSystems' => []], 'selectedSystems'],
    ]);

    test('error messages use readable field names', function () {
        $errors = filledCreateRequestPage()
            ->set('identifiers', [['type' => 'other', 'label' => '', 'value' => '']])
            ->set('selectedSystems', [])
            ->call('createRequest')
            ->errors();

        expect($errors->first('identifiers.0.value'))->toBe('The identifier field is required.')
            ->and($errors->first('identifiers.0.label'))->toBe('The label field is required when identifier type is other.')
            ->and($errors->first('selectedSystems'))->toBe('The systems field is required.');
    });

    test('an inactive system cannot be selected', function () {
        filledCreateRequestPage()
            ->set('selectedSystems', [$this->retired->id])
            ->call('createRequest')
            ->assertHasErrors('selectedSystems.0');

        expect(PrivacyRequest::count())->toBe(0);
    });

    test('a malformed identifier gets an error on its own row', function () {
        filledCreateRequestPage()
            ->set('identifiers', [
                ['type' => 'email', 'label' => '', 'value' => 'requester@example.org'],
                ['type' => 'discord_id', 'label' => '', 'value' => '12345'],
            ])
            ->call('createRequest')
            ->assertHasErrors('identifiers.1.value')
            ->assertHasNoErrors('identifiers.0.value');

        expect(PrivacyRequest::count())->toBe(0);
    });

    test('error messages never repeat the typed identifier', function () {
        $component = filledCreateRequestPage()
            ->set('identifiers', [['type' => 'discord_id', 'label' => '', 'value' => '99999-not-an-id']])
            ->call('createRequest');

        expect(json_encode($component->errors()->toArray(), JSON_THROW_ON_ERROR))->not->toContain('99999-not-an-id');
    });
});

/**
 * Creates an earlier request that holds the given identifier, hashed like the app does.
 */
function earlierRequestWith(SubjectIdentifier $identifier, array $attributes = []): PrivacyRequest
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

describe('earlier requests', function () {
    beforeEach(function () {
        $this->earlier = earlierRequestWith(
            new SubjectIdentifier(IdentifierType::DiscordId, CREATE_REQUEST_DISCORD_ID),
            ['type' => RequestType::Access, 'received_at' => '2025-06-01 10:00:00'],
        );
    });

    test('the warning appears as soon as a matching identifier is typed', function () {
        createRequestPage()
            ->assertDontSee($this->earlier->reference)
            ->set('identifiers.0.value', CREATE_REQUEST_DISCORD_ID)
            ->assertSet('earlierRequestIds', [$this->earlier->id])
            ->assertSee($this->earlier->reference)
            ->assertSee('This person has 1 earlier request.')
            ->assertSee('Create anyway');
    });

    test('no warning for someone who never made a request', function () {
        createRequestPage()
            ->set('identifiers.0.value', '111111111111111111')
            ->assertSet('earlierRequestIds', [])
            ->assertDontSee('earlier request');
    });

    test('an incomplete identifier is ignored while typing, without errors', function () {
        createRequestPage()
            ->set('identifiers.0.value', '12345')
            ->assertHasNoErrors()
            ->assertSet('earlierRequestIds', []);
    });

    test('earlier requests are listed newest first', function () {
        $newer = earlierRequestWith(
            new SubjectIdentifier(IdentifierType::Email, 'requester@example.org'),
            ['received_at' => '2026-01-01 10:00:00'],
        );

        createRequestPage()
            ->set('identifiers', [
                ['type' => 'discord_id', 'label' => '', 'value' => CREATE_REQUEST_DISCORD_ID],
                ['type' => 'email', 'label' => '', 'value' => 'requester@example.org'],
            ])
            ->assertSee('This person has 2 earlier requests.')
            ->assertSeeInOrder([$newer->reference, $this->earlier->reference]);
    });

    test('submitting with a match does not create anything and shows the warning', function () {
        filledCreateRequestPage()
            ->call('createRequest')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee($this->earlier->reference);

        expect(PrivacyRequest::count())->toBe(1);
    });

    test('the request is created once the duplicates are confirmed', function () {
        filledCreateRequestPage()
            ->call('createRequest')
            ->set('duplicatesConfirmed', true)
            ->call('createRequest')
            ->assertHasNoErrors()
            ->assertRedirect(route('requests.show', PrivacyRequest::latest('id')->first()));

        expect(PrivacyRequest::count())->toBe(2);
    });

    test('changing an identifier asks for confirmation again', function () {
        filledCreateRequestPage()
            ->set('duplicatesConfirmed', true)
            ->set('identifiers.1.value', 'someone.else@example.org')
            ->assertSet('duplicatesConfirmed', false)
            ->call('createRequest')
            ->assertNoRedirect();

        expect(PrivacyRequest::count())->toBe(1);
    });

    test('adding or removing an identifier asks for confirmation again', function (string $method, array $arguments) {
        filledCreateRequestPage()
            ->set('duplicatesConfirmed', true)
            ->call($method, ...$arguments)
            ->assertSet('duplicatesConfirmed', false);
    })->with([
        'add' => ['addIdentifier', []],
        'remove' => ['removeIdentifier', [1]],
    ]);

    test('removing the matching identifier clears the warning', function () {
        filledCreateRequestPage()
            ->assertSee($this->earlier->reference)
            ->call('removeIdentifier', 0)
            ->assertSet('earlierRequestIds', [])
            ->assertDontSee($this->earlier->reference);
    });

    test('the list of earlier requests cannot be tampered with from the browser', function () {
        createRequestPage()->set('earlierRequestIds', [$this->earlier->id]);
    })->throws(CannotUpdateLockedPropertyException::class);

    test('create anyway submits the form so the browser validates it first', function () {
        createRequestPage()
            ->set('identifiers.0.value', CREATE_REQUEST_DISCORD_ID)
            ->assertSeeHtml('x-data="duplicateConfirmation"')
            ->assertSeeHtml('x-on:click="confirmDuplicates"')
            ->assertSeeHtml('type="submit"');
    });
});

test('a reminder is shown near the buttons when fields have errors', function () {
    filledCreateRequestPage()
        ->assertDontSee('Some fields need your attention above.')
        ->set('receivedAt', '2099-01-01')
        ->call('createRequest')
        ->assertSee('Some fields need your attention above.');
});

test('earlier requests in the warning link to their page', function () {
    $earlier = earlierRequestWith(new SubjectIdentifier(IdentifierType::DiscordId, CREATE_REQUEST_DISCORD_ID));

    createRequestPage()
        ->set('identifiers.0.value', CREATE_REQUEST_DISCORD_ID)
        ->assertSeeHtml('href="' . route('requests.show', $earlier) . '"');
});
