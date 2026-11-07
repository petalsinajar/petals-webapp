<?php

namespace App\Modules\Social\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Social\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Grouped by post in the app: "Ama and 12 others liked your post". */
class PostLiked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Post $post, public readonly User $liker) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_liked',
            'actor' => ['type' => 'user', 'id' => $this->liker->id, 'handle' => $this->liker->handle,
                'name' => $this->liker->display_name, 'avatar_url' => $this->liker->avatar_url],
            'target' => ['type' => 'post', 'id' => $this->post->id],
            'thumbnail' => $this->post->media[0]['url'] ?? null,
            'group_key' => 'post_liked:'.$this->post->id,
        ];
    }
}
