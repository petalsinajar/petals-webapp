<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verified, two-way reviews of completed bookings, the reminders sent for them, and the
 * vendor trust signals computed nightly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The review window and reminders count from completion.
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('returned_at');
            $table->index(['status', 'completed_at']);
        });

        DB::table('bookings')->where('status', 'completed')->update([
            'completed_at' => DB::raw("(SELECT MAX(e.created_at) FROM booking_status_events e WHERE e.booking_id = bookings.id AND e.to_status = 'completed')"),
        ]);

        Schema::table('vendors', function (Blueprint $table) {
            $table->unsignedInteger('response_time_minutes')->nullable(); // median, to accept/decline
            $table->decimal('acceptance_rate', 5, 4)->nullable();
            $table->decimal('cancellation_rate', 5, 4)->nullable();      // vendor-cancelled, last 12 months
            $table->timestamp('trust_computed_at')->nullable();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20); // customer_to_vendor | vendor_to_customer
            $table->foreignUlid('author_id')->constrained('users');
            $table->foreignUlid('vendor_id')->constrained();
            $table->foreignUlid('customer_id')->constrained('users');
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->json('aspects')->nullable();   // { quality: 5, punctuality: 4, ... }
            $table->text('body')->nullable();
            $table->jsonb('item_ids')->nullable(); // items reviewed, from the booking's lines
            $table->string('status', 20)->default('pending'); // pending | published | removed
            $table->timestamp('published_at')->nullable();
            $table->timestamp('edited_until');
            $table->text('vendor_reply')->nullable();
            $table->timestamp('vendor_replied_at')->nullable();
            $table->string('removed_reason', 40)->nullable();
            $table->text('removed_note')->nullable();
            $table->foreignUlid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'direction']);
            $table->index(['vendor_id', 'direction', 'status', 'published_at']);
            $table->index(['customer_id', 'direction', 'status']);
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX reviews_item_ids_gin ON reviews USING GIN (item_ids)');
        }

        // One row per reminder sent, so a side is reminded at most once per stage.
        Schema::create('review_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 20);
            $table->unsignedSmallInteger('stage_days');
            $table->timestamp('created_at');

            $table->unique(['booking_id', 'direction', 'stage_days']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->index(['target_type', 'target_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id', 'status']);
        });
        Schema::dropIfExists('review_reminders');
        Schema::dropIfExists('reviews');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['response_time_minutes', 'acceptance_rate', 'cancellation_rate', 'trust_computed_at']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
