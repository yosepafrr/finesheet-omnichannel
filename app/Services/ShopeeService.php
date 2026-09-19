<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopeeService
{
    private const ORDER_DETAIL_OPTIONAL_FIELDS = [
        'buyer_user_id',
        'buyer_username',
        'estimated_shipping_fee',
        'recipient_address',
        'actual_shipping_fee',
        'actual_shipping_fee_confirmed',
        'goods_to_declare',
        'note',
        'note_update_time',
        'item_list',
        'pay_time',
        'dropshipper',
        'dropshipper_phone',
        'split_up',
        'buyer_cancel_reason',
        'cancel_by',
        'cancel_reason',
        'fulfillment_flag',
        'pickup_done_time',
        'package_list',
        'shipping_carrier',
        'payment_method',
        'total_amount',
        'invoice_data',
        'checkout_shipping_carrier',
    ];

    protected $partnerId;
    protected $partnerKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->partnerId = config('services.shopee.partner_id');
        $this->partnerKey = config('services.shopee.partner_key');
        $this->baseUrl = config('shopee.base_url');
        Log::info('ShopeeService constructed', [
            'partner_id' => $this->partnerId,
        ]);
    }

    /**
     * Get HTTP Client with conditional SSL Verification
     */
    protected function httpClient()
    {
        $client = Http::timeout(30)->connectTimeout(10);

        return app()->isLocal() ? $client->withoutVerifying() : $client;
    }

    public function ensureValidToken(Store $store)
    {
        $expiresAt = $store->token_expired_at ? Carbon::parse($store->token_expired_at) : null;
        if (!$expiresAt || now()->addMinutes(60)->gte($expiresAt)) {
            $this->refreshAccessToken($store);

            if ($store->exists) {
                $store->refresh();
                $expiresAt = $store->token_expired_at ? Carbon::parse($store->token_expired_at) : null;
            }
        }

        if (empty($store->access_token) || !$expiresAt || $expiresAt->isPast()) {
            throw new RuntimeException("Token Shopee untuk toko {$store->shopee_shop_id} sudah kedaluwarsa dan gagal diperbarui.");
        }

        return $store->access_token;
    }

    public function exchangeAuthorizationCode(string $code, int $shopId): array
    {
        $path = '/api/v2/auth/token/get';
        $timestamp = time();
        $baseString = $this->partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $this->partnerKey);
        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}";
        $body = [
            'code' => $code,
            'shop_id' => $shopId,
        ];

        $response = $this->httpClient()
            ->withBody(json_encode($body, JSON_THROW_ON_ERROR), 'application/json')
            ->post($url);
        $result = $response->json() ?? [];

        Log::info('Shopee - Token exchange response', [
            'shop_id' => $shopId,
            'status' => $response->status(),
            'has_access_token' => !empty($result['access_token']),
            'has_refresh_token' => !empty($result['refresh_token']),
            'error' => $result['error'] ?? null,
            'message' => $result['message'] ?? null,
            'request_id' => $result['request_id'] ?? null,
        ]);

        return $result;
    }

    // FUNGSI AMBIL INFORMASI TOKO
    public function getShopProfile(Store $store)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $this->ensureValidToken($store);

        $path = '/api/v2/shop/get_shop_info';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $url = "{$this->baseUrl}{$path}";
        $params = [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
        ];

        $response = $this->httpClient()->get($url, $params);
        return $response->json();
    }

    // FUNGSI REFRESH ACCESS TOKEN
    public function refreshAccessToken(Store $store, bool $force = false)
    {
        $shopId = (int) $store->shopee_shop_id;

        try {
            return Cache::lock("shopee-token-refresh:{$shopId}", 30)->block(10, function () use ($store, $shopId, $force) {
                if ($store->exists) {
                    $store->refresh();
                }

                $expiresAt = $store->token_expired_at ? Carbon::parse($store->token_expired_at) : null;
                if (!$force && $expiresAt && now()->addMinutes(60)->lt($expiresAt)) {
                    return true;
                }

                $refreshToken = $store->refresh_token;
                if (empty($refreshToken)) {
                    Log::warning('Shopee token refresh skipped: missing refresh token', [
                        'store_id' => $store->id,
                        'shop_id' => $shopId,
                    ]);

                    return false;
                }

                $path = '/api/v2/auth/access_token/get';
                $timestamp = time();
                $baseString = $this->partnerId . $path . $timestamp;
                $refreshSign = hash_hmac('sha256', $baseString, $this->partnerKey);
                $url = "{$this->baseUrl}{$path}"
                    . "?partner_id={$this->partnerId}"
                    . "&timestamp={$timestamp}"
                    . "&sign={$refreshSign}";
                $body = [
                    'partner_id' => (int) $this->partnerId,
                    'shop_id' => $shopId,
                    'refresh_token' => $refreshToken,
                ];

                $response = $this->httpClient()
                    ->withBody(json_encode($body, JSON_THROW_ON_ERROR), 'application/json')
                    ->post($url);
                $json = $response->json() ?? [];
                $data = $json['response'] ?? $json;

                Log::info('Shopee - Refresh Access Token Response', [
                    'store_id' => $store->id,
                    'shop_id' => $shopId,
                    'status' => $response->status(),
                    'has_access_token' => !empty($data['access_token']),
                    'has_refresh_token' => !empty($data['refresh_token']),
                    'error' => $json['error'] ?? null,
                    'message' => $json['message'] ?? null,
                    'request_id' => $json['request_id'] ?? null,
                ]);

                if (!empty($data['access_token']) && !empty($data['refresh_token'])) {
                    $store->update([
                        'access_token' => $data['access_token'],
                        'refresh_token' => $data['refresh_token'],
                        'token_expired_at' => now()->addSeconds((int) ($data['expire_in'] ?? 14400)),
                    ]);

                    return true;
                }

                return false;
            });
        } catch (\Throwable $e) {
            Log::error('Shopee token refresh failed', [
                'store_id' => $store->id,
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function generateSign($path, $timestamp, $accessToken, $shopId)
    {
        $baseString = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
        return hash_hmac('sha256', $baseString, $this->partnerKey);
    }

    // AMBIL DATA LIST ORDER
    public function getOrderList(string $accessToken, string $shopId, int $timeFrom, int $timeTo)
    {
        $path = '/api/v2/order/get_order_list';
        Log::info('Shopee - Fetching Order List', [
            'partner_id' => $this->partnerId,
            'shop_id' => $shopId,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
        ]);

        $cursor = '';
        $allOrders = [];
        $result = [];
        $page = 0;

        do {
            $page++;
            $timestamp = time();
            $params = [
                'partner_id' => $this->partnerId,
                'timestamp' => $timestamp,
                'sign' => $this->generateSign($path, $timestamp, $accessToken, $shopId),
                'access_token' => $accessToken,
                'shop_id' => $shopId,
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 100,
            ];

            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $response = $this->httpClient()->get("{$this->baseUrl}{$path}", $params);
            $result = $response->json() ?? [];

            if (!empty($result['error'])) {
                return $result;
            }

            $pageOrders = $result['response']['order_list'] ?? [];
            foreach ($pageOrders as $order) {
                if (!empty($order['order_sn'])) {
                    $allOrders[$order['order_sn']] = $order;
                }
            }

            $hasMore = (bool) ($result['response']['more'] ?? false);
            $nextCursor = (string) ($result['response']['next_cursor'] ?? '');

            if ($hasMore && ($nextCursor === '' || $nextCursor === $cursor)) {
                Log::warning('Shopee order pagination stopped because cursor did not advance', [
                    'shop_id' => $shopId,
                    'page' => $page,
                ]);
                break;
            }

            $cursor = $nextCursor;
        } while ($hasMore && $page < 100);

        data_set($result, 'response.order_list', array_values($allOrders));
        data_set($result, 'response.more', false);

        Log::info('Shopee - Order List fetched', [
            'shop_id' => $shopId,
            'pages' => $page,
            'orders' => count($allOrders),
        ]);

        return $result;
    }

    // AMBIL DATA LIST RETURN
    public function getReturnList(string $accessToken, string $shopId, int $pageNo, int $pageSize = 100)
    {
        $timestamp = time();
        $path = '/api/v2/returns/get_return_list';
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}"
            . "&page_no={$pageNo}"
            . "&page_size={$pageSize}";

        $response = $this->httpClient()->get($url);
        return $response->json();
    }

    // AMBIL DATA DETAIL RETURN
    public function getReturnDetail(string $accessToken, string $shopId, string $returnSn)
    {
        $timestamp = time();
        $path = '/api/v2/returns/get_return_detail';
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}"
            . "&return_sn={$returnSn}";

        $response = $this->httpClient()->get($url);
        return $response->json();
    }

    // AMBIL DATA DETAIL ORDER
    public function getOrderDetails($store, $orderSnList)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $this->ensureValidToken($store);

        $path = '/api/v2/order/get_order_detail';
        $timestamp = time();

        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = $this->httpClient()->get("{$this->baseUrl}{$path}", [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
            'order_sn_list' => implode(',', $orderSnList),
            'response_optional_fields' => implode(',', self::ORDER_DETAIL_OPTIONAL_FIELDS),
        ]);

        $result = $response->json() ?? [];

        if (!empty($result['error'])) {
            Log::warning('Shopee order detail API returned an error', [
                'shop_id' => $shop_id,
                'order_count' => count($orderSnList),
                'error' => $result['error'],
                'message' => $result['message'] ?? null,
                'request_id' => $result['request_id'] ?? null,
            ]);
        }

        return $result;
    }

    // AMBIL DATA UANG SETELAH DIPOTONG
    public function getEscrowDetail($store, $orderSnList)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $this->ensureValidToken($store);

        $path = '/api/v2/payment/get_escrow_detail';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = $this->httpClient()->get("{$this->baseUrl}/api/v2/payment/get_escrow_detail", [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
            'order_sn' => $orderSnList,
        ]);
        Log::info('Escrow Response', ['body' => $response->body()]);
        return $response->json();
    }

    // AMBIL DATA PRODUCT
    public function getItemList($store)
    {
        $shopId = $store->shopee_shop_id;
        $accessToken = $this->ensureValidToken($store);

        $timestamp = time();
        $path = '/api/v2/product/get_item_list';
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        // Build URL lengkap dengan query string
        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}"
            . "&offset=0"
            . "&page_size=100"
            . "&item_status=NORMAL"
            . "&item_status=BANNED"
            . "&item_status=UNLIST"
            . "&item_status=REVIEWING"
            . "&item_status=SELLER_DELETE"
            . "&item_status=SHOPEE_DELETE";

        // Logging detail untuk debugging
        Log::info('Shopee - Fetching Item List', ['url' => $url]);
        Log::info('Shopee - Params', [
            'shop_id' => $shopId,
            'timestamp' => $timestamp,
        ]);

        $response = $this->httpClient()->get($url);
        $result = $response->json();

        Log::info('Item List Response Summary', [
            'status' => $response->status(),
            'count' => count($result['response']['item'] ?? [])
        ]);

        return $result['response']['item'] ?? [];
    }

    public function getItemBaseInfo($store, array $itemIds)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $this->ensureValidToken($store);

        $path = '/api/v2/product/get_item_base_info';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = $this->httpClient()->get("{$this->baseUrl}/api/v2/product/get_item_base_info", [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
            'item_id_list' => implode(',', $itemIds),
        ]);

        $result = $response->json();
        Log::info('Item Base Info Summary', [
            'status' => $response->status(),
            'count' => count($result['response']['item_list'] ?? [])
        ]);

        return $result['response']['item_list'] ?? [];
    }

    public function getItemsVariant($store, array $itemIds)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $this->ensureValidToken($store);

        $path = '/api/v2/product/get_model_list';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $results = [];
        foreach ($itemIds as $itemId) {
            $response = $this->httpClient()->get("{$this->baseUrl}{$path}", [
                'partner_id' => $this->partnerId,
                'timestamp' => $timestamp,
                'sign' => $sign,
                'shop_id' => $shop_id,
                'access_token' => $access_token,
                'item_id' => $itemId,
            ]);

            $json = $response->json();

            if (!empty($json['error'])) {
                Log::error("Error fetch variant dari Shopee", [
                    'item_id' => $itemId,
                    'error'   => $json['error'],
                    'message' => $json['message'] ?? null
                ]);
                continue;
            }

            $response = $json['response'] ?? [];
            $tierVariation = $response['tier_variation'] ?? [];

            if (empty($response['model'])) {
                Log::info("Item {$itemId} tidak punya variant (single SKU).");
            } else {
                Log::info("Item {$itemId} berhasil ambil " . count($response['model']) . " variant.");
            }

            // 🔑 Inject item_id, variant_name, variant_options ke setiap model
            if (!empty($response['model'])) {
                foreach ($response['model'] as &$model) {
                    $model['item_id'] = $itemId;
                    $tierIndex = $model['tier_index'] ?? [];
                    
                    // Fallback jika tidak punya variant / empty tier_index
                    $defaultName = !empty($model['model_sku']) ? $model['model_sku'] : "Model " . $model['model_id'];
                    
                    $model['variant_name'] = $this->buildVariantName($tierVariation, $tierIndex, $defaultName);
                    $model['variant_options'] = $this->buildVariantOptions($tierVariation, $tierIndex);
                    $model['variant_image'] = $this->extractVariantImage($tierVariation, $tierIndex);
                }
            }

            $results[$itemId] = $response;
        }

        return $results;
    }

    /**
     * Membangun string variant_name (misal: "Blue - 40") berdasarkan tier_index dan tier_variation.
     */
    private function buildVariantName(array $tierVariation, array $tierIndex, string $defaultName = '')
    {
        if (empty($tierVariation) || empty($tierIndex)) {
            return $defaultName;
        }

        $names = [];
        foreach ($tierIndex as $level => $index) {
            if (isset($tierVariation[$level]['option_list'][$index]['option'])) {
                $names[] = $tierVariation[$level]['option_list'][$index]['option'];
            }
        }

        return !empty($names) ? implode(' - ', $names) : $defaultName;
    }

    /**
     * Membangun array variant_options untuk masing-masing tier.
     */
    private function buildVariantOptions(array $tierVariation, array $tierIndex)
    {
        $options = [];
        if (empty($tierVariation) || empty($tierIndex)) {
            return $options;
        }

        foreach ($tierIndex as $level => $index) {
            if (isset($tierVariation[$level]['name']) && isset($tierVariation[$level]['option_list'][$index]['option'])) {
                $options[] = [
                    'name' => $tierVariation[$level]['name'],
                    'value' => $tierVariation[$level]['option_list'][$index]['option']
                ];
            }
        }

        return $options;
    }

    /**
     * Mengekstrak URL gambar varian (misal dari tier warna).
     */
    private function extractVariantImage(array $tierVariation, array $tierIndex)
    {
        if (empty($tierVariation) || empty($tierIndex)) {
            return null;
        }

        // Biasanya gambar hanya ada di tier pertama (level 0)
        $level = 0;
        if (isset($tierIndex[$level])) {
            $index = $tierIndex[$level];
            if (!empty($tierVariation[$level]['option_list'][$index]['image']['image_url'])) {
                return $tierVariation[$level]['option_list'][$index]['image']['image_url'];
            }
        }

        return null;
    }

    // AMBIL DATA TRACKING INFO
    public function getTrackingInfo(Store $store, string $orderSn, string $packageNumber = '')
    {
        $shopId = $store->shopee_shop_id;
        $accessToken = $this->ensureValidToken($store);
        $timestamp = time();
        $path = '/api/v2/logistics/get_tracking_info';
        
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}"
            . "&order_sn={$orderSn}";

        if (!empty($packageNumber)) {
            $url .= "&package_number={$packageNumber}";
        }

        $response = $this->httpClient()->get($url);
        return $response->json();
    }

    // UPDATE STOCK
    public function updateStock(Store $store, string $itemId, string $modelId = '', int $stock)
    {
        $shopId = $store->shopee_shop_id;
        $accessToken = $this->ensureValidToken($store);

        $path = '/api/v2/product/update_stock';
        $timestamp = time();
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        $url = "{$this->baseUrl}{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}";

        $stockList = [
            [
                'normal_stock' => $stock
            ]
        ];

        if (!empty($modelId) && $modelId !== '0') {
            $stockList[0]['model_id'] = (int)$modelId;
        }

        $body = [
            'item_id' => (int)$itemId,
            'stock_list' => $stockList
        ];

        $response = $this->httpClient()->withHeaders([
            'Content-Type' => 'application/json'
        ])->post($url, $body);
        
        $result = $response->json();

        Log::info('Shopee - Update Stock', [
            'item_id' => $itemId,
            'model_id' => $modelId,
            'stock' => $stock,
            'response' => $result
        ]);

        return $result;
    }
}
