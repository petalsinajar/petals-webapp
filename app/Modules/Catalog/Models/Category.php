<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-managed category tree (two levels). The id is a slug, e.g. "chairs".
 *
 * @property string $id
 */
class Category extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'parent_id', 'icon', 'attributes_schema', 'sort'];

    protected $attributes = ['sort' => 0];

    protected function casts(): array
    {
        return [
            'attributes_schema' => 'array', // [{key, type, options}]
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }
}
