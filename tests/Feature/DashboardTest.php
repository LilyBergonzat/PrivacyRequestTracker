<?php

declare(strict_types=1);

use App\Enums\DeadlineStatus;
use App\Enums\RequestStatus;
use App\Enums\RequestSystemStatus;
use App\Models\PrivacyRequest;
use App\Models\RequestSystem;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['privacy.timezone' => 'UTC', 'privacy.deadline_warning_days' => 7]);
    $this->travelTo(CarbonImmutable::parse('2026-02-10 10:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardRequest(string $dueAt, RequestStatus $status = RequestStatus::InProgress, array $attributes = []): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create(['due_at' => $dueAt]);
    $request->forceFill(['status' => $status, ...$attributes])->save();

    return $request;
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();
});

test('an empty state is shown when nothing is open', function () {
    dashboardRequest('2026-02-01 23:59:59', RequestStatus::Completed, ['closed_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSee('Nothing to do right now.')
        ->assertSee(route('requests.create'));
});

test('open requests are listed by effective deadline, most urgent first', function () {
    $later = dashboardRequest('2026-03-01 23:59:59');
    $overdue = dashboardRequest('2026-02-05 23:59:59');
    $soon = dashboardRequest('2026-02-12 23:59:59');

    $extended = dashboardRequest('2026-02-06 23:59:59', attributes: ['extended_until' => '2026-04-01 23:59:59']);

    $systemFirst = dashboardRequest('2026-03-01 23:59:59');
    RequestSystem::factory()->for($systemFirst)->create(['due_at' => '2026-02-11 23:59:59']);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSeeInOrder([
            $overdue->reference,
            $systemFirst->reference,
            $soon->reference,
            $later->reference,
            $extended->reference,
        ]);
});

test('closed and rejected requests are not listed', function () {
    $completed = dashboardRequest('2026-02-01 23:59:59', RequestStatus::Completed, ['closed_at' => now()]);
    $rejected = dashboardRequest('2026-02-01 23:59:59', RequestStatus::Rejected, ['closed_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertDontSee($completed->reference)
        ->assertDontSee($rejected->reference);
});

test('every open status is listed', function (RequestStatus $status) {
    $request = dashboardRequest('2026-03-01 23:59:59', $status);

    $this->actingAs($this->user)->get(route('dashboard'))->assertSee($request->reference);
})->with([
    RequestStatus::Received,
    RequestStatus::VerifyingIdentity,
    RequestStatus::InProgress,
    RequestStatus::AwaitingNotification,
]);

test('each request links to its page and shows its progress', function () {
    $request = dashboardRequest('2026-03-01 23:59:59');
    RequestSystem::factory()->for($request)->resolved(RequestSystemStatus::Done)->create();
    RequestSystem::factory()->for($request)->create(['due_at' => '2026-03-01 23:59:59']);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSee(route('requests.show', $request))
        ->assertSee('1 of 2 resolved');
});

test('the counters show open, overdue and recently closed requests', function () {
    dashboardRequest('2026-02-01 23:59:59');
    dashboardRequest('2026-02-05 23:59:59');
    dashboardRequest('2026-03-01 23:59:59');

    dashboardRequest('2026-01-20 23:59:59', RequestStatus::Completed, ['closed_at' => '2026-02-01 10:00:00']);
    dashboardRequest('2026-01-20 23:59:59', RequestStatus::Rejected, ['closed_at' => '2026-01-20 10:00:00']);
    dashboardRequest('2025-12-01 23:59:59', RequestStatus::Completed, ['closed_at' => '2025-12-20 10:00:00']);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertSeeInOrder(['Open requests', '3', 'Overdue', '2', 'Closed in the last 30 days', '2']);
});

test('each deadline is shown relative to now, with its urgency', function () {
    $overdue = dashboardRequest('2026-02-05 23:59:59');
    $soon = dashboardRequest('2026-02-12 23:59:59');
    $onTrack = dashboardRequest('2026-03-01 23:59:59');

    $page = Livewire\Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->assertSeeInOrder(['4 days ago', '2 days from now', '2 weeks from now']);

    expect($page->instance()->deadlineStatusOf($overdue))->toBe(DeadlineStatus::Overdue)
        ->and($page->instance()->deadlineStatusOf($soon))->toBe(DeadlineStatus::Soon)
        ->and($page->instance()->deadlineStatusOf($onTrack))->toBe(DeadlineStatus::OnTrack);
});

test('the dashboard is the home page', function () {
    $this->actingAs($this->user)->get(route('home'))->assertRedirect('/dashboard');
});
