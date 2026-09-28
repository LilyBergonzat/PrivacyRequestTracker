<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\DeadlineReminder;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class DiscordWebhookNotifier
{
    public function __construct(
        #[Config('privacy.notifications.discord_webhook_url')] private readonly ?string $webhookUrl
    ) {}

    /**
     * @param  list<DeadlineReminder>  $reminders
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function send(array $reminders): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        $title = '## ' . trans_choice(':count privacy request needs attention|:count privacy requests need attention', count($reminders));
        $reminderLines = array_map(function (DeadlineReminder $reminder): string {
            return '- ' . $reminder->status->emoji() . ' [' . $reminder->summary() . '](' . $reminder->url . ')';
        }, $reminders);
        $remainderLine = '... and :count more.';

        $content = $title;
        $maxLengthReached = false;

        while (count($reminderLines) > 0) {
            $nextLine = "\n" . array_shift($reminderLines);

            if (mb_strlen($content) + mb_strlen($nextLine) + mb_strlen("\n" . __($remainderLine, ['count' => count($reminderLines)])) >= 2000) {
                $maxLengthReached = true;
                break;
            }

            $content .= $nextLine;
        }

        if ($maxLengthReached) {
            $content .= "\n" . __($remainderLine, ['count' => count($reminderLines) + 1]);
        }

        Http::post($this->webhookUrl, [
            'content' => $content,
            'allowed_mentions' => ['parse' => []],
        ])->throw();
    }
}
