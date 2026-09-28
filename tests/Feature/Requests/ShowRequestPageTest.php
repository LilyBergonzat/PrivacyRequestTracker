<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\User;
use App\Services\PrivacyRequestWorkflow;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->handler = User::factory()->create(['name' => 'Lily Handler']);
    $this->request = PrivacyRequest::factory()->for($this->handler, 'creator')->create([
        'type' => RequestType::Deletion,
        'channel' => RequestChannel::Modmail,
        'received_at' => '2026-01-15 10:00:00',
        'due_at' => '2026-02-15 23:59:59',
    ]);
});

describe('access', function () {
    test('guests are redirected to the login page', function () {
        $this->get(route('requests.show', $this->request))->assertRedirect(route('login'));
    });

    test('any staff member can open a request', function (bool $admin) {
        $user = $admin ? User::factory()->admin()->create() : User::factory()->create();

        $this->actingAs($user)->get(route('requests.show', $this->request))->assertOk();
    })->with(['handler' => false, 'admin' => true]);

    test('the request is found by its reference', function () {
        expect(route('requests.show', $this->request))->toEndWith('/requests/' . $this->request->reference);
    });

    test('an unknown reference gives a 404, and so does the id', function (string $path) {
        $this->actingAs($this->handler)->get($path)->assertNotFound();
    })->with([
        'unknown reference' => ['/requests/DSR-NOPE22'],
        'numeric id' => fn (): string => '/requests/' . test()->request->id,
    ]);

    test('the new request page is not mistaken for a reference', function () {
        $this->actingAs($this->handler)->get('/requests/new')->assertOk()->assertSee('Create request');
    });
});

describe('header', function () {
    test('the page shows the reference in the title and the header', function () {
        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSeeInOrder(['<title>', $this->request->reference, '</title>'], escape: false)
            ->assertSee('Request ' . $this->request->reference);
    });

    test('the header shows the type, status, channel, date and creator', function () {
        config(['privacy.timezone' => 'Europe/Paris']);

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('Deletion')
            ->assertSee('Received')
            ->assertSee('via Modmail')
            ->assertSee('January 15, 2026')
            ->assertSee('created by Lily Handler');
    });
});

describe('deadline', function () {
    test('an overdue request is shown in red', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('Overdue')
            ->assertSee('ago');
    });

    test('a request close to its deadline is flagged', function () {
        $this->travelTo(CarbonImmutable::parse('2026-02-12 10:00:00', 'UTC'));

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('Soon');
    });

    test('a request far from its deadline is on track', function () {
        $this->travelTo(CarbonImmutable::parse('2026-01-16 10:00:00', 'UTC'));

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('On Track')
            ->assertSee('Due 4 weeks from now');
    });

    test('the deadline shown is the earliest pending system deadline, in the organization time zone', function () {
        config(['privacy.timezone' => 'Europe/Paris']);
        $this->travelTo(CarbonImmutable::parse('2026-01-16 10:00:00', 'UTC'));
        RequestSystem::factory()->for($this->request)->create(['due_at' => '2026-01-29 22:59:59']);

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('January 29, 2026 11:59 PM')
            ->assertDontSee('Extended until');
    });

    test('an extended request says so', function () {
        $this->request->forceFill(['extended_until' => '2026-04-15 23:59:59'])->save();

        $this->actingAs($this->handler)
            ->get(route('requests.show', $this->request))
            ->assertSee('Extended until');
    });
});

describe('identity', function () {
    beforeEach(function () {
        $this->page = fn () => Livewire\Livewire::actingAs($this->handler)
            ->test('pages::requests.show', ['privacyRequest' => $this->request]);
    });

    test('an unverified request shows the verification form', function () {
        ($this->page)()
            ->assertSee('How was the identity verified?')
            ->assertSee('Mark identity as verified')
            ->assertDontSee('Verified by');
    });

    test('the notes field is filled with the saved notes', function () {
        $this->request->forceFill(['identity_verification_notes' => 'Same Discord account'])->save();

        ($this->page)()->assertSet('identityNotes', 'Same Discord account');
    });

    test('notes can be saved from the page', function () {
        ($this->page)()
            ->set('identityNotes', 'Same Discord account')
            ->call('saveIdentityNotes')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show')
            ->assertSee('Verifying Identity');

        expect($this->request->refresh()->identity_verification_notes)->toBe('Same Discord account');
    });

    test('empty notes are saved as null', function () {
        $this->request->forceFill(['identity_verification_notes' => 'Old'])->save();

        ($this->page)()->set('identityNotes', '')->call('saveIdentityNotes');

        expect($this->request->refresh()->identity_verification_notes)->toBeNull();
    });

    test('notes that are too long are rejected', function () {
        ($this->page)()
            ->set('identityNotes', str_repeat('a', 5001))
            ->call('saveIdentityNotes')
            ->assertHasErrors('identityNotes');

        expect($this->request->refresh()->identity_verification_notes)->toBeNull();
    });

    test('verifying the identity updates the page', function () {
        config(['privacy.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-01-16 10:00:00', 'UTC'));
        $this->request->forceFill(['identity_verification_notes' => "Same Discord account\nConfirmed by voice"])->save();

        ($this->page)()
            ->call('verifyIdentity')
            ->assertDispatched('toast-show')
            ->assertSee('In Progress')
            ->assertDontSee('Mark identity as verified')
            ->assertSee('Verified by Lily Handler on')
            ->assertSee('January 16, 2026')
            ->assertSee('Confirmed by voice');

        expect($this->request->refresh()->status)->toBe(RequestStatus::InProgress);
    });

    test('verifying twice from a stale page is refused', function () {
        $page = ($this->page)();
        app(PrivacyRequestWorkflow::class)->verifyIdentity($this->request);

        expect(fn () => $page->call('verifyIdentity'))->toThrow(DomainException::class);
    });

    test('a verified request without a log entry still renders', function () {
        $this->request->forceFill(['status' => RequestStatus::InProgress])->save();

        ($this->page)()->assertSee('Verified by someone');
    });
});

test('an identity verified by the system does not break the page', function () {
    $this->request->forceFill(['status' => RequestStatus::InProgress])->save();
    Activity::factory()->for($this->request)->create([
        'user_id' => null,
        'action' => ActivityAction::IdentityVerified,
    ]);

    $this->actingAs($this->handler)
        ->get(route('requests.show', $this->request))
        ->assertOk()
        ->assertSee('Verified by someone');
});
