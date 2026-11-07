<?php

namespace App\Modules\Social\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Notifications\NewFollower;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** People follow people and vendors. Counters are kept in step in the same transaction. */
class FollowService
{
    public function follow(User $follower, User|Vendor $target): bool
    {
        if ($target instanceof User && $target->id === $follower->id) {
            throw ValidationException::withMessages(['target' => "You can't follow yourself."]);
        }

        $t = Actor::of($target);

        $created = DB::transaction(function () use ($follower, $target, $t) {
            $inserted = DB::table('follows')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'follower_type' => Actor::USER,
                'follower_id' => $follower->id,
                'target_type' => $t->type,
                'target_id' => $t->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $follower->increment('following_count');
            $target->increment('followers_count');

            return true;
        });

        if ($created) {
            $this->backfillFeed($follower, $t);
            $recipients = $target instanceof Vendor ? $target->managers()->get() : collect([$target]);
            Notification::send($recipients, new NewFollower($follower, $t));
        }

        return $created;
    }

    public function unfollow(User $follower, User|Vendor $target): bool
    {
        $t = Actor::of($target);

        return DB::transaction(function () use ($follower, $target, $t) {
            $deleted = DB::table('follows')->where([
                'follower_type' => Actor::USER, 'follower_id' => $follower->id,
                'target_type' => $t->type, 'target_id' => $t->id,
            ])->delete();

            if ($deleted === 0) {
                return false;
            }

            $follower->decrement('following_count');
            $target->decrement('followers_count');

            DB::table('feed_items')
                ->where('owner_id', $follower->id)
                ->whereIn('post_id', DB::table('posts')->select('id')->where(['author_type' => $t->type, 'author_id' => $t->id]))
                ->delete();

            return true;
        });
    }

    /** Put the account's recent posts into the new follower's feed so it isn't empty. */
    private function backfillFeed(User $follower, Actor $target): void
    {
        $posts = DB::table('posts')
            ->where(['author_type' => $target->type, 'author_id' => $target->id, 'status' => 'published'])
            ->whereIn('visibility', ['public', 'followers'])
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'created_at']);

        DB::table('feed_items')->insertOrIgnore($posts->map(fn ($p) => [
            'owner_id' => $follower->id,
            'post_id' => $p->id,
            'created_at' => $p->created_at,
        ])->all());
    }
}
