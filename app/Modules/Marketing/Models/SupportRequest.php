<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Enums\SupportTopic;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequest extends Model
{
    use HasUlids;

    protected $fillable = ['topic', 'name', 'email', 'phone', 'message', 'user_id'];

    protected function casts(): array
    {
        return [
            'topic' => SupportTopic::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
