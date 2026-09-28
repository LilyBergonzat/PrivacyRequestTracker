<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequestType;
use Carbon\CarbonImmutable;
use Database\Factories\SystemStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $system_id
 * @property RequestType|null $request_type
 * @property string $title
 * @property string $instructions
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['request_type', 'title', 'instructions', 'is_active'])]
class SystemStep extends Model
{
    /** @use HasFactory<SystemStepFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $this->instructions, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return BelongsTo<System, $this>
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => RequestType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SystemStep $step): void {
            if (!$step->isDirty('sort_order')) {
                $step->sort_order = (SystemStep::where('system_id', $step->system_id)->max('sort_order') ?? -1) + 1;
            }
        });
    }

    /**
     * @param  Builder<SystemStep>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<SystemStep>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<SystemStep>  $query
     */
    #[Scope]
    protected function forRequestType(Builder $query, RequestType $type): void
    {
        $query->where(
            fn (Builder $q): Builder => $q->whereNull('request_type')->orWhere('request_type', $type)
        );
    }
}
