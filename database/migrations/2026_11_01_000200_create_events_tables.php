<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('host_id')->constrained('users');
            $table->string('type', 20); // wedding | birthday | corporate | other
            $table->string('title', 140);
            $table->string('slug', 160)->unique();
            $table->text('description')->nullable();
            $table->foreignUlid('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 40)->default('Africa/Accra');
            $table->string('venue_name', 140)->nullable();
            $table->string('venue_address')->nullable();
            $table->decimal('venue_lat', 10, 7)->nullable();
            $table->decimal('venue_lng', 10, 7)->nullable();
            $table->unsignedInteger('guest_count')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable(); // visible to hosts only
            $table->string('currency', 3)->default('GHS');
            $table->string('visibility', 20)->default('public'); // public | followers | invite_only | private
            $table->json('theme')->nullable();   // colors, tags
            $table->json('company')->nullable(); // corporate: name, tax_id
            $table->string('status', 20)->default('planning'); // planning | upcoming | live | completed | cancelled
            $table->unsignedInteger('going_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['host_id', 'starts_at']);
            $table->index(['visibility', 'type', 'starts_at']);
        });

        Schema::create('event_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('invite_phone', 20)->nullable(); // invited before joining the app
            $table->string('role', 20)->default('guest');   // host | cohost | guest
            $table->string('rsvp', 20)->default('invited'); // invited | going | maybe | declined
            $table->unsignedTinyInteger('plus_ones')->default(0);
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'rsvp']);
            $table->index('invite_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_members');
        Schema::dropIfExists('events');
    }
};
