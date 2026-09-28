<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityAction: string
{
    case RequestCreated = 'request.created';
    case IdentityVerified = 'identity.verified';
    case StepCompleted = 'step.completed';
    case StepUncompleted = 'step.uncompleted';
    case SystemResolved = 'system.resolved';
    case SystemReopened = 'system.reopened';
    case RequestExtended = 'request.extended';
    case RequesterNotified = 'requester.notified';
    case RequestClosed = 'request.closed';
    case RequestRejected = 'request.rejected';
    case ContactPurged = 'contact.purged';
    case RequestPurged = 'request.purged';
    case LookupPerformed = 'lookup.performed';
    case RecordsExported = 'records.exported';

    public function label(): string
    {
        return match ($this) {
            self::RequestCreated => __('Request created'),
            self::IdentityVerified => __('Identity verified'),
            self::StepCompleted => __('Step completed'),
            self::StepUncompleted => __('Step uncompleted'),
            self::SystemResolved => __('System resolved'),
            self::SystemReopened => __('System reopened'),
            self::RequestExtended => __('Request extended'),
            self::RequesterNotified => __('Requester notified'),
            self::RequestClosed => __('Request closed'),
            self::RequestRejected => __('Request rejected'),
            self::ContactPurged => __('Contact purged'),
            self::RequestPurged => __('Request purged'),
            self::LookupPerformed => __('Lookup performed'),
            self::RecordsExported => __('Records exported'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::RequestCreated => 'plus-circle',
            self::IdentityVerified => 'identification',
            self::StepCompleted => 'check',
            self::StepUncompleted => 'arrow-uturn-left',
            self::SystemResolved => 'check-badge',
            self::SystemReopened => 'arrow-path',
            self::RequestExtended => 'chevron-double-up',
            self::RequesterNotified => 'bell-alert',
            self::RequestClosed => 'check-circle',
            self::RequestRejected => 'no-symbol',
            self::ContactPurged => 'user-minus',
            self::RequestPurged => 'trash',
            self::LookupPerformed => 'magnifying-glass',
            self::RecordsExported => 'arrow-down-tray',
        };
    }
}
