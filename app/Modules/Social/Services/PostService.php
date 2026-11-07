<?php

namespace App\Modules\Social\Services;

use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Jobs\FanOutPost;
use App\Modules\Social\Models\Post;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PostService
{
    public function __construct(private readonly CatalogReader $catalog) {}

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

        $tags = $this->resolveTags($data['tags'] ?? [], $actor, count($data['media']));

        $post = DB::transaction(function () use ($poster, $actor, $event, $data, $tags, $author) {
            $post = Post::create([
                'author_type' => $actor->type,
                'author_id' => $actor->id,
                'posted_by' => $poster->id,
                'event_id' => $event?->id,
                'event_type' => $event?->type,
                'kind' => $data['kind'] ?? ($actor->type === Actor::VENDOR ? 'showcase' : 'post'),
                'caption' => $data['caption'] ?? null,
                'media' => array_values($data['media']),
                'hashtags' => $this->hashtags($data['caption'] ?? ''),
                'visibility' => $data['visibility'] ?? 'public',
                'status' => 'published',
            ]);

            foreach ($tags as $tag) {
                $post->tags()->create($tag);
            }

            $author->increment('posts_count');
            $event?->increment('posts_count');

            return $post;
        });

        FanOutPost::dispatch($post->id)->afterCommit();

        return $post->load('tags', 'author', 'event');
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
                'target_id' => $tag['target_id'],
                'variant_id' => $tag['variant_id'] ?? null,
                'status' => 'approved',
            ];

            switch ($tag['target_type']) {
                case 'item':
                    $vendorId = $this->catalog->vendorOf($tag['target_id'])
                        ?? throw ValidationException::withMessages(["tags.$i.target_id" => 'Item not found.']);
                    $row['vendor_id'] = $vendorId;
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
