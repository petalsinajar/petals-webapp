<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Uploaded images and videos (app/Modules/Media). Files live in R2 or the video provider;
 * rows store keys, never URLs. Runs after users and vendors, before everything that points
 * at media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->nullable()->constrained()->nullOnDelete(); // uploaded for a business
            $table->string('kind', 10);    // image | video
            $table->string('purpose', 20); // post | item | avatar | cover | event_cover
            $table->string('status', 12)->default('pending'); // pending | uploaded | processing | ready | failed | rejected
            $table->string('storage_key')->nullable();        // original upload in R2 (images); removed after processing
            $table->string('multipart_upload_id')->nullable();
            $table->string('mime', 50);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('blurhash', 100)->nullable();
            $table->json('variants')->nullable(); // images: {thumb, feed, full} keys; video: {hls, poster} paths
            $table->string('provider_ref', 100)->nullable()->unique(); // video provider's id
            $table->string('failure_reason', 100)->nullable();
            // What it's used by (post, item, package, user, vendor, event) and where in that list.
            $table->nullableUlidMorphs('attachable');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
            $table->index(['attached_at', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete();
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreign('logo_media_id')->references('id')->on('media')->nullOnDelete();
            $table->foreign('cover_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['logo_media_id']);
            $table->dropForeign(['cover_media_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
        });
        Schema::dropIfExists('media');
    }
};
