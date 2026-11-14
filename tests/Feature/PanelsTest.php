<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Filament dashboards boot and enforce who can see them. */
class PanelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render_for_admins(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->actingAs(User::factory()->admin()->create());

        foreach (['/admin', '/admin/users', '/admin/vendors', '/admin/bookings', '/admin/posts', '/admin/reviews', '/admin/event-types', '/admin/plans', '/admin/subscriptions'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_non_admins_cannot_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
    }

    public function test_vendor_members_open_their_dashboard(): void
    {
        $owner = User::factory()->create();
        Vendor::factory()->ownedBy($owner)->onPlan('growth')->create(['handle' => 'royal.canopies']);
        $this->actingAs($owner);

        $this->get('/vendor/royal.canopies')->assertOk();
        $this->get('/vendor/royal.canopies/bookings')->assertOk();
    }

    public function test_the_web_dashboard_is_a_growth_plan_feature(): void
    {
        $owner = User::factory()->create();
        Vendor::factory()->ownedBy($owner)->create(['handle' => 'royal.canopies']); // Starter
        $this->actingAs($owner);

        $this->get('/vendor/royal.canopies/bookings')->assertForbidden();
    }

    public function test_other_users_cannot_open_a_vendor_dashboard(): void
    {
        Vendor::factory()->ownedBy()->create(['handle' => 'royal.canopies']);
        $this->actingAs(User::factory()->create());

        $this->get('/vendor/royal.canopies/bookings')->assertForbidden();
    }
}
