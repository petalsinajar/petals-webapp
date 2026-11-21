<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A number that gets no marketing SMS (replied STOP, or an admin opted it out). E.164. */
class SmsOptOut extends Model
{
    use HasUlids;

    protected $fillable = ['phone', 'source'];
}
