<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TiktokService
{
    protected $appKey;
    protected $appSecret;
    protected $baseUrl;

    public function __construct()
    {
        $this->appKey = config('services.tiktok.app_key') ?? env('TIKTOK_APP_KEY');
        $this->appSecret = config('services.tiktok.app_secret') ?? env('TIKTOK_APP_SECRET');
        $this->baseUrl = 'https://open-api.tiktokglobalshop.com'; // Standard base URL for TikTok API

        Log::info('TiktokService constructed', [
            'app_key' => $this->appKey,
        ]);
    }

    /**
     * Get HTTP Client with conditional SSL Verification
     */
    protected function httpClient()
    {
        return app()->isLocal() ? Http::withoutVerifying() : Http::withOptions([]);
    }

    /**
     * Generate TikTok Signature
     */
    protected function generateSign($path, $queries, $body = '')
    {
        // 1. Sort queries alphabetically by key
        ksort($queries);

        // 2. Concatenate key and value
        $queryStr = '';
        foreach ($queries as $key => $value) {
            if ($key === 'sign' || $key === 'access_token') {
                continue; // Exclude these
            }
            $queryStr .= $key . $value;
        }

        // 3. Wrap with app_secret and include path and body
        $stringToSign = $this->appSecret . $path . $queryStr . $body . $this->appSecret;

        // 4. HMAC-SHA256
        return hash_hmac('sha256', $stringToSign, $this->appSecret);
    }

    /**
     * Get Access Token from Auth Code
     */
    public function getAccessToken($code)
    {
        $path = '/api/v2/token/get';
        
        $url = 'https://auth.tiktok-shops.com' . $path;

        $queries = [
            'app_key' => $this->appKey,
            'auth_code' => $code,
            'grant_type' => 'authorized_code',
        ];

        // TikTok doesn't typically require signature for token endpoint, 
        // but we pass app_key and app_secret in queries
        $queries['app_secret'] = $this->appSecret;

        $response = $this->httpClient()->get($url, $queries);
        $result = $response->json();

        Log::info('TikTok - Get Access Token Response', $result);

        return $result;
    }

    /**
     * Refresh Access Token
     */
    public function refreshAccessToken(Store $store)
    {
        $path = '/api/v2/token/refresh';
        
        $queries = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'refresh_token' => $store->refresh_token,
            'grant_type' => 'refresh_token',
        ];

        $response = $this->httpClient()->get('https://auth.tiktok-shops.com' . $path, $queries);
        $result = $response->json();

        Log::info('TikTok - Refresh Token Response', $result);

        $data = $result['data'] ?? [];
        if (!empty($data['access_token'])) {
            $store->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expired_at' => Carbon::createFromTimestamp($data['access_token_expire_in'])->setTimezone('Asia/Jakarta'),
            ]);
            return $data['access_token'];
        }

        return false;
    }

    /**
     * Ensure Token is Valid
     */
    public function ensureValidToken(Store $store)
    {
        if (Carbon::now('Asia/Jakarta')->gte($store->token_expired_at)) {
            $this->refreshAccessToken($store);
        }
        return $store->access_token;
    }

    /**
     * Get Authorized Shops
     */
    public function getAuthorizedShop($accessToken)
    {
        $path = '/authorization/202309/shops';
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
        ];

        $sign = $this->generateSign($path, $queries);
        $queries['sign'] = $sign;

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken])
            ->get($this->baseUrl . $path, $queries);
        return $response->json();
    }

    /**
     * Fetch Product List
     */
    public function getProductList($store)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; // Using this column for tiktok shop_id as well for now

        $path = '/product/202309/products/search';
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
            'page_size' => 100,
        ];

        $bodyParams = [];
        $bodyStr = '{}'; // Empty body for product search

        $sign = $this->generateSign($path, $queries, $bodyStr);
        $queries['sign'] = $sign;
        
        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->withBody($bodyStr, 'application/json')
            ->post($url);
        
        $res = $response->json();
        Log::info('TikTok - Fetching Product List', [
            'status' => $response->status(), 
            'count' => count($res['data']['products'] ?? [])
        ]);

        return $res;
    }

    /**
     * Fetch Product Detail
     */
    public function getProductDetail($store, $productId)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; 

        $path = '/product/202309/products/' . $productId;
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
        ];

        $sign = $this->generateSign($path, $queries);
        $queries['sign'] = $sign;
        
        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->get($url);
        
        $res = $response->json();
        Log::info('TikTok - Fetching Product Detail', [
            'product_id' => $productId, 
            'status' => $response->status()
        ]);

        return $res;
    }

    /**
     * Fetch Specific Order Detail
     */
    public function getOrderDetail($store, $orderId)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; 

        // TikTok Shop OpenAPI v2.0 for fetching specific orders
        $path = '/order/202309/orders';
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
            'ids' => $orderId, // Can be comma separated if multiple
        ];

        $sign = $this->generateSign($path, $queries);
        $queries['sign'] = $sign;

        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->get($url);

        $res = $response->json();
        Log::info('TikTok - Fetching Order Detail', [
            'status' => $response->status(), 
            'order_id' => $orderId
        ]);

        return $res;
    }

    /**
     * Fetch Order List
     */
    public function getOrderList($store, $timeFrom, $timeTo)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; 

        $path = '/order/202309/orders/search';
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
            'page_size' => 100,
        ];

        $bodyParams = [
            'update_time_ge' => $timeFrom,
            'update_time_lt' => $timeTo,
        ];
        $bodyStr = json_encode($bodyParams);

        $sign = $this->generateSign($path, $queries, $bodyStr);
        $queries['sign'] = $sign;

        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->withBody($bodyStr, 'application/json')
            ->post($url);

        $res = $response->json();
        Log::info('TikTok - Fetching Order List', [
            'status' => $response->status(), 
            'count' => count($res['data']['orders'] ?? [])
        ]);

        return $res;
    }

    /**
     * Fetch Unsettled Transaction (Estimate Escrow)
     */
    public function getUnsettledTransaction($store, $orderId)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; 

        $path = '/finance/202507/orders/unsettled';
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
            'order_id' => $orderId,
            'page_size' => 10,
            'sort_field' => 'order_create_time',
            'sort_order' => 'DESC'
        ];

        $sign = $this->generateSign($path, $queries);
        $queries['sign'] = $sign;

        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->get($url);

        $res = $response->json();
        Log::info('TikTok - Fetching Unsettled Transaction', [
            'status' => $response->status(), 
            'order_id' => $orderId
        ]);

        return $res;
    }

    /**
     * Fetch Statement Transaction (Final Escrow)
     */
    public function getStatementTransaction($store, $orderId)
    {
        $accessToken = $this->ensureValidToken($store);
        $shopId = $store->shopee_shop_id; 

        $path = "/finance/202309/orders/{$orderId}/statement_transactions";
        $timestamp = time();

        $queries = [
            'app_key' => $this->appKey,
            'timestamp' => $timestamp,
            'shop_cipher' => $shopId,
        ];

        $sign = $this->generateSign($path, $queries);
        $queries['sign'] = $sign;

        $url = $this->baseUrl . $path . '?' . http_build_query($queries);

        $response = $this->httpClient()->withHeaders(['x-tts-access-token' => $accessToken, 'Content-Type' => 'application/json'])
            ->get($url);

        $res = $response->json();
        Log::info('TikTok - Fetching Statement Transaction', [
            'status' => $response->status(), 
            'order_id' => $orderId
        ]);

        return $res;
    }
}
