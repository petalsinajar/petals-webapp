<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventMember;
use App\Modules\Events\Notifications\EventInvitation;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inviting guests and co-hosts. App users are told by push; numbers not on the app get a
 * text, and the invite becomes theirs when they sign in with that number.
 */
class EventInvites
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * @param  list<array{user_id?: ?string, phone?: ?string, role?: ?string}>  $invites
     * @return int people newly invited or made co-host
     */
    public function invite(Event $event, User $inviter, array $invites): int
    {
        $changed = 0;
        foreach ($invites as $invite) {
            $role = ($invite['role'] ?? null) === 'cohost' ? 'cohost' : 'guest';
            $phone = isset($invite['phone']) ? PhoneNumber::normalize($invite['phone']) : null;
            $user = isset($invite['user_id'])
                ? User::find($invite['user_id'])
                : ($phone ? User::where('phone', $phone)->first() : null);

            if ($user) {
                $changed += $this->inviteUser($event, $inviter, $user, $role) ? 1 : 0;
            } elseif ($phone) {
                $changed += $this->invitePhone($event, $inviter, $phone, $role) ? 1 : 0;
            }
        }

        return $changed;
    }

    private function inviteUser(Event $event, User $inviter, User $user, string $role): bool
    {
        if ($user->id === $event->host_id) {
            return false; // the creator is already the host
        }
        $member = EventMember::firstOrNew(['event_id' => $event->id, 'user_id' => $user->id]);
        $isNew = ! $member->exists;
        // Promote a guest; never demote a co-host by inviting them again.
        $promoted = ! $isNew && $role === 'cohost' && $member->role === 'guest';
        if (! $isNew && ! $promoted) {
            return false;
        }
        $member->fill([
            'role' => $promoted ? 'cohost' : $role,
            // A co-host is going, as the host is; a guest has yet to answer.
            'rsvp' => $isNew ? ($role === 'cohost' ? 'going' : 'invited') : $member->rsvp,
            'invited_by' => $member->invited_by ?? $inviter->id,
        ])->save();

        if ($user->id !== $inviter->id) {
            $user->notify(new EventInvitation($event, $inviter, $member->role));
        }

        return true;
    }

    private function invitePhone(Event $event, User $inviter, string $phone, string $role): bool
    {
        $member = EventMember::firstOrNew(['event_id' => $event->id, 'invite_phone' => $phone, 'user_id' => null]);
        $isNew = ! $member->exists;
        if (! $isNew && ! ($role === 'cohost' && $member->role === 'guest')) {
            return false;
        }
        $member->fill(['role' => $role, 'rsvp' => 'invited', 'invited_by' => $member->invited_by ?? $inviter->id])->save();

        if ($isNew) {
            $name = $inviter->display_name ?: '@'.$inviter->handle;
            $what = $role === 'cohost' ? 'to co-host' : 'to';
            try {
                $this->sms->send($phone, "{$name} invited you {$what} {$event->title} on Petalsinajar. Get the app: ".config('app.url'));
            } catch (Throwable $e) {
                // The invite stands; it's theirs when they sign in with this number.
                Log::warning('Invite text not sent', ['event' => $event->id, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /** Invites sent to [user]'s phone before they had an account become theirs. */
    public function claimPhoneInvites(User $user): void
    {
        DB::transaction(function () use ($user) {
            $pending = EventMember::whereNull('user_id')->where('invite_phone', $user->phone)->lockForUpdate()->get();
            foreach ($pending as $invite) {
                $existing = EventMember::where('event_id', $invite->event_id)->where('user_id', $user->id)->first();
                if ($existing) {
                    if ($invite->role === 'cohost' && $existing->role === 'guest') {
                        $existing->update(['role' => 'cohost']);
                    }
                    $invite->delete();
                } else {
                    $invite->update(['user_id' => $user->id, 'invite_phone' => null]);
                }
            }
        });
    }
}
