<?php

namespace App\Modules\Subscriptions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price_monthly_minor' => $this->price_monthly_minor,
            'price_yearly_minor' => $this->price_yearly_minor,
            'currency' => $this->currency,
            'limits' => [
                'items' => $this->limit('items'),
                'packages' => $this->limit('packages'),
                'team' => $this->limit('team'),
            ],
            'features' => $this->features ?? (object) [],
            'commission_percent' => $this->commission_percent,
            'visibility_level' => $this->visibility_level,
            'featured_weeks_per_month' => $this->featured_weeks_per_month,
        ];
    }
}
