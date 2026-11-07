<?php

namespace App\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
