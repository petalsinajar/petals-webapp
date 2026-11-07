<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_creates_a_vendor_and_becomes_its_owner(): void
    {
        $this->signIn();

        $vendor = $this->postJson('/v1/vendors', [
            'handle' => 'royal.canopies',
            'name' => 'Royal Canopies',
            'event_types' => ['wedding', 'corporate'],
        ])->assertCreated()->assertJson(['handle' => 'royal.canopies', 'my_role' => 'owner'])->json();

        $this->getJson('/v1/me')->assertJsonPath('vendors.0.id', $vendor['id'])->assertJsonPath('vendors.0.role', 'owner');
    }

    public function test_vendor_and_user_handles_share_one_namespace(): void
    {
        User::factory()->create(['handle' => 'glow.decor']);
        $this->signIn();

        $this->postJson('/v1/vendors', ['handle' => 'glow.decor', 'name' => 'Glow'])
            ->assertStatus(422)->assertJsonValidationErrors('handle');
    }

    public function test_staff_can_operate_but_not_manage(): void
    {
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->ownedBy($owner)->create();
        $staff = User::factory()->create(['phone' => '+233209999999']);

        $this->signIn($owner);
        $this->postJson("/v1/vendors/{$vendor->id}/members", ['phone' => '0209999999', 'role' => 'staff'])->assertCreated();

        $this->signIn($staff);
        $this->getJson("/v1/vendors/{$vendor->id}/members")->assertOk();
        $this->patchJson("/v1/vendors/{$vendor->id}", ['name' => 'Hijacked'])->assertForbidden();
    }

    public function test_only_owners_change_policies(): void
    {
        $vendor = Vendor::factory()->ownedBy()->create();
        $manager = User::factory()->create();
        $vendor->members()->create(['user_id' => $manager->id, 'role' => 'manager', 'status' => 'active']);

        $this->signIn($manager);
        $this->patchJson("/v1/vendors/{$vendor->id}", ['bio' => 'New bio'])->assertOk();
        $this->patchJson("/v1/vendors/{$vendor->id}", ['policies' => ['deposit_percent' => 0]])->assertForbidden();
    }
}
