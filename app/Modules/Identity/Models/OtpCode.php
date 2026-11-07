<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasUlids, Prunable;

    protected $fillable = ['phone', 'code_hash', 'expires_at'];

    protected $hidden = ['code_hash'];

    /** Old codes are deleted daily by model:prune. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
