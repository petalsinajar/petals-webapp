<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per follower per post: the Following feed, fanned out on write. */
class FeedItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
