<?php

namespace App\Modules\Identity\Services;

use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Services\MediaLibrary;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account from the app, as the support page promises: the profile, posts,
 * comments, likes, follows, events without bookings and notification settings go.
 * Bookings and payments stay for accounting, and reviews stay (as a former member) so
 * ratings remain fair. The phone number and handle are freed, so the person can sign up
 * again later as someone new.
 */
final class AccountDeletion
{
    /** Bookings that still involve money or items moving. */
    private const IN_PROGRESS = [
        BookingStatus::PendingVendor,
        BookingStatus::Accepted,
        BookingStatus::Confirmed,
        BookingStatus::OutForDelivery,
        BookingStatus::Delivered,
        BookingStatus::Returned,
        BookingStatus::Disputed,
    ];

    public function __construct(
        private readonly HandleRegistry $handles,
        private readonly MediaLibrary $media,
    ) {}

    /** Why the account can't be deleted yet, or null when it can. */
    public function blocker(User $user): ?string
    {
        if (Booking::where('customer_id', $user->id)->whereIn('status', self::IN_PROGRESS)->exists()) {
            return 'You have bookings in progress. Once they’re finished or cancelled, you can delete your account.';
        }

        $soleOwner = Vendor::query()
            ->whereHas('members', fn ($q) => $q->where(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']))
            ->whereDoesntHave('members', fn ($q) => $q->where('user_id', '!=', $user->id)->where(['role' => 'owner', 'status' => 'active']))
            ->first();
        if ($soleOwner) {
            return "You’re the only owner of {$soleOwner->name}. Make someone else an owner on the web dashboard first, or contact support to close the business.";
        }

        return null;
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->deleteContent($user);
            $this->deleteFollows($user);

            // Events nobody booked for go; the rest stay for the vendors' records.
            Event::where('host_id', $user->id)->whereDoesntHave('bookings')->get()->each->delete();
            DB::table('event_members')->where('user_id', $user->id)->delete();
            DB::table('vendor_members')->where('user_id', $user->id)->update(['status' => 'removed', 'updated_at' => now()]);
            DB::table('devices')->where('user_id', $user->id)->delete();
            DB::table('blocks')->where('blocker_id', $user->id)->delete();
            DB::table('notifications')->where(['notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id])->delete();
            $user->tokens()->delete();

            $this->handles->release($user->handle);
            $this->media->discardFor($user);

            // Unique columns get placeholders that can't collide or be signed in with.
            $tag = strtolower(substr($user->id, -12));
            $user->forceFill([
                'handle' => "deleted-{$tag}",
                'display_name' => 'Former member',
                'phone' => "deleted:{$tag}",
                'phone_verified_at' => null,
                'email' => null,
                'password' => null,
                'avatar_media_id' => null,
                'bio' => null,
                'city' => null,
                'lat' => null,
                'lng' => null,
                'is_private' => true,
                'settings' => null,
                'followers_count' => 0,
                'following_count' => 0,
                'posts_count' => 0,
                'status' => 'deleted',
                'remember_token' => null,
            ])->save();
            $user->delete();
        });
    }

    /** Their posts (with photos and videos), comments and likes, keeping counters right. */
    private function deleteContent(User $user): void
    {
        Post::where(['author_type' => Actor::USER, 'author_id' => $user->id])->get()
            ->each(function (Post $post) {
                if ($post->status === 'published') {
                    $post->event?->decrement('posts_count');
                }
                $post->update(['status' => 'removed']);
                $post->delete();
                $this->media->discardFor($post);
            });

        Comment::where('posted_by', $user->id)->with('post')->get()
            ->each(function (Comment $comment) {
                $comment->delete();
                $comment->post?->decrement('comments_count');
            });

        $likes = DB::table('reactions')->where(['actor_type' => Actor::USER, 'actor_id' => $user->id]);
        foreach ((clone $likes)->get(['reactable_type', 'reactable_id']) as $like) {
            $table = $like->reactable_type === 'comment' ? 'comments' : 'posts';
            DB::table($table)->where('id', $like->reactable_id)->where('likes_count', '>', 0)->decrement('likes_count');
        }
        $likes->delete();
    }

    /** Who they follow and who follows them, both ways. */
    private function deleteFollows(User $user): void
    {
        $following = DB::table('follows')->where(['follower_type' => Actor::USER, 'follower_id' => $user->id]);
        foreach ((clone $following)->get(['target_type', 'target_id']) as $f) {
            $table = $f->target_type === Actor::VENDOR ? 'vendors' : 'users';
            DB::table($table)->where('id', $f->target_id)->where('followers_count', '>', 0)->decrement('followers_count');
        }
        $following->delete();

        $followers = DB::table('follows')->where(['target_type' => Actor::USER, 'target_id' => $user->id]);
        foreach ((clone $followers)->where('follower_type', Actor::USER)->pluck('follower_id') as $id) {
            DB::table('users')->where('id', $id)->where('following_count', '>', 0)->decrement('following_count');
        }
        $followers->delete();

        DB::table('feed_items')->where('owner_id', $user->id)->delete();
    }
}
