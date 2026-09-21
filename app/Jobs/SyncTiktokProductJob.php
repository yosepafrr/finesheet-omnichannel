<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\TiktokService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTiktokProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public function __construct(public ?int $storeId = null) {}

    public function uniqueId(): string
    {
        return (string) ($this->storeId ?? 'all');
    }

    public function handle(TiktokService $tiktok): void
    {
        $stores = Store::query()
            ->where('platform', 'Tiktokshop')
            ->when($this->storeId, fn ($query) => $query->where('id', $this->storeId))
            ->get();

        foreach ($stores as $store) {
            try {
                $pageToken = '';
                $seenTokens = [];
                $queued = 0;

                do {
                    $response = $tiktok->getProductList($store, $pageToken);

                    foreach ($response['data']['products'] ?? [] as $product) {
                        if (! empty($product['id'])) {
                            HandleTiktokProductWebhookJob::dispatch(
                                $store->shopee_shop_id,
                                $product['id']
                            )->onQueue('products');
                            $queued++;
                        }
                    }

                    $nextPageToken = (string) ($response['data']['next_page_token'] ?? '');
                    if ($nextPageToken === '' || isset($seenTokens[$nextPageToken])) {
                        break;
                    }

                    $seenTokens[$nextPageToken] = true;
                    $pageToken = $nextPageToken;
                } while (true);

                Log::info('TikTok product detail jobs queued', [
                    'store_id' => $store->id,
                    'count' => $queued,
                ]);
            } catch (Throwable $e) {
                Log::error('TikTok product list sync failed', [
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
