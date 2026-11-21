<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\Recurrence;
use App\Modules\Campaigns\Services\SegmentQuery;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message to an audience on one or more channels. Status only changes through
 * CampaignService; sending goes through CampaignDispatcher. Automations (lifecycle
 * messages) are campaigns with an `automation` key whose audience comes from code.
 */
class Campaign extends Model
{
    use HasUlids;

    protected $fillable = [
        'name', 'channels', 'category', 'title', 'body', 'sms_body', 'deep_link', 'image_media_id',
        'segment_id', 'scheduled_at', 'recurrence', 'active', 'created_by',
    ];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'status' => 'draft',
        'active' => false,
        'requires_approval' => false,
        'audience_count' => 0,
        'estimated_sms_cost_minor' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'recurrence' => Recurrence::class,
            'channels' => 'array',
            'deep_link' => 'array',
            'audience_filters' => 'array',
            'active' => 'boolean',
            'requires_approval' => 'boolean',
            'scheduled_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    /** @return list<Channel> */
    public function channelList(): array
    {
        return array_values(array_map(fn (string $c) => Channel::from($c), $this->channels ?? []));
    }

    public function sendsOn(Channel $channel): bool
    {
        return in_array($channel->value, $this->channels ?? [], true);
    }

    public function isMarketing(): bool
    {
        return in_array($this->category, config('eventapp.campaigns.marketing_categories'), true);
    }

    public function isAutomation(): bool
    {
        return $this->automation !== null;
    }

    /**
     * Who the campaign reaches: the filters frozen at submit, or the segment's current ones
     * while it's still a draft. Null when there's no audience (automations).
     *
     * @return ?Builder<User>
     */
    public function audience(): ?Builder
    {
        $filters = $this->audience_filters ?? $this->segment?->filters;

        return $filters === null && $this->segment === null ? null : SegmentQuery::users($filters ?? []);
    }

    /** @return BelongsTo<CampaignSegment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(CampaignSegment::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
