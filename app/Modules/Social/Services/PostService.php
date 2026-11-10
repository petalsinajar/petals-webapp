<?php

namespace App\Modules\Social\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Services\MediaLibrary;
use App\Modules\Social\Jobs\FanOutPost;
use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input (see PostController::store)
     */
    public function create(User $poster, array $data): Post
    {
        $author = $poster;
        if (! empty($data['as_vendor_id'])) {
            $author = Vendor::findOrFail($data['as_vendor_id']);
            Gate::forUser($poster)->authorize('manage', $author);
        }
        $actor = Actor::of($author);

        $event = null;
        if (! empty($data['event_id'])) {
            $event = Event::findOrFail($data['event_id']);
            // Vendors can showcase work at any event they supplied; others need to be in it.
            $suppliedEvent = $author instanceof Vendor
                && DB::table('bookings')->where(['event_id' => $event->id, 'vendor_id' => $author->id])->exists();
            if (! $suppliedEvent) {
                Gate::forUser($poster)->authorize('post', $event);
            }
        }

        $media = $this->media->resolve($data['media'], $poster, $author instanceof Vendor ? $author : null,
            config('eventapp.media.purposes.post'), 'media', allowProcessing: true);
        $ready = $media->every(fn ($m) => $m->status === MediaStatus::Ready);
        $tags = $this->resolveTags($data['tags'] ?? [], $actor, $media->count());

        $post = DB::transaction(function () use ($poster, $actor, $event, $data, $tags, $media, $ready) {
            $post = Post::create([
                'author_type' => $actor->type,
                'author_id' => $actor->id,
                'posted_by' => $poster->id,
                'event_id' => $event?->id,
                'event_type' => $event?->type,
                'kind' => $data['kind'] ?? ($actor->type === Actor::VENDOR ? 'showcase' : 'post'),
                'caption' => $data['caption'] ?? null,
                'hashtags' => $this->hashtags($data['caption'] ?? ''),
                'visibility' => $data['visibility'] ?? 'public',
                'status' => $ready ? 'published' : 'processing',
            ]);

            foreach ($tags as $tag) {
                $post->tags()->create($tag);
            }
            $this->media->attach($media, $post);

            if ($ready) {
                $this->published($post);
            }

            return $post;
        });

        return $post->load('tags', 'author', 'event', 'media');
    }

    /**
     * A processing post's media changed: publish it once everything is ready, or mark it
     * failed if any of its media failed. Safe to call repeatedly.
     */
    public function mediaChanged(string $postId): void
    {
        DB::transaction(function () use ($postId) {
            $post = Post::whereKey($postId)->lockForUpdate()->first();
            if (! $post || $post->status !== 'processing') {
                return;
            }

            $statuses = $post->media()->pluck('status')->map(fn ($s) => $s instanceof MediaStatus ? $s : MediaStatus::from($s));
            if ($statuses->contains(fn (MediaStatus $s) => in_array($s, [MediaStatus::Failed, MediaStatus::Rejected], true))) {
                $post->update(['status' => 'failed']);
            } elseif ($statuses->isNotEmpty() && $statuses->every(fn (MediaStatus $s) => $s === MediaStatus::Ready)) {
                $post->update(['status' => 'published', 'created_at' => now()]);
                $this->published($post);
            }
        });
    }

    /** Counters and feeds, once a post goes public. */
    private function published(Post $post): void
    {
        $post->author?->increment('posts_count');
        $post->event?->increment('posts_count');

        FanOutPost::dispatch($post->id)->afterCommit();
    }

    /**
     * @param  list<array<string, mixed>>  $tags
     * @return list<array<string, mixed>>
     */
    private function resolveTags(array $tags, Actor $author, int $mediaCount): array
    {
        $resolved = [];

        foreach ($tags as $i => $tag) {
            if ($tag['media_index'] >= $mediaCount) {
                throw ValidationException::withMessages(["tags.$i.media_index" => 'That photo does not exist.']);
            }

            $row = [
                'media_index' => $tag['media_index'],
                'x' => $tag['x'],
                'y' => $tag['y'],
                'target_type' => $tag['target_type'],
                'target_id' => strtolower($tag['target_id']),
                'variant_id' => isset($tag['variant_id']) ? strtolower($tag['variant_id']) : null,
                'status' => 'approved',
            ];

            switch ($tag['target_type']) {
                case 'item':
                    $vendorId = $this->catalog->vendorOf($tag['target_id'])
                        ?? throw ValidationException::withMessages(["tags.$i.target_id" => 'Item not found.']);
                    $row['vendor_id'] = $vendorId;
                    if ($row['variant_id'] && $this->catalog->variant($tag['target_id'], $row['variant_id']) === null) {
                        throw ValidationException::withMessages(["tags.$i.variant_id" => 'That item has no such variant.']);
                    }
                    // Tagging another vendor's item needs that vendor's approval.
                    $isOwnItem = $author->type === Actor::VENDOR && $author->id === $vendorId;
                    $row['status'] = $isOwnItem ? 'approved' : 'pending';
                    break;
                case 'vendor':
                    Vendor::whereKey($tag['target_id'])->exists()
                        ?: throw ValidationException::withMessages(["tags.$i.target_id" => 'Vendor not found.']);
                    $row['vendor_id'] = $tag['target_id'];
                    break;
                case 'user':
                    User::whereKey($tag['target_id'])->exists()
                        ?: throw ValidationException::withMessages(["tags.$i.target_id" => 'Person not found.']);
                    break;
            }

            $resolved[] = $row;
        }

        return $resolved;
    }

    /** @return list<string> */
    private function hashtags(string $caption): array
    {
        preg_match_all('/#([\p{L}\p{N}_]{2,40})/u', $caption, $matches);

        return array_slice(array_values(array_unique(array_map('mb_strtolower', $matches[1]))), 0, 30);
    }
}
