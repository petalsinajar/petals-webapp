<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('author');                     // user | vendor
            $table->foreignUlid('posted_by')->constrained('users'); // the person who pressed post
            $table->foreignUlid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 20)->nullable();     // copied from the event for feed filters
            $table->string('kind', 20)->default('post');      // post | reel | showcase
            $table->text('caption')->nullable();
            $table->json('media');                            // [{url,type,w,h,blurhash}]
            $table->json('hashtags')->nullable();
            $table->string('visibility', 20)->default('public'); // public | followers | event_members
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->unsignedInteger('shop_taps_count')->default(0);
            $table->double('score')->default(0);
            $table->string('status', 20)->default('published'); // draft | published | hidden | removed
            $table->timestamps();
            $table->softDeletes();

            $table->index(['author_type', 'author_id', 'created_at']);
            $table->index(['event_id', 'created_at']);
            $table->index(['status', 'visibility', 'score']);
            $table->index(['status', 'event_type', 'score']);
        });

        // Shop-the-look pins on a post's photos.
        Schema::create('post_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('media_index')->default(0);
            $table->decimal('x', 5, 4); // 0..1 from the left
            $table->decimal('y', 5, 4); // 0..1 from the top
            $table->string('target_type', 20); // item | vendor | user
            $table->ulid('target_id');         // items, vendors or users: polymorphic, so no foreign key
            $table->foreignUlid('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete(); // owner of a tagged item
            $table->string('status', 20)->default('approved'); // pending | approved | rejected
            $table->timestamps();

            $table->index(['target_type', 'target_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('follows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('follower');
            $table->ulidMorphs('target');
            $table->timestamps();

            $table->unique(['follower_type', 'follower_id', 'target_type', 'target_id'], 'follows_unique');
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('actor');
            $table->ulidMorphs('reactable'); // post | comment
            $table->string('kind', 10)->default('like'); // like | love | fire
            $table->timestamps();

            $table->unique(['actor_type', 'actor_id', 'reactable_type', 'reactable_id'], 'reactions_unique');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('author');
            $table->foreignUlid('posted_by')->constrained('users');
            $table->ulid('parent_id')->nullable();
            $table->text('body');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'parent_id', 'created_at']);
        });
        // Separate step: PostgreSQL needs the primary key in place before a self-reference.
        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
        });

        // Fan-out on write for the Following feed.
        Schema::create('feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['owner_id', 'post_id']);
            $table->index(['owner_id', 'created_at']);
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->ulidMorphs('blocked');
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_type', 'blocked_id']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_id')->constrained('users');
            $table->string('target_type', 20); // post | comment | user | vendor | item
            $table->string('target_id', 26);
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('open'); // open | actioned | dismissed
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('feed_items');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('posts');
    }
};
