<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/*
 * MongoDB inventory collections and indexes.
 * Run with: php artisan migrate --database=mongodb --path=database/migrations/mongodb
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('categories', function (Blueprint $collection) {
            $collection->index('parent_id');
        });

        Schema::connection('mongodb')->create('items', function (Blueprint $collection) {
            $collection->index(['vendor_id' => 1, 'status' => 1]);
            $collection->index(['category_id' => 1, 'status' => 1]);
            $collection->index('variants._id');
            $collection->index('event_types');
            $collection->geospatial('geo', '2dsphere');
            // Basic text search; swap for an Atlas Search index when relevance tuning matters.
            $collection->index(
                ['title' => 'text', 'description' => 'text', 'tags' => 'text'],
                'items_text',
                null,
                ['weights' => ['title' => 5, 'tags' => 3, 'description' => 1]],
            );
        });

        Schema::connection('mongodb')->create('packages', function (Blueprint $collection) {
            $collection->index(['vendor_id' => 1, 'status' => 1]);
            $collection->index('event_types');
        });

        // One counter per variant per day. The unique index is what makes reservations safe.
        Schema::connection('mongodb')->create('availability_days', function (Blueprint $collection) {
            $collection->unique(['variant_id' => 1, 'day' => 1]);
        });

        Schema::connection('mongodb')->create('reservations', function (Blueprint $collection) {
            $collection->index(['variant_id' => 1, 'start' => 1, 'end' => 1]);
            $collection->index('booking_id');
            $collection->index(['kind' => 1, 'status' => 1, 'expires_at' => 1]);
            $collection->index(['vendor_id' => 1, 'start' => 1]);
        });
    }

    public function down(): void
    {
        foreach (['reservations', 'availability_days', 'packages', 'items', 'categories'] as $name) {
            Schema::connection('mongodb')->dropIfExists($name);
        }
    }
};
