<?php

namespace App\Modules\Recommendations\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Modules\Recommendations\Enums\RecommendationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'score' => 0,
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'reason' => RecommendationReason::class,
            'status' => RecommendationStatus::class,
            'score' => 'float',
            'details' => 'array',
            'sent_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
