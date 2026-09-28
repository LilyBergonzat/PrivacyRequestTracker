<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityAction;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int|null $privacy_request_id
 * @property int|null $user_id
 * @property ActivityAction $action
 * @property array<string, int|bool|string|null>|null $metadata
 * @property CarbonImmutable $created_at
 */
#[Fillable(['action', 'metadata'])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /**
     * @return BelongsTo<PrivacyRequest, $this>
     */
    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $appendOnlyCallback = function (): void {
            throw new LogicException('The activity log is append-only');
        };

        static::updating($appendOnlyCallback);
        static::deleting($appendOnlyCallback);
    }
}
