<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\Activity;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\System;
use App\Models\SystemStep;
use App\Models\User;
use App\Services\PrivacyRequestWorkflow;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->handler = User::factory()->create(['name' => 'Lily Handler']);
    $this->actingAs($this->handler);

    $this->request = PrivacyRequest::factory()->create();
    $this->request->forceFill(['status' => RequestStatus::InProgress])->save();

    $this->system = System::factory()->create(['name' => 'Modmail Bot']);
    $this->step = SystemStep::factory()->for($this->system)->create(['title' => 'Delete their threads']);
    $this->row = RequestSystem::factory()->for($this->request)->for($this->system)->create();

    $this->workflow = app(PrivacyRequestWorkflow::class);
});

function timeline(): Testable
{
    return Livewire::actingAs(test()->handler)
        ->test('pages::requests.show', ['privacyRequest' => test()->request->fresh()]);
}

test('entries are listed oldest first, including several in the same second', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

    $this->workflow->completeStep($this->row, $this->step, $this->handler);
    $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Done', $this->handler);
    $this->workflow->reopenSystem($this->row->fresh());

    timeline()->assertSeeInOrder(['Step completed', 'System resolved', 'System reopened']);
});

test('each entry says who did it and when', function () {
    config(['privacy.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00', 'UTC'));

    $this->workflow->completeStep($this->row, $this->step, $this->handler);

    timeline()->assertSeeInOrder(['Step completed', 'Lily Handler', 'January 20, 2026 10:00 AM']);
});

test('actions done by the scheduler are attributed to the system', function () {
    Activity::factory()->for($this->request)->create(['user_id' => null, 'action' => ActivityAction::ContactPurged]);

    timeline()->assertSeeInOrder(['Contact purged', 'System']);
});

test('step entries show the step and the system', function () {
    $this->workflow->completeStep($this->row, $this->step, $this->handler);
    $this->workflow->uncompleteStep($this->row, $this->step);

    timeline()
        ->assertSeeInOrder(['Step completed', 'Delete their threads · Modmail Bot'])
        ->assertSeeInOrder(['Step uncompleted', 'Delete their threads · Modmail Bot']);
});

test('entries about a deleted step still make sense', function () {
    $this->workflow->completeStep($this->row, $this->step, $this->handler);
    $this->step->delete();

    timeline()->assertSee('a removed step · Modmail Bot');
});

test('resolution entries show the system and the outcome, never the summary', function () {
    $this->workflow->resolveSystem($this->row, RequestSystemStatus::NoDataFound, 'Searched every log for them', $this->handler);

    timeline()
        ->assertSeeInOrder(['System resolved', 'Modmail Bot · No Data Found']);

    expect(Activity::where('action', ActivityAction::SystemResolved)->sole()->metadata)
        ->not->toHaveKey('summary');
});

test('reopening entries show the system', function () {
    $this->workflow->resolveSystem($this->row, RequestSystemStatus::Done, 'Done', $this->handler);
    $this->workflow->reopenSystem($this->row->fresh());

    timeline()->assertSeeInOrder(['System reopened', 'Modmail Bot']);
});

test('only the entries of this request are shown', function () {
    Activity::factory()->create(['action' => ActivityAction::RequestRejected]);

    timeline()->assertDontSee('Request rejected');
});

test('the timeline updates right after an action on the page', function () {
    timeline()
        ->assertDontSee('Step completed')
        ->call('toggleStep', $this->row->id, $this->step->id)
        ->assertSee('Step completed');
});

test('the timeline updates right after verifying the identity', function () {
    $this->request->forceFill(['status' => RequestStatus::VerifyingIdentity])->save();

    timeline()
        ->assertDontSee('Identity verified')
        ->call('verifyIdentity')
        ->assertSee('Identity verified');
});

test('every action has a label and an icon', function (ActivityAction $action) {
    expect($action->label())->not->toBeEmpty()
        ->and($action->icon())->toMatch('/^[a-z0-9-]+$/');
})->with(ActivityAction::cases());
