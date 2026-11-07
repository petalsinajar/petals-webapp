<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Marketing\Enums\WaitlistRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WaitlistSignup extends Model
{
    use HasUlids;

    protected $fillable = [
        'name', 'whatsapp_phone', 'city', 'role', 'business_name', 'category', 'event_types', 'source',
    ];

    /** Database defaults, mirrored so new models have them before a refresh. */
    protected $attributes = [
        'business_name' => null,
        'category' => null,
        'event_types' => null,
        'source' => null,
    ];

    protected function casts(): array
    {
        return [
            'role' => WaitlistRole::class,
            'event_types' => 'array',
            'source' => 'array',
        ];
    }
}
