<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person on one channel for one run of a campaign. Unique, so nothing is sent twice. */
class CampaignRecipient extends Model
{
    use HasUlids;

    protected $fillable = ['campaign_id', 'user_id', 'channel', 'run_key', 'context', 'status', 'skip_reason'];

    protected $attributes = [
        'run_key' => '',
        'status' => 'queued',
        'cost_minor' => 0,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => RecipientStatus::class,
            'skip_reason' => SkipReason::class,
            'context' => 'array',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
