<?php

declare(strict_types=1);

use App\Enums\RequestStatus;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Notifications\DeadlineDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

const COMMAND_TEST_WEBHOOK = 'https://discord.test/api/webhooks/789/token';

beforeEach(function () {
    config([
        'privacy.timezone' => 'UTC',
        'privacy.deadline_warning_days' => 7,
        'privacy.notifications.mail' => true,
        'privacy.notifications.discord_webhook_url' => COMMAND_TEST_WEBHOOK,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-02-10 08:00:00', 'UTC'));

    Notification::fake();
    Http::fake([COMMAND_TEST_WEBHOOK => Http::response(null, 204)]);
});

function commandRequest(string $dueAt): PrivacyRequest
{
    $request = PrivacyRequest::factory()->create(['due_at' => $dueAt]);
    $request->forceFill(['status' => RequestStatus::InProgress])->save();

    return $request;
}

test('nothing is sent when no request needs attention', function () {
    commandRequest('2026-03-20 23:59:59');

    $this->artisan('privacy:deadlines')
        ->expectsOutputToContain('No request needs attention.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('active staff are emailed and the discord channel is notified', function () {
    $lily = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $disabled = User::factory()->disabled()->create();
    $overdue = commandRequest('2026-02-05 23:59:59');
    $soon = commandRequest('2026-02-12 23:59:59');
    commandRequest('2026-03-20 23:59:59');

    $this->artisan('privacy:deadlines')->assertSuccessful();

    Notification::assertSentTo([$lily, $admin], DeadlineDigest::class, fn (DeadlineDigest $digest): bool => array_map(fn ($reminder): string => $reminder->reference, $digest->reminders) === [$overdue->reference, $soon->reference]);
    Notification::assertNotSentTo($disabled, DeadlineDigest::class);

    Http::assertSent(fn (Request $request): bool => str_contains($request['content'], $overdue->reference)
        && str_contains($request['content'], $soon->reference));
});

test('the summary counts the requests', function () {
    User::factory()->create();
    commandRequest('2026-02-05 23:59:59');
    commandRequest('2026-02-06 23:59:59');
    commandRequest('2026-02-12 23:59:59');

    $this->artisan('privacy:deadlines')
        ->expectsOutputToContain('Reminded staff about 3 requests')
        ->assertSuccessful();
});

test('the email channel can be turned off', function () {
    config(['privacy.notifications.mail' => false]);
    User::factory()->create();
    commandRequest('2026-02-05 23:59:59');

    $this->artisan('privacy:deadlines')->assertSuccessful();

    Notification::assertNothingSent();
    Http::assertSentCount(1);
});

test('the discord channel is off without a webhook url', function () {
    config(['privacy.notifications.discord_webhook_url' => null]);
    $user = User::factory()->create();
    commandRequest('2026-02-05 23:59:59');

    $this->artisan('privacy:deadlines')->assertSuccessful();

    Http::assertNothingSent();
    Notification::assertSentTo($user, DeadlineDigest::class);
});

test('a broken discord webhook does not stop the email, and is reported without leaking the url', function () {
    $brokenWebhook = 'https://discord.test/api/webhooks/000/broken-secret';
    config(['privacy.notifications.discord_webhook_url' => $brokenWebhook]);
    Http::fake([$brokenWebhook => Http::response('Unknown Webhook', 404)]);
    $user = User::factory()->create();
    commandRequest('2026-02-05 23:59:59');

    $this->artisan('privacy:deadlines')
        ->expectsOutputToContain('The Discord reminder could not be sent.')
        ->doesntExpectOutputToContain('broken-secret')
        ->assertFailed();

    Notification::assertSentTo($user, DeadlineDigest::class);
});

test('a failing email does not stop discord, and the command still fails', function () {
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
    commandRequest('2026-02-05 23:59:59');

    $this->artisan('privacy:deadlines')
        ->expectsOutputToContain('The reminder email could not be sent.')
        ->assertFailed();

    Http::assertSentCount(1);
});

test('the command fails when every channel fails', function () {
    $brokenWebhook = 'https://discord.test/api/webhooks/000/broken';
    config(['privacy.notifications.discord_webhook_url' => $brokenWebhook]);
    Http::fake([$brokenWebhook => Http::response('Unknown Webhook', 404)]);
    Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
    commandRequest('2026-02-05 23:59:59');

    $this->artisan('privacy:deadlines')->assertFailed();
});

test('closed requests are never reminded about', function () {
    $closed = commandRequest('2026-02-05 23:59:59');
    $closed->forceFill(['status' => RequestStatus::Completed, 'closed_at' => now()])->save();

    $this->artisan('privacy:deadlines')->expectsOutputToContain('No request needs attention.');

    Http::assertNothingSent();
});

test('the reminders are scheduled every morning in the organization time zone', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'privacy:deadlines'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
