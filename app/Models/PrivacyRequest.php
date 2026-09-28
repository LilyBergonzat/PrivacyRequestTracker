<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactMethod;
use App\Enums\NotificationOutcome;
use App\Enums\RequestChannel;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use Carbon\CarbonImmutable;
use Database\Factories\PrivacyRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $reference
 * @property RequestType $type
 * @property RequestChannel $channel
 * @property RequestStatus $status
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $extended_until
 * @property string|null $extension_reason
 * @property CarbonImmutable|null $extension_notified_at
 * @property string|null $identity_verification_notes
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property ContactMethod $contact_method
 * @property string|null $contact_details
 * @property CarbonImmutable|null $notified_at
 * @property NotificationOutcome|null $notification_outcome
 * @property string|null $notification_notes
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $purge_after
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'channel', 'received_at', 'contact_method', 'contact_details', 'notes'])]
class PrivacyRequest extends Model
{
    /** @use HasFactory<PrivacyRequestFactory> */
    use HasFactory;

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $attributes = [
        'status' => 'received',
    ];

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RequestIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(RequestIdentifier::class);
    }

    /**
     * @return HasMany<RequestSystem, $this>
     */
    public function requestSystems(): HasMany
    {
        return $this->hasMany(RequestSystem::class);
    }

    public function effectiveDeadline(): CarbonImmutable
    {
        if ($this->extended_until !== null) {
            return $this->extended_until;
        }

        $earliestUnresolved = $this->requestSystems->filter(
            fn (RequestSystem $row): bool => !$row->status->isResolved()
        )->min('due_at');

        if ($earliestUnresolved !== null) {
            return $earliestUnresolved;
        }

        return $this->due_at;
    }

    protected static function booted(): void
    {
        static::creating(function (PrivacyRequest $request): void {
            if (empty($request->reference)) {
                do {
                    $code = Collection::times(
                        6,
                        fn (): string => self::ALPHABET[random_int(
                            0,
                            strlen(self::ALPHABET) - 1
                        )]
                    )->implode('');
                } while (static::where('reference', 'DSR-' . $code)->exists());

                $request->reference = 'DSR-' . $code;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RequestType::class,
            'channel' => RequestChannel::class,
            'status' => RequestStatus::class,
            'contact_method' => ContactMethod::class,
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'extended_until' => 'datetime',
            'extension_notified_at' => 'datetime',
            'closed_at' => 'datetime',
            'purge_after' => 'date',
            'extension_reason' => 'encrypted',
            'identity_verification_notes' => 'encrypted',
            'rejection_reason' => 'encrypted',
            'notes' => 'encrypted',
            'contact_details' => 'encrypted',
            'notified_at' => 'datetime',
            'notification_outcome' => NotificationOutcome::class,
            'notification_notes' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<PrivacyRequest>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNotIn('status', [RequestStatus::Completed, RequestStatus::Rejected]);
    }
}
