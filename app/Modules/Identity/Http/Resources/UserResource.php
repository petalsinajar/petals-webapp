<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public profile of a person. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => 'user',
            'id' => $this->id,
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url, // the avatar's thumb; `avatar` has every size
            'avatar' => $this->avatar?->toPayload(),
            'bio' => $this->bio,
            'city' => $this->city,
            'is_private' => $this->is_private,
            'counts' => [
                'followers' => $this->followers_count,
                'following' => $this->following_count,
                'posts' => $this->posts_count,
            ],
            'is_following' => $this->whenHas('is_following', fn () => (bool) $this->is_following),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
