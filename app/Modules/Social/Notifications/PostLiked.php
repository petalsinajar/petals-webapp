<?php

namespace App\Modules\Social\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Social\Models\Post;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Grouped by post in the app: "Ama and 12 others liked your post". */
class PostLiked extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Post $post, public readonly User $liker) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $name = $this->liker->display_name ?: '@'.$this->liker->handle;

        // The app has no post screen yet, so the tap just opens it.
        return new PushMessage('New like', "{$name} liked your post.", data: ['post_id' => $this->post->id]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'post_liked',
            'actor' => ['type' => 'user', 'id' => $this->liker->id, 'handle' => $this->liker->handle,
                'name' => $this->liker->display_name, 'avatar_url' => $this->liker->avatar_url],
            'target' => ['type' => 'post', 'id' => $this->post->id],
            'thumbnail' => $this->post->media->first()?->url('thumb'),
            'group_key' => 'post_liked:'.$this->post->id,
        ];
    }
}
