<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The app shows the venue's city and how many guests said maybe / declined. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('venue_city', 80)->nullable()->after('venue_address');
            $table->unsignedInteger('maybe_count')->default(0)->after('going_count');
            $table->unsignedInteger('declined_count')->default(0)->after('maybe_count');
        });

        foreach (['maybe', 'declined'] as $rsvp) {
            DB::table('events')->update([
                "{$rsvp}_count" => DB::raw(
                    "(select count(*) from event_members where event_members.event_id = events.id and event_members.rsvp = '{$rsvp}')"
                ),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['venue_city', 'maybe_count', 'declined_count']);
        });
    }
};
