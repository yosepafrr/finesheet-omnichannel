<?php

namespace Tests\Feature;

use App\Jobs\SyncShopeeReturnJob;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopeeReturnWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_push_targets_the_exact_return_instead_of_only_recent_returns(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Store::create([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'store_name' => 'Shopee Webhook Store',
            'shopee_shop_id' => '12345',
        ]);

        $key = 'test-partner-key';
        $url = 'https://finesheet.id/webhook/shopee';
        $body = json_encode([
            'code' => 29,
            'shop_id' => 12345,
            'data' => [
                'ordersn' => 'ORDER-OLD-RETURN',
                'return_sn' => 'RETURN-OLD-UPDATED-TODAY',
            ],
        ], JSON_UNESCAPED_SLASHES);

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
            ],
            $body,
        );

        $response->assertNoContent();
        Queue::assertPushedOn('orders', SyncShopeeReturnJob::class, function ($job) {
            return $job->returnSn === 'RETURN-OLD-UPDATED-TODAY'
                && ($job->returnData['ordersn'] ?? null) === 'ORDER-OLD-RETURN';
        });
    }
}
