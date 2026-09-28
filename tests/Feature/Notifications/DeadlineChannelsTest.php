<?php

declare(strict_types=1);

use App\Enums\DeadlineStatus;
use App\Enums\RequestType;
use App\Models\User;
use App\Notifications\DeadlineDigest;
use App\Services\DiscordWebhookNotifier;
use App\ValueObjects\DeadlineReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

const DISCORD_TEST_WEBHOOK = 'https://discord.test/api/webhooks/123/secret-token';

beforeEach(function () {
    config(['privacy.timezone' => 'UTC']);
});

function reminder(string $reference, DeadlineStatus $status = DeadlineStatus::Overdue, string $deadline = '2026-02-05 23:59:59'): DeadlineReminder
{
    return new DeadlineReminder(
        $reference,
        RequestType::Deletion,
        CarbonImmutable::parse($deadline, 'UTC'),
        $status,
        'https://privacy.test/requests/' . $reference,
    );
}

/**
 * @return list<DeadlineReminder>
 */
function manyReminders(int $count): array
{
    return array_map(fn (int $index): DeadlineReminder => reminder(sprintf('DSR-%06d', $index)), range(1, $count));
}

describe('summary line', function () {
    test('an overdue request says since when', function () {
        expect(reminder('DSR-AAAAAA')->summary())->toBe('DSR-AAAAAA · Deletion · overdue since February 5, 2026');
    });

    test('a request due soon says when', function () {
        expect(reminder('DSR-BBBBBB', DeadlineStatus::Soon, '2026-02-14 23:59:59')->summary())
            ->toBe('DSR-BBBBBB · Deletion · due February 14, 2026');
    });

    test('the date is shown in the organization time zone', function () {
        config(['privacy.timezone' => 'Asia/Tokyo']);

        expect(reminder('DSR-CCCCCC', deadline: '2026-02-05 20:00:00')->summary())->toContain('February 6, 2026');
    });
});

describe('email', function () {
    test('the email lists every request as a link, plus a dashboard button', function () {
        $mail = (new DeadlineDigest([
            reminder('DSR-AAAAAA'),
            reminder('DSR-BBBBBB', DeadlineStatus::Soon, '2026-02-14 23:59:59'),
        ]))->toMail(User::factory()->make());

        expect($mail->subject)->toBe('2 privacy requests need attention')
            ->and($mail->introLines)->toContain('[DSR-AAAAAA · Deletion · overdue since February 5, 2026](https://privacy.test/requests/DSR-AAAAAA)')
            ->and($mail->introLines)->toContain('[DSR-BBBBBB · Deletion · due February 14, 2026](https://privacy.test/requests/DSR-BBBBBB)')
            ->and($mail->actionUrl)->toBe(route('dashboard'));
    });

    test('the subject is singular for a single request', function () {
        $mail = (new DeadlineDigest([reminder('DSR-AAAAAA')]))->toMail(User::factory()->make());

        expect($mail->subject)->toBe('1 privacy request needs attention');
    });

    test('the email is only sent by mail', function () {
        expect((new DeadlineDigest([reminder('DSR-AAAAAA')]))->via(User::factory()->make()))->toBe(['mail']);
    });

    test('the rendered email contains no personal data field at all', function () {
        $rendered = (string) (new DeadlineDigest([reminder('DSR-AAAAAA')]))->toMail(User::factory()->make())->render();

        expect($rendered)->toContain('DSR-AAAAAA')
            ->toContain('https://privacy.test/requests/DSR-AAAAAA');
    });
});

describe('discord', function () {
    beforeEach(function () {
        Http::fake([DISCORD_TEST_WEBHOOK => Http::response(null, 204)]);
    });

    test('nothing is sent without a webhook url', function (?string $url) {
        (new DiscordWebhookNotifier($url))->send([reminder('DSR-AAAAAA')]);

        Http::assertNothingSent();
    })->with(['null' => null, 'empty' => '']);

    test('the message lists every request, without pinging anyone', function () {
        (new DiscordWebhookNotifier(DISCORD_TEST_WEBHOOK))->send([
            reminder('DSR-AAAAAA'),
            reminder('DSR-BBBBBB', DeadlineStatus::Soon, '2026-02-14 23:59:59'),
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === DISCORD_TEST_WEBHOOK
                && $request['allowed_mentions'] === ['parse' => []]
                && str_contains($request['content'], '2 privacy requests need attention')
                && str_contains($request['content'], '[DSR-AAAAAA · Deletion · overdue since February 5, 2026](https://privacy.test/requests/DSR-AAAAAA)')
                && str_contains($request['content'], '[DSR-BBBBBB · Deletion · due February 14, 2026](https://privacy.test/requests/DSR-BBBBBB)');
        });
    });

    test('the title is singular for a single request', function () {
        (new DiscordWebhookNotifier(DISCORD_TEST_WEBHOOK))->send([reminder('DSR-AAAAAA')]);

        Http::assertSent(fn (Request $request): bool => str_contains($request['content'], '1 privacy request needs attention'));
    });

    test('the message does not end with blank lines', function () {
        (new DiscordWebhookNotifier(DISCORD_TEST_WEBHOOK))->send([reminder('DSR-AAAAAA'), reminder('DSR-BBBBBB')]);

        Http::assertSent(fn (Request $request): bool => $request['content'] === rtrim($request['content'])
            && mb_strlen($request['content']) < 500);
    });

    test('a long list stays under the discord limit and says how many were left out', function () {
        (new DiscordWebhookNotifier(DISCORD_TEST_WEBHOOK))->send(manyReminders(60));

        Http::assertSent(function (Request $request): bool {
            $content = $request['content'];
            preg_match('/and (\d+) more/', $content, $more);
            $shown = substr_count($content, '](https://privacy.test/requests/');

            return mb_strlen($content) <= 2000
                && isset($more[1])
                && $shown + (int) $more[1] === 60;
        });
    });

    test('a failing webhook is reported instead of being silently ignored', function () {
        $deletedWebhook = 'https://discord.test/api/webhooks/456/deleted';
        Http::fake([$deletedWebhook => Http::response('Unknown Webhook', 404)]);

        (new DiscordWebhookNotifier($deletedWebhook))->send([reminder('DSR-AAAAAA')]);
    })->throws(RequestException::class);
});
