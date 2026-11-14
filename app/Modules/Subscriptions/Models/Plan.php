<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A vendor plan. Limits ({ items, packages, team }, null = unlimited) and features
 * ({ analytics: level, web_dashboard: bool, ... }) are data, edited in the admin panel.
 */
class Plan extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'currency' => 'GHS',
        'featured_weeks_per_month' => 0,
        'sort' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price_monthly_minor' => 'integer',
            'price_yearly_minor' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'commission_percent' => 'float',
            'featured_weeks_per_month' => 'integer',
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function byKey(string $key): self
    {
        return static::where('key', $key)->firstOrFail();
    }

    public static function default(): self
    {
        return static::byKey(config('eventapp.subscriptions.default_plan'));
    }

    /** @param  Builder<Plan>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('price_monthly_minor');
    }

    public function price(BillingInterval $interval): int
    {
        return $interval === BillingInterval::Month ? $this->price_monthly_minor : $this->price_yearly_minor;
    }

    public function isFree(): bool
    {
        return $this->price_monthly_minor === 0;
    }

    /** Null means unlimited. */
    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function feature(string $key): mixed
    {
        return $this->features[$key] ?? false;
    }

    public function visibilityBoost(): float
    {
        $boost = (float) config("eventapp.visibility.boost.{$this->visibility_level}", 0);

        return min($boost, (float) config('eventapp.visibility.max_boost'));
    }
}
