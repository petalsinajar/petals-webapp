<?php

namespace App\Modules\Catalog\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Platform-managed category tree (two levels). The id is a slug, e.g. "chairs".
 *
 * @property string $id
 */
class Category extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['_id', 'name', 'parent_id', 'icon', 'attributes_schema', 'order'];

    protected function casts(): array
    {
        return [
            'attributes_schema' => 'array', // [{key, type, options}]
            'order' => 'integer',
        ];
    }
}
