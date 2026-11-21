<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Filament dashboards boot and enforce who can see them. */
class PanelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render_for_admins(): void
    {
        $this->get('/admin/login')->assertOk();
        $this->actingAs(User::factory()->admin()->create());

        foreach (['/admin', '/admin/users', '/admin/vendors', '/admin/bookings', '/admin/posts', '/admin/reviews', '/admin/event-types', '/admin/plans', '/admin/subscriptions', '/admin/support-requests', '/admin/items'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_seeded_admin_is_a_super_admin_who_sees_campaigns(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@eventapp.test')->firstOrFail();

        $this->assertTrue($admin->isSuperAdmin());
        $this->actingAs($admin)->get('/admin/campaigns')->assertOk();
    }

    public function test_the_seeded_campaigns_admin_sees_campaigns_only(): void
    {
        $this->seed();
        $admin = User::where('email', 'campaigns@eventapp.test')->firstOrFail();

        $this->assertFalse($admin->isSuperAdmin());
        $this->actingAs($admin)->get('/admin/campaigns')->assertOk();
    }

    public function test_super_admins_grant_admin_areas_on_the_users_screen(): void
    {
        $user = User::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $user, data: ['is_admin' => true, 'admin_permissions' => ['campaigns']])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($user->fresh()->hasAdminPermission('campaigns'));
        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_other_admins_cannot_grant_admin_areas(): void
    {
        $user = User::factory()->create();
        $this->actingAs(User::factory()->admin(['campaigns'])->create());

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $user, data: ['is_admin' => true, 'admin_permissions' => ['campaigns'], 'is_super_admin' => true]);

        $this->assertNull($user->fresh()->admin_permissions);
        $this->assertFalse($user->fresh()->is_super_admin);
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
