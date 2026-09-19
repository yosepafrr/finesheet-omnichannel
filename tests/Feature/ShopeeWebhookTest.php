<?php

namespace Tests\Feature;

use App\Jobs\HandleShopeeOrderWebhookJob;
use App\Jobs\SyncShopeeReturnJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopeeWebhookTest extends TestCase
{
    public function test_shopee_verification_push_bypasses_csrf_and_returns_success(): void
    {
        $key = 'test-partner-key';
        $url = 'https://finesheet.id/webhook/shopee';
        $body = '{"code":0}';

        config()->set('shopee.partner_key', $key);
        config()->set('shopee.live_push_partner_key', $key);
        config()->set('shopee.webhook_url', $url);

        $response = $this->call(
            'POST',
            '/webhook/shopee',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$body, $key),
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'finesheet.id',
            ],
            $body
        );

        $response->assertOk()->assertExactJson([
            'code' => 0,
            'message' => 'success',
        ]);
    }

    public function test_shopee_webhook_rejects_an_invalid_signature(): void
    {
        config()->set('shopee.partner_key', 'test-partner-key');
        config()->set('shopee.live_push_partner_key', 'test-partner-key');
        config()->set('shopee.webhook_url', 'https://finesheet.id/webhook/shopee');

        $response = $this->call(
            'POST',
            '/webhook/shopee',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => str_repeat('a', 64),
            ],
            '{"code":0}'
        );

        $response->assertUnauthorized();
    }

    public function test_order_push_is_dispatched_to_the_orders_queue(): void
    {
        Queue::fake();

        $response = $this->signedWebhookRequest([
            'code' => 3,
            'shop_id' => 12345,
            'data' => ['ordersn' => 'ORDER-123'],
        ]);

        $response->assertOk();
        Queue::assertPushedOn('orders', HandleShopeeOrderWebhookJob::class);
    }

    public function test_booking_tracking_push_is_not_treated_as_a_return(): void
    {
        Queue::fake();

        $response = $this->signedWebhookRequest([
            'code' => 24,
            'shop_id' => 12345,
            'data' => ['ordersn' => 'ORDER-123'],
        ]);

        $response->assertOk();
        Queue::assertPushedOn('orders', HandleShopeeOrderWebhookJob::class);
        Queue::assertNotPushed(SyncShopeeReturnJob::class);
    }

    private function signedWebhookRequest(array $payload)
    {
        $key = 'test-partner-key';
        $url = 'https://finesheet.id/webhook/shopee';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        config()->set('shopee.partner_key', $key);
        config()->set('shopee.live_push_partner_key', $key);
        config()->set('shopee.webhook_url', $url);

        return $this->call(
            'POST',
            '/webhook/shopee',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => hash_hmac('sha256', $url.'|'.$body, $key),
            ],
            $body
        );
    }
}
