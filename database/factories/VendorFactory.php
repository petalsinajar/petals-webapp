<?php

namespace Database\Factories;

use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Modules\Subscriptions\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        $name = fake()->company().' Rentals';

        return [
            'handle' => Str::lower(Str::slug(Str::limit($name, 18, ''), '.')).'.'.fake()->unique()->numberBetween(100, 99999),
            'name' => $name,
            'bio' => fake()->sentence(),
            'event_types' => ['wedding', 'birthday', 'corporate'],
            'categories' => ['chairs', 'tents'],
            'city' => 'Accra',
            'base_lat' => 5.6037,
            'base_lng' => -0.1870,
            'service_radius_km' => 40,
            'policies' => [
                'deposit_percent' => 30,
                'lead_time_hours' => 48,
                'buffer_hours' => 12,
                'cancellation' => [
                    ['days_before' => 14, 'refund_percent' => 100],
                    ['days_before' => 3, 'refund_percent' => 50],
                ],
            ],
            'delivery' => ['offered' => true, 'base_fee_minor' => 15000, 'per_km_minor' => 0, 'setup_offered' => true, 'setup_fee_minor' => 10000],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Vendor $vendor) {
            DB::table('handles')->insertOrIgnore([
                'handle' => $vendor->handle, 'owner_type' => 'vendor', 'owner_id' => $vendor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** On a paid plan for the next month (no payment), like a comp from the admin panel. */
    public function onPlan(string $planKey, string $interval = 'month'): static
    {
        return $this->afterCreating(function (Vendor $vendor) use ($planKey, $interval) {
            VendorSubscription::create([
                'vendor_id' => $vendor->id,
                'plan_id' => Plan::byKey($planKey)->id,
                'interval' => $interval,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => $interval === 'year' ? now()->addYear() : now()->addMonth(),
            ]);
            app(SubscriptionService::class)->syncVendor($vendor->id);
        });
    }

    /** Attach an owner (created if not given). */
    public function ownedBy(?User $owner = null): static
    {
        return $this->afterCreating(function (Vendor $vendor) use ($owner) {
            $vendor->members()->create([
                'user_id' => ($owner ?? User::factory()->create())->id,
                'role' => VendorRole::Owner,
                'status' => 'active',
            ]);
        });
    }
}
