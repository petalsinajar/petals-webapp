<?php

namespace App\Modules\Social\Notifications;

use App\Modules\Identity\Models\User;
use App\Support\Actor;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewFollower extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly User $follower, public readonly Actor $target) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $name = $this->follower->display_name ?: '@'.$this->follower->handle;
        $whom = $this->target->type === Actor::VENDOR ? 'your business' : 'you';

        // Profiles aren't in the app yet, so the tap just opens it.
        return new PushMessage(
            'New follower',
            "{$name} started following {$whom}.",
            forBusiness: $this->target->type === Actor::VENDOR,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_follower',
            'actor' => ['type' => 'user', 'id' => $this->follower->id, 'handle' => $this->follower->handle,
                'name' => $this->follower->display_name, 'avatar_url' => $this->follower->avatar_url],
            'target' => $this->target->toArray(),
            'vendor_id' => $this->target->type === Actor::VENDOR ? $this->target->id : null,
            'group_key' => 'new_follower:'.$this->target->type.':'.$this->target->id,
        ];
    }
}
