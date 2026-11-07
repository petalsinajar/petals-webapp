<?php

namespace App\Modules\Events\Policies;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        if ($event->host_id === $user->id || $user->is_admin) {
            return true;
        }

        return match ($event->visibility) {
            'public' => true,
            'followers' => $this->followsHost($user, $event) || $this->isMember($user, $event),
            'invite_only', 'private' => $this->isMember($user, $event),
            default => false,
        };
    }

    /** Host and co-hosts edit the event, invite guests and rent for it. */
    public function manage(User $user, Event $event): bool
    {
        return $event->isHostedBy($user);
    }

    /** Guests who are going (and hosts) can post into the event feed. */
    public function post(User $user, Event $event): bool
    {
        return $event->isHostedBy($user)
            || $event->members()->where('user_id', $user->id)->whereIn('rsvp', ['going', 'maybe'])->exists();
    }

    public function rsvp(User $user, Event $event): bool
    {
        return $event->visibility === 'public' || $this->isMember($user, $event);
    }

    private function isMember(User $user, Event $event): bool
    {
        return $event->members()->where('user_id', $user->id)->exists();
    }

    private function followsHost(User $user, Event $event): bool
    {
        return DB::table('follows')->where([
            'follower_type' => Actor::USER, 'follower_id' => $user->id,
            'target_type' => Actor::USER, 'target_id' => $event->host_id,
        ])->exists();
    }
}
