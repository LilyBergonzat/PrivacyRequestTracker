<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StepCompletionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $request_system_id
 * @property int|null $system_step_id
 * @property string $step_title
 * @property int $completed_by
 * @property CarbonImmutable $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class StepCompletion extends Model
{
    /** @use HasFactory<StepCompletionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RequestSystem, $this>
     */
    public function requestSystem(): BelongsTo
    {
        return $this->belongsTo(RequestSystem::class);
    }

    /**
     * @return BelongsTo<SystemStep, $this>
     */
    public function systemStep(): BelongsTo
    {
        return $this->belongsTo(SystemStep::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
