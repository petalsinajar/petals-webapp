<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event types (wedding, birthday, …), managed by admins in the admin panel instead of
 * hardcoded. Events, items, vendors and posts keep storing the slug as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table) {
            $table->string('slug', 30)->primary();       // stored on events.type etc.; never changes
            $table->string('name', 40);                   // "Wedding"
            $table->string('plural', 60);                 // "Weddings", for filters
            $table->string('description', 160)->nullable(); // "White weddings, engagements, receptions"
            $table->string('icon', 30)->default('celebration'); // one of EventType::ICONS
            $table->string('color', 7)->nullable();       // #RRGGBB accent
            $table->json('required_categories')->nullable(); // what it usually rents, for suggestions
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);      // inactive: kept for old events, not offered
            $table->timestamps();
        });

        $now = now();
        DB::table('event_types')->insert(array_map(fn (array $row) => [
            ...$row,
            'required_categories' => json_encode($row['required_categories']),
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['slug' => 'wedding', 'name' => 'Wedding', 'plural' => 'Weddings', 'description' => 'White weddings, engagements, receptions', 'icon' => 'favorite', 'color' => '#B5446E', 'required_categories' => ['chairs', 'tables', 'tents', 'decor', 'lighting', 'sound'], 'sort' => 10, 'active' => true],
            ['slug' => 'birthday', 'name' => 'Birthday', 'plural' => 'Birthdays', 'description' => 'Kids parties, milestones, surprise nights', 'icon' => 'cake', 'color' => '#E8771A', 'required_categories' => ['chairs', 'tables', 'decor', 'kids'], 'sort' => 20, 'active' => true],
            ['slug' => 'corporate', 'name' => 'Corporate', 'plural' => 'Corporate', 'description' => 'Launches, summits, staff parties', 'icon' => 'business', 'color' => '#2F5BEA', 'required_categories' => ['chairs', 'tables', 'staging', 'sound'], 'sort' => 30, 'active' => true],
            ['slug' => 'religious', 'name' => 'Religious', 'plural' => 'Religious events', 'description' => 'Church programs, crusades, thanksgiving services', 'icon' => 'church', 'color' => '#7B4FBF', 'required_categories' => ['chairs', 'tents', 'sound', 'staging'], 'sort' => 40, 'active' => true],
            // Already accepted by the API; not offered in the app.
            ['slug' => 'other', 'name' => 'Other', 'plural' => 'Other events', 'description' => null, 'icon' => 'celebration', 'color' => null, 'required_categories' => null, 'sort' => 90, 'active' => false],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
