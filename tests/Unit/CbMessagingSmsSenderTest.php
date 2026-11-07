<?php

namespace Tests\Unit;

use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use App\Modules\Identity\Services\CbMessagingSmsSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CbMessagingSmsSenderTest extends TestCase
{
    private function sender(): CbMessagingSmsSender
    {
        return new CbMessagingSmsSender(
            key: 'key-123',
            secret: 'secret-456',
            senderId: 'Petals',
            baseUrl: 'https://cbmessaging.test/api/v1/',
        );
    }

    public function test_posts_the_message_with_basic_auth(): void
    {
        Http::fake(['cbmessaging.test/*' => Http::response(['status' => 'ok'])]);

        $this->sender()->send('+233241234567', 'Your code is 123456.');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://cbmessaging.test/api/v1/sms/send-message'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('key-123:secret-456'))
            && $request['to'] === '233241234567'
            && $request['msg'] === 'Your code is 123456.'
            && $request['senderID'] === 'Petals');
    }

    public function test_a_gateway_error_is_a_delivery_failure(): void
    {
        Http::fake(['cbmessaging.test/*' => Http::response(['message' => 'Insufficient credit'], 402)]);

        $this->expectException(SmsDeliveryFailed::class);
        $this->sender()->send('+233241234567', 'hi');
    }

    public function test_an_unreachable_gateway_is_a_delivery_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(SmsDeliveryFailed::class);
        $this->sender()->send('+233241234567', 'hi');
    }

    public function test_the_failure_renders_as_a_503_without_leaking_the_message(): void
    {
        $response = (new SmsDeliveryFailed('gateway said no'))->render();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('gateway said no', $response->getContent());
    }

    public function test_missing_credentials_fail_loudly_at_construction(): void
    {
        config([
            'eventapp.sms.cbmessaging.key' => 'k',
            'eventapp.sms.cbmessaging.secret' => '',
            'eventapp.sms.cbmessaging.sender_id' => 'Petals',
        ]);

        $this->expectException(InvalidArgumentException::class);
        CbMessagingSmsSender::fromConfig();
    }

    public function test_sender_ids_longer_than_11_characters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CbMessagingSmsSender('k', 's', 'Petalsinajar', 'https://cbmessaging.test/api/v1/');
    }
}
