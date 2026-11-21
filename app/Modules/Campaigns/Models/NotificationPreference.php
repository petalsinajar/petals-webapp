<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A user's choice for one channel and category. No row means on. Read via Preferences. */
class NotificationPreference extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'channel', 'category', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
