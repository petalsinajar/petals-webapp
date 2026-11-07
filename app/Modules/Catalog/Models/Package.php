<?php

namespace App\Modules\Catalog\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * A vendor bundle booked in one tap, e.g. "Kids' party for 30".
 * lines: [{ item_id, variant_id, qty }]. price_minor is the fixed bundle price.
 *
 * @property string $id
 */
class Package extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'packages';

    protected $fillable = [
        'vendor_id', 'title', 'description', 'event_types', 'guest_min', 'guest_max',
        'lines', 'price_minor', 'currency', 'media', 'status',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'lines' => 'array',
            'media' => 'array',
            'price_minor' => 'integer',
            'guest_min' => 'integer',
            'guest_max' => 'integer',
        ];
    }
}
