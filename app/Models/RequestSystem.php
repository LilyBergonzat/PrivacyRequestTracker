<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestSystemStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RequestSystemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $privacy_request_id
 * @property int $system_id
 * @property CarbonImmutable $due_at
 * @property RequestSystemStatus $status
 * @property string|null $result_summary
 * @property int|null $completed_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RequestSystem extends Model
{
    /** @use HasFactory<RequestSystemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestSystemStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'result_summary' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<PrivacyRequest, $this>
     */
    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }

    /**
     * @return BelongsTo<System, $this>
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<StepCompletion, $this>
     */
    public function stepCompletions(): HasMany
    {
        return $this->hasMany(StepCompletion::class);
    }
}
