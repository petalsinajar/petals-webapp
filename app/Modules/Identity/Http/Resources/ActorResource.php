<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact author card used in feeds, comments and notifications. */
class ActorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isUser = $this->resource instanceof User;

        return [
            'type' => $isUser ? 'user' : 'vendor',
            'id' => $this->id,
            'handle' => $this->handle,
            'name' => $isUser ? $this->display_name : $this->name,
            'avatar_url' => $isUser ? $this->avatar_url : $this->logo_url,
            'is_verified' => $isUser ? false : $this->isVerified(),
        ];
    }
}
