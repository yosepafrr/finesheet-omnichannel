<?php

namespace App\Http\Controllers;

use App\Jobs\HandleShopeeOrderWebhookJob;
use App\Jobs\HandleShopeeProductWebhookJob;
use App\Jobs\SyncShopeeReturnJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Models\Store;
use App\Services\ShopeeWebhookSignatureVerifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopeeWebhookController extends Controller
{
    private const ORDER_PUSH_CODES = [3, 4, 15, 23, 24, 25, 30, 47];

    private const PACKAGE_PUSH_CODES = [30, 47];

    private const PRODUCT_PUSH_CODES = [6, 8, 16, 17, 22, 27];

    private const AUTHORIZATION_PUSH_CODES = [1, 2, 12];

    private const RETURN_PUSH_CODE = 29;

    public function handleWebhook(
        Request $request,
        ShopeeWebhookSignatureVerifier $signatureVerifier
    ) {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload'], 400);
        }

        $code = is_numeric($payload['code'] ?? null) ? (int) $payload['code'] : null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $shopId = $payload['shop_id'] ?? $data['shop_id'] ?? null;
        $signatureUrls = $this->signatureUrls($request);
        $signature = $this->signatureFromRequest($request);

        Log::info('Shopee webhook received', [
            'code' => $code,
            'shop_id' => $shopId,
            'request_url' => $request->url(),
            'configured_url' => config('shopee.webhook_url'),
        ]);

        if (! $signatureVerifier->verify(
            $rawBody,
            $signature,
            $signatureUrls
        )) {
            Log::warning('Shopee webhook signature verification failed', [
                'code' => $code,
                'shop_id' => $shopId,
                'signature_present' => (bool) $signature,
                'candidate_urls' => $signatureUrls,
                'configured_url' => config('shopee.webhook_url'),
                'forwarded_proto' => $request->header('X-Forwarded-Proto'),
                'forwarded_host' => $request->header('X-Forwarded-Host'),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        if ($code === 0) {
            return $this->successResponse();
        }

        if (! $shopId || ! $code) {
            return response()->json(['message' => 'Invalid payload format'], 400);
        }

        if (in_array($code, self::AUTHORIZATION_PUSH_CODES, true)) {
            $this->handleAuthorizationPush($code, $this->findStore($shopId), $data, $shopId);

            return $this->successResponse();
        }

        $orderSn = $this->extractOrderSn($data);

        if ($code === self::RETURN_PUSH_CODE) {
            $store = $this->findStore($shopId);

            if ($orderSn) {
                $this->dispatchOrderSync($shopId, $orderSn, $code);
            }

            if ($store) {
                SyncShopeeReturnJob::dispatch($store)->onQueue('orders');
            } else {
                Log::warning('Shopee return push ignored because store was not found', [
                    'shop_id' => $shopId,
                ]);
            }

            return $this->successResponse();
        }

        if (in_array($code, self::ORDER_PUSH_CODES, true)) {
            if ($orderSn) {
                $this->dispatchOrderSync($shopId, $orderSn, $code);
            }

            if (in_array($code, self::PACKAGE_PUSH_CODES, true)) {
                $store = $this->findStore($shopId);
                if ($store) {
                    SyncStoreLogisticsJob::dispatch($store->id, false, 'webhook')
                        ->onQueue('logistics');
                }
            }

            return $this->successResponse();
        }

        if (in_array($code, self::PRODUCT_PUSH_CODES, true)) {
            $itemId = data_get($data, 'item_id') ?? data_get($data, 'item.item_id');
            if ($itemId) {
                Log::info('Dispatching Shopee product webhook job', [
                    'shop_id' => $shopId,
                    'item_id' => $itemId,
                    'code' => $code,
                ]);
                HandleShopeeProductWebhookJob::dispatch($shopId, $itemId)->onQueue('products');
            }
        }

        return $this->successResponse();
    }

    private function signatureUrls(Request $request): array
    {
        $path = '/'.ltrim($request->path(), '/');
        $forwardedProto = $this->firstHeaderValue($request->header('X-Forwarded-Proto'));
        $forwardedHost = $this->firstHeaderValue($request->header('X-Forwarded-Host'));

        $urls = [$request->url()];

        if ($forwardedProto) {
            $urls[] = $forwardedProto.'://'.($forwardedHost ?: $request->getHttpHost()).$path;
        }

        $urls[] = 'https://'.$request->getHttpHost().$path;

        return array_values(array_unique(array_filter($urls)));
    }

    private function signatureFromRequest(Request $request): ?string
    {
        return $request->header('Authorization')
            ?? $request->server('HTTP_AUTHORIZATION')
            ?? $request->server('REDIRECT_HTTP_AUTHORIZATION');
    }

    private function firstHeaderValue(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return trim(explode(',', $value)[0]);
    }

    private function findStore(string|int $shopId): ?Store
    {
        return Store::query()
            ->where('platform', 'Shopee')
            ->where('shopee_shop_id', (string) $shopId)
            ->first();
    }

    private function extractOrderSn(array $data): ?string
    {
        $orderSn = data_get($data, 'ordersn')
            ?? data_get($data, 'order_sn')
            ?? data_get($data, 'order.order_sn')
            ?? data_get($data, 'package.order_sn')
            ?? data_get($data, 'package_list.0.order_sn');

        return $orderSn ? (string) $orderSn : null;
    }

    private function dispatchOrderSync(string|int $shopId, string $orderSn, int $code): void
    {
        Log::info('Dispatching Shopee order webhook job', [
            'shop_id' => $shopId,
            'order_sn' => $orderSn,
            'code' => $code,
        ]);

        HandleShopeeOrderWebhookJob::dispatch($shopId, $orderSn)->onQueue('orders');
    }

    private function handleAuthorizationPush(
        int $code,
        ?Store $store,
        array $data,
        string|int $shopId
    ): void {
        if (! $store) {
            Log::warning('Shopee authorization push received for an unknown store', [
                'shop_id' => $shopId,
                'code' => $code,
            ]);

            return;
        }

        if ($code === 2) {
            $store->update([
                'access_token' => null,
                'refresh_token' => null,
                'token_expired_at' => now(),
                'shop_expired_at' => now(),
            ]);

            Log::warning('Shopee store authorization was canceled', ['store_id' => $store->id]);

            return;
        }

        if ($code === 12) {
            $expiration = $this->extractAuthorizationExpiration($data);
            if ($expiration) {
                $store->update(['shop_expired_at' => $expiration]);
            }

            Log::warning('Shopee store authorization is approaching expiry', [
                'store_id' => $store->id,
                'shop_expired_at' => $expiration?->toIso8601String(),
            ]);
        }
    }

    private function extractAuthorizationExpiration(array $data): ?Carbon
    {
        $value = data_get($data, 'expire_time')
            ?? data_get($data, 'shop_expire_time')
            ?? data_get($data, 'authorization_expire_time');

        if (! $value) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (int) $value;
                if ($timestamp > 20_000_000_000) {
                    $timestamp = (int) floor($timestamp / 1000);
                }

                return Carbon::createFromTimestamp($timestamp);
            }

            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function successResponse()
    {
        return response()->json(['code' => 0, 'message' => 'success']);
    }
}
