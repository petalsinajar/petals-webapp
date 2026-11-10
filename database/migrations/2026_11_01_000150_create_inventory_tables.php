<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Inventory: categories, items and their variants, packages, and the stock ledger
 * (availability_days + reservations). Runs before events and bookings, which point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform-managed tree (two levels). The id is a slug, e.g. "chairs".
        Schema::create('categories', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 80);
            $table->string('parent_id', 40)->nullable()->index();
            $table->string('icon', 40)->nullable();
            $table->json('attributes_schema')->nullable(); // [{key, type, options}] for items.specs
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        // Separate step: PostgreSQL needs the primary key in place before a self-reference.
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained();
            $table->string('category_id', 40);
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->jsonb('specs')->nullable();         // category-specific details (attributes_schema)
            $table->json('event_types')->nullable();    // ["wedding","birthday","corporate"]
            $table->json('tags')->nullable();
            $table->string('pricing_unit', 20)->default('per_event'); // per_event | per_day | per_hour
            $table->json('price_tiers');                // [{min_qty, price_minor}]
            $table->string('currency', 3);
            $table->json('deposit_policy')->nullable(); // {type: percent|fixed, value}
            $table->unsignedBigInteger('damage_deposit_minor')->default(0);
            $table->unsignedInteger('min_qty')->default(1);
            $table->boolean('requires_setup')->default(false);
            $table->boolean('instant_book')->default(false);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->unsignedInteger('saves_count')->default(0);
            $table->string('status', 20)->default('draft'); // draft | active | paused | archived
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories');
            $table->index(['vendor_id', 'status']);
            $table->index(['category_id', 'status']);
        });

        // A stockable version of an item. Availability is always tracked per variant.
        Schema::create('item_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('sku', 40)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->bigInteger('price_delta_minor')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['item_id', 'sort']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->json('event_types')->nullable();
            $table->unsignedInteger('guest_min')->nullable();
            $table->unsignedInteger('guest_max')->nullable();
            $table->unsignedBigInteger('price_minor'); // fixed bundle price
            $table->string('currency', 3);
            $table->string('status', 20)->default('active'); // active | paused | archived
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
        });

        Schema::create('package_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('package_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained();
            $table->foreignUlid('variant_id')->constrained('item_variants');
            $table->unsignedInteger('qty');
            $table->timestamps();
        });

        // One counter per variant per day. Reserving is a guarded UPDATE on this row, so the
        // last units can't be sold twice (see SqlAvailabilityStore).
        Schema::create('availability_days', function (Blueprint $table) {
            $table->foreignUlid('variant_id')->constrained('item_variants')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('reserved')->default(0);

            $table->primary(['variant_id', 'day']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained();
            $table->foreignUlid('item_id')->constrained();
            $table->foreignUlid('variant_id')->constrained('item_variants');
            $table->unsignedInteger('qty');
            $table->timestamp('starts_at'); // includes the vendor's buffer hours
            $table->timestamp('ends_at');
            $table->json('days');           // ["2026-12-05", ...] counters this reservation holds
            $table->string('kind', 10);     // hold | booking | block
            $table->string('status', 10)->default('active'); // active | released
            $table->ulid('booking_id')->nullable()->index(); // written before the booking row, same transaction
            $table->string('reason', 40)->nullable(); // blocks: offline_booking | maintenance | lost
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status', 'starts_at']);
            $table->index(['kind', 'status', 'expires_at']);
            $table->index(['variant_id', 'status']);
        });

        // Location matching on PostgreSQL: a geography point kept in sync with base_lat/base_lng.
        // Other drivers (SQLite in tests) fall back to a bounding box + haversine in App\Support\Geo.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
            DB::statement(<<<'SQL'
                ALTER TABLE vendors ADD COLUMN base_location geography(Point, 4326)
                GENERATED ALWAYS AS (
                    CASE WHEN base_lat IS NOT NULL AND base_lng IS NOT NULL
                        THEN ST_SetSRID(ST_MakePoint(base_lng::double precision, base_lat::double precision), 4326)::geography
                    END
                ) STORED
                SQL);
            DB::statement('CREATE INDEX vendors_base_location_gist ON vendors USING GIST (base_location)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS vendors_base_location_gist');
            DB::statement('ALTER TABLE vendors DROP COLUMN IF EXISTS base_location');
        }

        Schema::dropIfExists('reservations');
        Schema::dropIfExists('availability_days');
        Schema::dropIfExists('package_lines');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('item_variants');
        Schema::dropIfExists('items');
        Schema::dropIfExists('categories');
    }
};
