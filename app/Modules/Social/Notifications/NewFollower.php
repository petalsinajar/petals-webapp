<?php

namespace App\Modules\Social\Notifications;

use App\Modules\Identity\Models\User;
use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewFollower extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly User $follower, public readonly Actor $target) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
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
