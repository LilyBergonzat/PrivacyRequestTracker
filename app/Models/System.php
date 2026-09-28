<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $response_period_days
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'response_period_days', 'is_active'])]
class System extends Model
{
    /** @use HasFactory<SystemFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return HasMany<SystemStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SystemStep::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'response_period_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (System $system): void {
            if (empty($system->slug)) {
                $system->slug = Str::slug($system->name) ?: 'system';
            }

            $originalSlug = $system->slug;
            $suffixNumber = 1;

            while (System::where('slug', $system->slug)->exists()) {
                $system->slug = $originalSlug . '-' . ++$suffixNumber;
            }

            if (!$system->isDirty('sort_order')) {
                $system->sort_order = (System::max('sort_order') ?? -1) + 1;
            }
        });
    }

    /**
     * @param  Builder<System>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<System>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
