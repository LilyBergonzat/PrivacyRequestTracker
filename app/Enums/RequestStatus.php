<?php

declare(strict_types=1);

namespace App\Enums;

enum RequestStatus: string
{
    /**
     * @var list<RequestStatus> COMPLETED_STATUSES
     */
    private const array CLOSED_STATUSES = [self::Completed, self::Rejected];

    private const array CAN_VERIFY_IDENTITY_STATUSES = [self::Received, self::VerifyingIdentity];

    private const array CAN_REOPEN_STATUSES = [self::InProgress, self::AwaitingNotification];

    case Received = 'received';
    case VerifyingIdentity = 'verifying_identity';
    case InProgress = 'in_progress';
    case AwaitingNotification = 'awaiting_notification';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => __('Received'),
            self::VerifyingIdentity => __('Verifying Identity'),
            self::InProgress => __('In Progress'),
            self::AwaitingNotification => __('Awaiting Notification'),
            self::Completed => __('Completed'),
            self::Rejected => __('Rejected'),
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, self::CLOSED_STATUSES, true);
    }

    public function canVerifyIdentity(): bool
    {
        return in_array($this, self::CAN_VERIFY_IDENTITY_STATUSES, true);
    }

    public function canReopen(): bool
    {
        return in_array($this, self::CAN_REOPEN_STATUSES, true);
    }
}
