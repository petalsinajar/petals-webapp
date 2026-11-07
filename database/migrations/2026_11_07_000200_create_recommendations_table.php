<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vendors suggested to a host for one event. A vendor is suggested at most once per event.
        Schema::create('recommendations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('category_id', 40);                 // catalog category slug
            $table->string('reason', 20);                      // checklist | missing_category | last_call
            $table->decimal('score', 6, 4)->default(0);
            $table->string('status', 12)->default('pending');  // pending | sent | opened | dismissed | booked
            $table->ulid('batch_id')->nullable();              // vendors sent together in one notification
            $table->json('details')->nullable();               // from_price_minor, distance_km, score breakdown
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();     // the host said "not interested"
            $table->foreignUlid('booked_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'vendor_id']);
            $table->index(['event_id', 'sent_at']);
            $table->index(['vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
