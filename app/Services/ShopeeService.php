<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ShopeeService
{
    protected $partnerId;
    protected $partnerKey;

    public function __construct()
    {
        $this->partnerId = config('services.shopee.partner_id');
        $this->partnerKey = config('services.shopee.partner_key');
        Log::info('ShopeeService constructed', [
            'partner_id' => $this->partnerId,
            'partner_key' => $this->partnerKey,
        ]);
    }

    public function ensureValidToken(Store $store)
    {
        if (Carbon::now('Asia/Jakarta')->gte($store->token_expired_at)) {
            $this->refreshAccessToken($store);
        }
        return $store->access_token;
    }

    // FUNGSI AMBIL INFORMASI TOKO
    public function getShopProfile(Store $store)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $store->access_token;

        $path = '/api/v2/shop/get_shop_info';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $url = "https://openplatform.sandbox.test-stable.shopee.sg{$path}";
        $params = [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
        ];

        $response = Http::get($url, $params);
        return $response->json();
    }

    // FUNGSI REFRESH ACCESS TOKEN
    public function refreshAccessToken(Store $store)
    {
        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();

        $shopId = (int) $store->shopee_shop_id;
        $refreshToken = $store->refresh_token;

        $baseString = $this->partnerId . $path . $timestamp;
        $refreshSign = hash_hmac('sha256', $baseString, $this->partnerKey);

        $url = "https://openplatform.sandbox.test-stable.shopee.sg{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$refreshSign}";

        $body = [
            'partner_id' => (int) $this->partnerId,
            'shop_id' => $shopId,
            'refresh_token' => $refreshToken,
        ];

        Log::info('Shopee Access Token Refresh Request', [
            'url' => $url,
            'body' => $body,
            'base_string' => $baseString,
            'sign' => $refreshSign,
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, $body);

        $json = $response->json();

        Log::info('Shopee - Refresh Access Token Response', [
            'response' => $json,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $data = $json['response'] ?? $json;

        if (isset($data['access_token']) && isset($data['refresh_token'])) {
            $store->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expired_at' => Carbon::createFromTimestamp($data['expire_in'] + $timestamp)
                    ->setTimezone('Asia/Jakarta'),
            ]);

            return true;
        }

        Log::error('Shopee - Failed to refresh access token', ['response' => $response->body()]);
        return false;
    }

    protected function generateSign($path, $timestamp, $accessToken, $shopId)
    {
        $baseString = $this->partnerId . $path . $timestamp . $accessToken . $shopId;
        return hash_hmac('sha256', $baseString, $this->partnerKey);
    }

    // AMBIL DATA LIST ORDER
    public function getOrderList(string $accessToken, string $shopId, int $timeFrom, int $timeTo)
    {
        $timestamp = time();
        $path = '/api/v2/order/get_order_list';
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        $url = "https://openplatform.sandbox.test-stable.shopee.sg{$path}"
            . "?partner_id={$this->partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&access_token={$accessToken}"
            . "&shop_id={$shopId}"
            . "&time_range_field=create_time"
            . "&time_from={$timeFrom}"
            . "&time_to={$timeTo}"
            . "&page_size=100";

        Log::info('Shopee - Fetching Order List', ['url' => $url]);
        Log::info('Shopee - Params', [
            'partner_id' => $this->partnerId,
            'shop_id' => $shopId,
            'access_token' => $accessToken,
            'sign' => $sign,
            'url' => $url,
        ]);


        $response = Http::get($url);

        return $response->json();
    }

    // AMBIL DATA DETAIL ORDER
    public function getOrderDetails($store, $orderSnList)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $store->access_token;

        $path = "/api/v2/order/get_order_detail";
        $timestamp = time();

        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = Http::get('https://openplatform.sandbox.test-stable.shopee.sg/api/v2/order/get_order_detail', [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
            'order_sn_list' => implode(',', $orderSnList),
            "response_optional_fields" => "item_list",
        ]);

        return json_decode($response->getBody(), true);
    }

    // AMBIL DATA UANG SETELAH DIPOTONG
    public function getEscrowDetail($store, $orderSnList)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $store->access_token;

        $path = '/api/v2/payment/get_escrow_detail';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = Http::get('https://openplatform.sandbox.test-stable.shopee.sg/api/v2/payment/get_escrow_detail', [
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
        $accessToken = $store->access_token;

        $timestamp = time();
        $path = '/api/v2/product/get_item_list';
        $sign = $this->generateSign($path, $timestamp, $accessToken, $shopId);

        // Build URL lengkap dengan query string
        $url = "https://openplatform.sandbox.test-stable.shopee.sg{$path}"
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
            'partner_id' => $this->partnerId,
            'shop_id' => $shopId,
            'access_token' => $accessToken,
            'sign' => $sign,
            'timestamp' => $timestamp,
        ]);

        $response = Http::get($url);
        $result = $response->json();

        Log::info('Item List Response', $result);

        return $result['response']['item'] ?? [];
    }

    public function getItemBaseInfo($store, array $itemIds)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $store->access_token;

        $path = '/api/v2/product/get_item_base_info';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $response = Http::get('https://openplatform.sandbox.test-stable.shopee.sg/api/v2/product/get_item_base_info', [
            'partner_id' => $this->partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'shop_id' => $shop_id,
            'access_token' => $access_token,
            'item_id_list' => implode(',', $itemIds),
        ]);

        $result = $response->json();
        Log::info('Item Base Info', ['body' => $result]);

        return $result['response']['item_list'] ?? [];
    }

    public function getItemsVariant($store, array $itemIds)
    {
        $shop_id = $store->shopee_shop_id;
        $access_token = $store->access_token;

        $path = '/api/v2/product/get_model_list';
        $timestamp = time();
        $base_string = $this->partnerId . $path . $timestamp . $access_token . $shop_id;
        $sign = hash_hmac('sha256', $base_string, $this->partnerKey);

        $results = [];
        foreach ($itemIds as $itemId) {
            $response = Http::get("https://openplatform.sandbox.test-stable.shopee.sg{$path}", [
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

            if (empty($response['model'])) {
                Log::info("Item {$itemId} tidak punya variant (single SKU).");
            } else {
                Log::info("Item {$itemId} berhasil ambil " . count($response['model']) . " variant.");
            }

            // 🔑 Inject item_id ke setiap model
            foreach ($response['model'] as &$model) {
                $model['item_id'] = $itemId;
            }

            $results[$itemId] = $response;
        }

        return $results;
        // return dd($response->json());
    }
}
