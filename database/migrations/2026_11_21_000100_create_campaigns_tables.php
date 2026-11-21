<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin campaigns: push, SMS and in-app messages to saved audiences (app/Modules/Campaigns).
 * Lifecycle automations are campaigns with an `automation` key, inserted here switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_segments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->json('filters'); // see Campaigns\Services\SegmentQuery
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            // Lifecycle automations (welcome_day_0, inactive_14d_push, …); null for one-off campaigns.
            $table->string('automation', 40)->nullable()->unique();
            $table->boolean('active')->default(false); // automations only: on/off
            $table->string('status', 20)->default('draft'); // draft | pending_approval | scheduled | sending | sent | cancelled
            $table->json('channels');                      // ["push","sms","in_app"]
            $table->string('category', 20);                // promotions | product_updates | social | suggestions
            $table->string('title', 120)->nullable();      // push and in-app
            $table->text('body');
            $table->string('sms_body', 1000)->nullable();  // defaults to body
            $table->json('deep_link')->nullable();         // { type: event|post|vendor|item|discover|create_event, id? }
            $table->foreignUlid('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignUlid('segment_id')->nullable()->constrained('campaign_segments')->nullOnDelete();
            // The segment's filters as submitted (and approved): sends use these, so editing the
            // segment afterwards can't change who a submitted campaign reaches.
            $table->json('audience_filters')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('recurrence', 10)->nullable(); // daily | weekly (digests)
            $table->boolean('requires_approval')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('audience_count')->default(0); // at submit; recipients hold the real numbers
            $table->unsignedBigInteger('estimated_sms_cost_minor')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            // Which run this belongs to: '' for one-off sends, the date for recurring ones, the
            // step for automations. Unique with campaign, user and channel: a send is never repeated.
            $table->string('run_key', 60)->default('');
            $table->json('context')->nullable(); // event_id, vendor_id for personalisation
            $table->string('status', 12)->default('queued'); // queued | sent | delivered | failed | skipped
            $table->string('skip_reason', 30)->nullable();
            $table->string('error', 255)->nullable();
            $table->string('provider_message_id', 100)->nullable()->index();
            $table->unsignedTinyInteger('sms_segments')->nullable();
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'run_key', 'user_id', 'channel'], 'campaign_recipients_once');
            $table->index(['user_id', 'status', 'sent_at']); // frequency caps
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);  // push | sms | in_app
            $table->string('category', 20); // promotions | product_updates | social | suggestions
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'channel', 'category']);
        });

        // Numbers that replied STOP (or were opted out by an admin): no marketing SMS at all.
        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('phone', 20)->unique();
            $table->string('source', 20); // stop_reply | admin
            $table->timestamps();
        });

        $now = now();
        $automations = [
            ['welcome_day_0', 'Welcome · day 0', ['push', 'in_app'], 'product_updates', 'Welcome to Petalsinajar, {first_name}!', 'Plan your next event, find vendors near you and share the moments.', ['type' => 'discover']],
            ['welcome_day_1', 'Welcome · day 1', ['push'], 'product_updates', 'Planning something?', 'Create your event and we’ll suggest vendors who are free on your date.', ['type' => 'create_event']],
            ['welcome_day_3', 'Welcome · day 3', ['push'], 'suggestions', 'Vendors near {city}', 'See chairs, tents, decor and sound you can rent near you.', ['type' => 'discover']],
            ['welcome_day_7', 'Welcome · day 7', ['in_app'], 'social', 'Follow vendors you love', 'Follow vendors to see their latest setups in your feed.', ['type' => 'discover']],
            ['event_countdown', 'Event countdown', ['push', 'in_app'], 'suggestions', '{event_title} is coming up', 'Check your bookings and checklist for {event_title}.', ['type' => 'event']],
            ['inactive_14d_push', 'Inactive 14 days (push)', ['push'], 'promotions', 'We miss you, {first_name}', 'New setups from vendors near {city} are waiting.', ['type' => 'discover']],
            ['inactive_30d_sms', 'Inactive 30 days (SMS)', ['sms'], 'promotions', null, 'Hi {first_name}, plan your next event on Petalsinajar: petalsinajar.com', null],
            ['vendor_weekly_summary', 'Vendor weekly summary', ['push', 'in_app'], 'product_updates', 'Your week at {vendor_name}', '{week_bookings} new booking requests this week. Open your dashboard to respond.', null],
        ];
        DB::table('campaigns')->insert(array_map(fn (array $a) => [
            'id' => (string) Str::ulid(),
            'automation' => $a[0],
            'name' => $a[1],
            'active' => false,
            'status' => 'draft',
            'channels' => json_encode($a[2]),
            'category' => $a[3],
            'title' => $a[4],
            'body' => $a[5],
            'deep_link' => $a[6] ? json_encode($a[6]) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $automations));
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('campaign_segments');
    }
};
