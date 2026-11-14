<?php

namespace Tests\Feature;

use App\Modules\Identity\Contracts\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The OTP endpoint as production runs it: a real code, sent through CB Messaging. */
class OtpSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // OtpService only texts real codes outside local/testing.
        $this->app['env'] = 'production';
        config([
            'eventapp.sms.driver' => 'cbmessaging',
            'eventapp.sms.cbmessaging.url' => 'https://cbmessaging.test/api/v1/',
            'eventapp.sms.cbmessaging.key' => 'key',
            'eventapp.sms.cbmessaging.secret' => 'secret',
            'eventapp.sms.cbmessaging.sender_id' => 'Petals',
        ]);
        $this->app->forgetInstance(SmsSender::class);
    }

    public function test_the_code_is_texted_through_cb_messaging(): void
    {
        Http::fake(['cbmessaging.test/*' => Http::response(['status' => 'ok'])]);

        $this->postJson('/v1/auth/otp', ['phone' => '024 123 4567'])->assertOk();

        Http::assertSent(fn (Request $request) => $request['to'] === '233241234567'
            && preg_match('/^Your Petalsinajar code is \d{6}\. It expires in 10 minutes\.$/', $request['msg']) === 1);
    }

    public function test_a_gateway_failure_tells_the_app_to_retry(): void
    {
        Http::fake(['cbmessaging.test/*' => Http::response(['message' => 'Insufficient credit'], 402)]);

        $this->postJson('/v1/auth/otp', ['phone' => '0241234567'])
            ->assertStatus(503)
            ->assertJson(['message' => "We couldn't send the text message. Please try again in a moment."]);
    }

    public function test_the_store_review_phone_signs_in_with_its_code_and_no_sms(): void
    {
        config(['eventapp.auth.review_phone' => '0544874686', 'eventapp.auth.review_code' => '443944']);
        Http::fake();

        $this->postJson('/v1/auth/otp', ['phone' => '054 487 4686'])->assertOk();
        Http::assertNothingSent();

        $this->postJson('/v1/auth/verify', ['phone' => '0544874686', 'code' => '000000', 'device_name' => 'Pixel 8'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/v1/auth/verify', ['phone' => '+233544874686', 'code' => '443944', 'device_name' => 'Pixel 8'])
            ->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_other_phones_still_get_a_real_texted_code(): void
    {
        config(['eventapp.auth.review_phone' => '0544874686', 'eventapp.auth.review_code' => '443944']);
        Http::fake(['cbmessaging.test/*' => Http::response(['status' => 'ok'])]);

        $this->postJson('/v1/auth/otp', ['phone' => '0241234567'])->assertOk();
        Http::assertSentCount(1);
        $this->postJson('/v1/auth/verify', ['phone' => '0241234567', 'code' => '443944', 'device_name' => 'Pixel 8'])
            ->assertUnprocessable();
    }

    public function test_without_settings_the_review_login_is_off(): void
    {
        config(['eventapp.auth.review_phone' => '0544874686', 'eventapp.auth.review_code' => null]);
        Http::fake(['cbmessaging.test/*' => Http::response(['status' => 'ok'])]);

        $this->postJson('/v1/auth/otp', ['phone' => '0544874686'])->assertOk();
        Http::assertSentCount(1);
    }
}
