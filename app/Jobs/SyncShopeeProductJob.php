<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncShopeeProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $storeId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->storeId ?? 'all');
    }

    public function handle(ShopeeService $shopee): void
    {
        $stores = Store::query()
            ->where('platform', 'Shopee')
            ->when($this->storeId, fn ($query) => $query->where('id', $this->storeId))
            ->get();

        foreach ($stores as $store) {
            try {
                $items = $shopee->getItemList($store);

                foreach ($items as $item) {
                    if (! empty($item['item_id'])) {
                        HandleShopeeProductWebhookJob::dispatch(
                            $store->shopee_shop_id,
                            $item['item_id']
                        )->onQueue('products');
                    }
                }

                Log::info('Shopee product detail jobs queued', [
                    'store_id' => $store->id,
                    'count' => count($items),
                ]);
            } catch (Throwable $e) {
                Log::error('Shopee product list sync failed', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);

                if ($this->storeId) {
                    throw $e;
                }
            }
        }
    }
}
