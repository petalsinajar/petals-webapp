<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum tokens, keyed to ULID users.
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->ulidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('handle', 30)->unique();
            $table->string('name', 120);
            $table->text('bio')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->json('event_types')->nullable();   // ["wedding","birthday","corporate"]
            $table->json('categories')->nullable();    // category slugs
            $table->string('city', 80)->nullable();
            $table->decimal('base_lat', 10, 7)->nullable();
            $table->decimal('base_lng', 10, 7)->nullable();
            $table->unsignedSmallInteger('service_radius_km')->default(40);
            $table->json('contact')->nullable();       // phone, whatsapp, email
            $table->string('verification_status', 20)->default('pending'); // pending | verified | rejected
            $table->timestamp('verified_at')->nullable();
            $table->json('policies')->nullable();      // deposit_percent, cancellation tiers, lead_time_hours, buffer_hours
            $table->json('delivery')->nullable();      // offered, base_fee_minor, per_km_minor, setup_offered, setup_fee_minor
            $table->text('payout')->nullable();        // encrypted json: method, account_ref
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('completed_bookings_count')->default(0);
            $table->string('status', 20)->default('active'); // active | suspended
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'verification_status']);
        });

        Schema::create('vendor_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // owner | manager | staff
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['vendor_id', 'user_id']);
            $table->index('user_id');
        });

        // One namespace for @handles across users and vendors.
        Schema::create('handles', function (Blueprint $table) {
            $table->string('handle', 30)->primary();
            $table->string('owner_type', 20);
            $table->ulid('owner_id');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10); // android | ios
            $table->string('push_token')->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('handles');
        Schema::dropIfExists('vendor_members');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('personal_access_tokens');
    }
};
