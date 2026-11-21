<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Campaigns\Services\SegmentQuery;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A saved, reusable audience: filters applied by SegmentQuery when a campaign runs. */
class CampaignSegment extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'filters', 'created_by'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /** @return Builder<User> */
    public function users(): Builder
    {
        return SegmentQuery::users($this->filters ?? []);
    }
}
