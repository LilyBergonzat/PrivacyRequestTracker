<?php

declare(strict_types=1);

namespace App\Notifications;

use App\ValueObjects\DeadlineReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineDigest extends Notification
{
    use Queueable;

    /** @param list<DeadlineReminder> $reminders */
    public function __construct(public readonly array $reminders) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(trans_choice(':count privacy request needs attention|:count privacy requests need attention', count($this->reminders)))
            ->line(__('These requests are overdue or due soon:'));

        foreach ($this->reminders as $reminder) {
            $mail->line('[' . $reminder->summary() . '](' . $reminder->url . ')');
        }

        return $mail->action(__('Open the dashboard'), route('dashboard'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
