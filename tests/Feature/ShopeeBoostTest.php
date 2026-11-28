<?php

namespace Tests\Feature;

use App\Jobs\SyncShopeeBoostJob;
use App\Models\Product;
use App\Models\ShopeeBoostSetting;
use App\Models\ShopeeProductBoost;
use App\Models\Store;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\ShopeeBoostService;
use App\Services\ShopeeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopeeBoostTest extends TestCase
{
    use RefreshDatabase;

    private function storeFor(?User $user = null, string $platform = 'Shopee'): Store
    {
        return Store::create(['user_id' => ($user ?? User::factory()->create())->id, 'platform' => $platform,
            'store_name' => 'Toko Boost', 'shopee_shop_id' => (string) random_int(1000, 9999999),
            'access_token' => 'secret', 'token_expired_at' => now()->addDay()]);
    }

    private function select(Store $store, array $ids = ['100']): void
    {
        ShopeeBoostSetting::create(['store_id' => $store->id, 'enabled' => true, 'next_check_at' => now()]);
        foreach ($ids as $id) {
            Product::create(['store_id' => $store->id, 'platform' => 'Shopee', 'product_id' => $id,
                'product_name' => 'Kemeja '.$id, 'product_status' => 'NORMAL', 'product_sku' => 'KMJ-'.$id]);
            ShopeeProductBoost::create(['store_id' => $store->id, 'item_id' => $id, 'selected' => true]);
        }
    }

    public function test_settings_are_owned_limited_to_five_and_can_be_emptied_without_stopping_active_boosts(): void
    {
        Queue::fake();
        $store = $this->storeFor();
        $this->select($store);
        $this->actingAs($store->user);
        $this->putJson('/api/shopee-boost/stores/'.$store->id, ['enabled' => true, 'item_ids' => ['100']])->assertOk();
        Queue::assertPushed(SyncShopeeBoostJob::class, fn ($job) => $job->storeId === $store->id && $job->queue === 'products');
        $this->putJson('/api/shopee-boost/stores/'.$store->id, ['enabled' => true, 'item_ids' => ['1', '2', '3', '4', '5', '6']])->assertUnprocessable();
        $other = $this->storeFor();
        $this->select($other, ['200']);
        $this->putJson('/api/shopee-boost/stores/'.$store->id, ['enabled' => true, 'item_ids' => ['200']])->assertUnprocessable();
        $this->postJson('/api/shopee-boost/stores/'.$other->id.'/refresh')->assertNotFound();
        $this->putJson('/api/shopee-boost/stores/'.$other->id, ['enabled' => true, 'item_ids' => ['200']])->assertNotFound();
        $entry = ShopeeProductBoost::where('store_id', $store->id)->first();
        $entry->update(['active_until' => now()->addHour()]);
        $this->putJson('/api/shopee-boost/stores/'.$store->id, ['enabled' => false, 'item_ids' => []])->assertOk();
        $this->assertFalse($entry->fresh()->selected);
        $this->assertTrue($entry->fresh()->active_until->isFuture());
        $this->getJson('/api/shopee-boost')->assertOk()->assertJsonCount(1, 'stores')
            ->assertJsonPath('stores.0.items.0.selected', false)->assertJsonPath('stores.0.active_count', 1);
    }

    public function test_external_boosts_consume_slots_and_have_product_previews(): void
    {
        $store = $this->storeFor();
        $this->select($store, ['100', '101']);
        $api = $this->mock(ShopeeService::class);
        $items = array_map(fn ($id) => ['item_id' => $id, 'cool_down_second' => 600], [101, 201, 202, 203, 204]);
        $api->shouldReceive('getBoostedList')->once()->andReturn(['item_list' => $items]);
        $api->shouldNotReceive('boostItems');
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->actingAs($store->user)->getJson('/api/shopee-boost')->assertOk()
            ->assertJsonPath('stores.0.active_count', 5)->assertJsonPath('stores.0.items.1.name', 'Kemeja 101');
        $this->assertDatabaseHas('shopee_product_boosts', ['store_id' => $store->id, 'item_id' => '201', 'selected' => false]);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '100', 'boost_count' => 0]);
    }

    public function test_only_free_slots_are_used_and_success_is_repeated_after_expiry(): void
    {
        $store = $this->storeFor();
        $this->select($store, ['100', '101']);
        $api = $this->mock(ShopeeService::class);
        $external = array_map(fn ($id) => ['item_id' => $id, 'cool_down_second' => 100], [201, 202, 203, 204]);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => $external]);
        $api->shouldReceive('boostItems')->once()->withArgs(fn ($s, $ids) => $s->id === $store->id && $ids === ['100'])
            ->ordered()->andReturn(['success_list' => ['item_id_list' => [100]], 'failure_list' => []]);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => [...$external, ['item_id' => 100, 'cool_down_second' => 14400]]]);
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '100', 'boost_count' => 1]);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '101', 'boost_count' => 0]);
        $this->travel(4)->hours();
        $this->travel(1)->minutes();
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => []]);
        $api->shouldReceive('boostItems')->once()->ordered()->andReturn(['success_list' => ['item_id_list' => [100, 101]], 'failure_list' => []]);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => [['item_id' => 100, 'cool_down_second' => 14400], ['item_id' => 101, 'cool_down_second' => 14400]]]);
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '100', 'boost_count' => 2]);
    }

    public function test_partial_failure_does_not_claim_success_and_paused_stores_never_boost(): void
    {
        $store = $this->storeFor();
        $this->select($store, ['100', '101']);
        $api = $this->mock(ShopeeService::class);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => []]);
        $api->shouldReceive('boostItems')->once()->ordered()->andReturn(['success_list' => ['item_id_list' => [100]], 'failure_list' => [['item_id' => 101, 'failed_reason' => 'Slot penuh']]]);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => [['item_id' => 100, 'cool_down_second' => 12000]]]);
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '101', 'error' => 'Slot penuh', 'boost_count' => 0]);
        $setting = ShopeeBoostSetting::first();
        $this->assertTrue($setting->next_check_at->equalTo(now()->addMinutes(15)->startOfSecond()));
        $setting->update(['enabled' => false]);
        $api->shouldReceive('getBoostedList')->once()->ordered()->andReturn(['item_list' => []]);
        app(ShopeeBoostService::class)->run($store->id, $api);
    }

    public function test_invalid_slot_response_and_permission_error_fail_closed(): void
    {
        $store = $this->storeFor();
        $this->select($store);
        $api = $this->mock(ShopeeService::class);
        $api->shouldReceive('getBoostedList')->once()->andReturn(['unexpected' => []]);
        $api->shouldNotReceive('boostItems');
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertStringContainsString('tidak valid', ShopeeBoostSetting::first()->error);
        $this->assertDatabaseHas('shopee_product_boosts', ['boost_count' => 0]);
    }

    public function test_api_signs_requests_and_keeps_item_ids_numeric_and_errors_explicit(): void
    {
        config(['services.shopee.partner_id' => 123, 'services.shopee.partner_key' => 'key', 'shopee.base_url' => 'https://partner.test']);
        $store = $this->storeFor();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()
            ->push(['error' => '', 'response' => ['item_list' => [['item_id' => 100, 'cool_down_second' => 500]]]])
            ->push(['error' => '', 'response' => ['success_list' => ['item_id_list' => [100]], 'failure_list' => []]])
            ->push(['error' => 'error_permission', 'message' => 'Access denied', 'request_id' => 'request-123'])]);
        $api = app(ShopeeService::class);
        $this->assertSame(100, $api->getBoostedList($store)['item_list'][0]['item_id']);
        $api->boostItems($store, ['100']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['item_id_list'] === [100] && str_contains($r->url(), 'sign='));
        $this->expectExceptionMessage('Access denied (request request-123)');
        $api->getBoostedList($store);
    }

    public function test_catalog_is_searchable_and_boost_requires_explicit_operator_permission(): void
    {
        Queue::fake();
        $store = $this->storeFor();
        $this->select($store);
        $this->actingAs($store->user)->getJson('/api/shopee-boost/stores/'.$store->id.'/products?search=100')
            ->assertOk()->assertJsonPath('data.0.item_id', '100');
        $profile = $store->user->accessProfiles()->create(['name' => 'Viewer', 'layout' => 'custom', 'permissions' => ['products.view'], 'is_active' => true]);
        $this->withSession([AccessControlService::SESSION_KEY => $profile->id])->getJson('/api/shopee-boost')->assertForbidden();
        $profile->update(['permissions' => ['products.view', 'products.boost.manage']]);
        $this->getJson('/api/shopee-boost')->assertOk();
    }

    public function test_store_lock_prevents_duplicate_calls_and_job_is_unique_per_store(): void
    {
        $store = $this->storeFor();
        $this->select($store);
        $lock = Cache::lock(ShopeeBoostService::lockKey($store->id), 240);
        $lock->get();
        try {
            $api = $this->mock(ShopeeService::class);
            $api->shouldNotReceive('getBoostedList');
            app(ShopeeBoostService::class)->run($store->id, $api);
            $this->actingAs($store->user)->putJson('/api/shopee-boost/stores/'.$store->id, ['enabled' => false, 'item_ids' => []])->assertConflict();
        } finally {
            $lock->release();
        }
        $this->assertSame((string) $store->id, (new SyncShopeeBoostJob($store->id))->uniqueId());
    }

    public function test_scheduler_only_dispatches_enabled_due_stores(): void
    {
        Queue::fake();
        $due = $this->storeFor();
        $future = $this->storeFor();
        $paused = $this->storeFor();
        foreach ([[$due, true, now()->subMinute()], [$future, true, now()->addHour()], [$paused, false, now()->subMinute()]] as [$store, $enabled, $at]) {
            ShopeeBoostSetting::create(['store_id' => $store->id, 'enabled' => $enabled, 'next_check_at' => $at]);
        }
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(fn ($event) => $event->description === 'dispatch-shopee-product-boost');
        $this->assertNotNull($event);
        $event->run(app());
        Queue::assertPushed(SyncShopeeBoostJob::class, 1);
        Queue::assertPushed(SyncShopeeBoostJob::class, fn ($job) => $job->storeId === $due->id);
    }

    public function test_inactive_products_are_not_boosted_and_api_errors_are_saved_for_monitoring(): void
    {
        $store = $this->storeFor();
        $this->select($store);
        Product::where('store_id', $store->id)->update(['product_status' => 'UNLIST']);
        $api = $this->mock(ShopeeService::class);
        $api->shouldReceive('getBoostedList')->once()->andReturn(['item_list' => []]);
        $api->shouldNotReceive('boostItems');
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertDatabaseHas('shopee_product_boosts', ['item_id' => '100', 'boost_count' => 0, 'error' => 'Produk tidak tersedia atau tidak aktif di katalog marketplace.']);
        $api->shouldReceive('getBoostedList')->once()->andThrow(new \RuntimeException('Izin Shopee belum tersedia'));
        app(ShopeeBoostService::class)->run($store->id, $api);
        $this->assertSame('Izin Shopee belum tersedia', ShopeeBoostSetting::first()->error);
        $this->assertTrue(ShopeeBoostSetting::first()->next_check_at->isFuture());
    }
}
