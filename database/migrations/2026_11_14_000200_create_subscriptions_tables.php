<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vendor plans and subscriptions. The four launch plans are inserted here so every
 * environment has them; admins edit them afterwards in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 30)->unique();
            $table->string('name', 60);
            $table->unsignedBigInteger('price_monthly_minor');
            $table->unsignedBigInteger('price_yearly_minor');
            $table->string('currency', 3)->default('GHS');
            $table->json('limits');   // { items, packages, team }: null = unlimited
            $table->json('features'); // { analytics: basic|standard|full|full_plus, web_dashboard: bool, ... }
            $table->decimal('commission_percent', 5, 2);
            $table->string('visibility_level', 20); // standard | search_boost | priority | top
            $table->unsignedTinyInteger('featured_weeks_per_month')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('vendor_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained();
            $table->string('interval', 10); // month | year
            $table->string('status', 20);   // trialing | active | grace | expired | cancelled
            $table->boolean('is_current')->default(true);
            $table->boolean('is_trial')->default(false);
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->foreignUlid('scheduled_plan_id')->nullable()->constrained('plans'); // downgrade at period end
            $table->string('scheduled_interval', 10)->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method', 20)->nullable(); // card | mobile_money
            $table->text('authorization_code')->nullable();  // card authorization for renewals (encrypted)
            $table->string('card_last4', 4)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable();      // upgraded | changed | expired | cancelled | trial_ended
            $table->timestamps();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['is_current', 'status', 'current_period_end']);
        });
        // One current subscription per vendor; older ones are history.
        DB::statement('CREATE UNIQUE INDEX vendor_subscriptions_one_current ON vendor_subscriptions (vendor_id) WHERE is_current');

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('vendor_subscriptions')->nullOnDelete(); // set once paid
            $table->foreignUlid('payment_id')->nullable()->unique()->constrained(); // null when a credit covered it all
            $table->foreignUlid('plan_id')->constrained();
            $table->string('interval', 10);
            $table->string('kind', 20); // new | upgrade | renewal
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('credit_minor')->default(0); // unused value of the plan upgraded from
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending'); // pending | paid | failed
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        // Reminders and other one-off notices, so each is sent once per period.
        Schema::create('subscription_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('subscription_id')->constrained('vendor_subscriptions')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->timestamp('period_end');
            $table->timestamp('created_at');

            $table->unique(['subscription_id', 'kind', 'period_end']);
        });

        // Manual changes by the platform team, with the reason.
        Schema::create('subscription_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('subscription_id')->constrained('vendor_subscriptions')->cascadeOnDelete();
            $table->foreignUlid('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20); // extend | comp
            $table->unsignedSmallInteger('days');
            $table->string('reason');
            $table->timestamp('created_at');
        });

        Schema::create('featured_credits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('vendor_subscriptions')->nullOnDelete();
            $table->unsignedTinyInteger('weeks')->default(1);
            $table->string('granted_for_period', 7); // YYYY-MM
            $table->unsignedTinyInteger('sequence')->default(1); // 1st, 2nd... credit of that month
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('placement_starts_at')->nullable();
            $table->timestamp('placement_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'granted_for_period', 'sequence']);
            $table->index(['placement_starts_at', 'placement_ends_at']);
        });

        // Subscription payments have no checkout.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUlid('checkout_id')->nullable()->change();
        });

        // The vendor's current plan, cached for ranking queries (kept in sync by SubscriptionService).
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('plan_key', 30)->default('starter');
            $table->decimal('visibility_boost', 4, 3)->default(0);
        });

        $this->seedPlans();
    }

    private function seedPlans(): void
    {
        $features = fn (string $analytics, array $on = []) => [
            'analytics' => $analytics,
            ...array_fill_keys([
                'web_dashboard', 'pinned_showcase_posts', 'branded_booking_link', 'bulk_upload',
                'corporate_quotes', 'api_export', 'multiple_branches', 'account_manager',
            ], false),
            ...array_fill_keys($on, true),
        ];
        $growth = ['web_dashboard', 'pinned_showcase_posts'];
        $pro = [...$growth, 'branded_booking_link', 'bulk_upload', 'corporate_quotes'];
        $business = [...$pro, 'api_export', 'multiple_branches', 'account_manager'];

        $plans = [
            ['starter', 'Starter', 0, ['items' => 10, 'packages' => 1, 'team' => 1], $features('basic'), 12, 'standard', 0],
            ['growth', 'Growth', 9900, ['items' => 50, 'packages' => 5, 'team' => 3], $features('standard', $growth), 10, 'search_boost', 0],
            ['pro', 'Pro', 24900, ['items' => null, 'packages' => null, 'team' => 10], $features('full', $pro), 8, 'priority', 1],
            ['business', 'Business', 59900, ['items' => null, 'packages' => null, 'team' => null], $features('full_plus', $business), 6, 'top', 2],
        ];

        foreach ($plans as $sort => [$key, $name, $monthly, $limits, $planFeatures, $commission, $visibility, $featured]) {
            DB::table('plans')->insert([
                'id' => strtolower((string) Str::ulid()),
                'key' => $key,
                'name' => $name,
                'price_monthly_minor' => $monthly,
                'price_yearly_minor' => $monthly * 10, // two months free
                'currency' => 'GHS',
                'limits' => json_encode($limits),
                'features' => json_encode($planFeatures),
                'commission_percent' => $commission,
                'visibility_level' => $visibility,
                'featured_weeks_per_month' => $featured,
                'sort' => $sort,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['plan_key', 'visibility_boost']);
        });
        Schema::dropIfExists('featured_credits');
        Schema::dropIfExists('subscription_adjustments');
        Schema::dropIfExists('subscription_notices');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('vendor_subscriptions');
        Schema::dropIfExists('plans');
    }
};
