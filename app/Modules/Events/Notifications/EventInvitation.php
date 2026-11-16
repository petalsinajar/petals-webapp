<?php

namespace App\Modules\Events\Notifications;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** "Ama added you as a co-host of …" / "Ama invited you to …". Opens the event. */
class EventInvitation extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Event $event,
        public readonly User $inviter,
        public readonly string $role,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $name = $this->inviter->display_name ?: '@'.$this->inviter->handle;
        $body = $this->role === 'cohost'
            ? "{$name} added you as a co-host of {$this->event->title}."
            : "{$name} invited you to {$this->event->title}.";

        return new PushMessage(
            $this->role === 'cohost' ? 'You’re a co-host' : 'You’re invited',
            $body,
            route: "/events/{$this->event->id}",
            data: ['event_id' => $this->event->id],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->role === 'cohost' ? 'event_cohost' : 'event_invite',
            'actor' => ['type' => 'user', 'id' => $this->inviter->id, 'handle' => $this->inviter->handle,
                'name' => $this->inviter->display_name, 'avatar_url' => $this->inviter->avatar_url],
            'target' => ['type' => 'event', 'id' => $this->event->id],
            'event' => ['id' => $this->event->id, 'title' => $this->event->title],
            'group_key' => 'event_invite:'.$this->event->id,
        ];
    }
}
