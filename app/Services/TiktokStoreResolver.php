<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

class TiktokStoreResolver
{
    public function __construct(private TiktokService $tiktok)
    {
    }

    public function resolve($webhookShopId, ?string $orderId = null): ?Store
    {
        $shopId = trim((string) $webhookShopId);

        if ($shopId !== '') {
            $store = Store::query()
                ->where('platform', 'Tiktokshop')
                ->where(function ($query) use ($shopId) {
                    $query->where('platform_shop_id', $shopId)
                        ->orWhere('shopee_shop_id', $shopId);
                })
                ->first();

            if ($store) {
                return $store;
            }
        }

        if ($orderId) {
            $store = Order::query()
                ->where('platform', 'Tiktokshop')
                ->where('order_sn', $orderId)
                ->with('store')
                ->first()?->store;

            if ($store) {
                return $store;
            }
        }

        $stores = Store::query()
            ->where('platform', 'Tiktokshop')
            ->get();

        foreach ($stores as $store) {
            try {
                $accessToken = $this->tiktok->ensureValidToken($store);
                $response = $this->tiktok->getAuthorizedShop($accessToken);

                foreach ($response['data']['shops'] ?? [] as $shop) {
                    $authorizedId = (string) ($shop['id'] ?? '');
                    $cipher = (string) ($shop['cipher'] ?? '');

                    if ($cipher !== '' && $cipher === (string) $store->shopee_shop_id && $authorizedId !== '') {
                        $store->update(['platform_shop_id' => $authorizedId]);
                    }

                    if (self::authorizedShopMatches($shopId, $shop)) {
                        if ($authorizedId !== '' && $store->platform_shop_id !== $authorizedId) {
                            $store->update(['platform_shop_id' => $authorizedId]);
                        }

                        return $store;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Unable to resolve TikTok webhook store from authorized shops', [
                    'store_id' => $store->id,
                    'webhook_shop_id' => $shopId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($stores->count() === 1) {
            return $stores->first();
        }

        Log::warning('TikTok webhook store could not be resolved safely', [
            'webhook_shop_id' => $shopId,
            'order_id' => $orderId,
            'candidate_count' => $stores->count(),
        ]);

        return null;
    }

    public static function authorizedShopMatches($webhookShopId, array $authorizedShop): bool
    {
        $shopId = trim((string) $webhookShopId);
        if ($shopId === '') {
            return false;
        }

        return in_array($shopId, [
            (string) ($authorizedShop['id'] ?? ''),
            (string) ($authorizedShop['cipher'] ?? ''),
        ], true);
    }
}
