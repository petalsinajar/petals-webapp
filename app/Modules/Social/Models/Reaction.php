<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    use HasUlids;

    protected $guarded = ['id'];
}
