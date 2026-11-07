<?php

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_creates_an_account(): void
    {
        $this->postJson('/v1/auth/otp', ['phone' => '024 123 4567'])->assertOk()->assertJson(['phone' => '+233241234567']);

        $response = $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '123456', 'device_name' => 'pixel'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_at', 'is_new', 'user' => ['id', 'handle', 'vendors', 'needs_profile']]);

        $this->assertTrue($response->json('is_new'));
        $this->assertTrue($response->json('user.needs_profile'));
        $this->assertDatabaseHas('handles', ['handle' => $response->json('user.handle'), 'owner_type' => 'user']);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/v1/auth/otp', ['phone' => '0241234567']);

        $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '000000', 'device_name' => 'pixel'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_access_token_works_and_refresh_token_does_not(): void
    {
        $this->postJson('/v1/auth/otp', ['phone' => '0241234567']);
        $tokens = $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '123456', 'device_name' => 'pixel'])->json();

        $this->withToken($tokens['access_token'])->getJson('/v1/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['refresh_token'])->getJson('/v1/me')->assertForbidden();
    }

    public function test_refresh_rotates_the_token(): void
    {
        $this->postJson('/v1/auth/otp', ['phone' => '0241234567']);
        $tokens = $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '123456', 'device_name' => 'pixel'])->json();

        $new = $this->postJson('/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json();
        $this->assertNotSame($tokens['access_token'], $new['access_token']);

        // The old refresh token can't be used twice.
        $this->postJson('/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(422);
    }

    public function test_profile_setup_claims_a_handle(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create(['handle' => 'taken.name']);

        $this->patchJson('/v1/me', ['handle' => 'taken.name'])->assertStatus(422)->assertJsonValidationErrors('handle');

        $this->patchJson('/v1/me', ['display_name' => 'Ama Mensah', 'handle' => 'ama.events'])
            ->assertOk()->assertJson(['handle' => 'ama.events', 'display_name' => 'Ama Mensah']);

        $this->assertDatabaseHas('handles', ['handle' => 'ama.events', 'owner_id' => $user->id]);
        $this->assertDatabaseHas('handles', ['handle' => 'taken.name', 'owner_id' => $other->id]);
    }

    public function test_suspended_users_are_locked_out(): void
    {
        $user = $this->signIn();
        $user->forceFill(['status' => 'suspended'])->save();

        $this->getJson('/v1/me')->assertForbidden();
    }
}
