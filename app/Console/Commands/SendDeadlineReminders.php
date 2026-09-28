<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DeadlineDigest;
use App\Services\DeadlineReminderCollector;
use App\Services\DiscordWebhookNotifier;
use App\ValueObjects\DeadlineReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

#[Signature('privacy:deadlines')]
#[Description('Remind staff about privacy requests that are overdue or due soon')]
class SendDeadlineReminders extends Command
{
    public function handle(DeadlineReminderCollector $collector, DiscordWebhookNotifier $discord): int
    {
        $reminders = $collector->collect(now()->toImmutable());

        if (count($reminders) < 1) {
            $this->info(__('No request needs attention.'));

            return self::SUCCESS;
        }

        $emailSuccess = $this->notifyByEmail($reminders) === self::SUCCESS;
        $discordSuccess = $this->notifyByDiscord($reminders, $discord) === self::SUCCESS;

        if (!$emailSuccess && !$discordSuccess) {
            $this->error(trans_choice(
                'Unable to remind staff about :count request|Unable to remind staff about :count requests',
                count($reminders)
            ));

            return self::FAILURE;
        }

        if (!$emailSuccess || !$discordSuccess) {
            $this->warn(
                !$emailSuccess
                    ? __('The reminder email could not be sent.')
                    : __('The Discord reminder could not be sent.')
            );
        }

        $this->info(trans_choice(
            'Reminded staff about :count request|Reminded staff about :count requests',
            count($reminders)
        ));

        return $emailSuccess && $discordSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<DeadlineReminder>  $reminders
     */
    private function notifyByEmail(array $reminders): int
    {
        if (!config('privacy.notifications.mail')) {
            return self::SUCCESS;
        }

        try {
            $staff = User::whereNull('disabled_at')->get();
            Notification::send($staff, new DeadlineDigest($reminders));

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);

            return self::FAILURE;
        }
    }

    /**
     * @param  list<DeadlineReminder>  $reminders
     */
    private function notifyByDiscord(array $reminders, DiscordWebhookNotifier $discord): int
    {
        try {
            $discord->send($reminders);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);

            return self::FAILURE;
        }
    }
}
