<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Arr;
use App\Models\VariantProduct;
use Illuminate\Http\Request;
use App\Services\ShopeeService;
use App\Services\InitialOrderSyncDispatcher;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class ShopeeController extends Controller
{
    public function redirectToShopee()
    {
        $partnerId = config('shopee.partner_id');
        $partnerKey = config('shopee.partner_key');
        $timestamp = time();
        $path = '/api/v2/shop/auth_partner';




        // HARUS pakai path, bukan redirectUrl di base_string
        $baseString = $partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $partnerKey);

        $redirectUrl = config('shopee.redirect_uri'); // pastikan ini terdaftar di Shopee developer dashboard
        $baseUrl = config('shopee.base_url');
        $url = "{$baseUrl}{$path}"
            . "?partner_id={$partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}"
            . "&redirect=" . urlencode($redirectUrl);

        return redirect($url);
    }

    public function handleShopeeCallback(
        Request $request,
        ShopeeService $shopee,
        InitialOrderSyncDispatcher $initialOrderSync
    )
    {
        $partnerId = config('shopee.partner_id');
        $partnerKey = config('shopee.partner_key');
        $timestamp = time();
        $path = '/api/v2/auth/token/get';

        $code = $request->query('code');
        $shopId = $request->query('shop_id');

        Log::info('Shopee Callback received', [
            'has_code' => !empty($code),
            'shop_id' => $shopId,
            'error' => $request->query('error'),
            'message' => $request->query('message'),
        ]);

        if (empty($code) || empty($shopId)) {
            Log::error('Shopee callback missing required query parameters', [
                'has_code' => !empty($code),
                'shop_id' => $shopId,
                'query' => $request->query(),
            ]);

            return redirect('/#/stores')->with('error', 'Otorisasi Shopee gagal. Code atau shop_id tidak diterima.');
        }

        $baseString = $partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $partnerKey);

        $baseUrl = config('shopee.base_url');
        $url = "{$baseUrl}{$path}"
            . "?partner_id={$partnerId}"
            . "&timestamp={$timestamp}"
            . "&sign={$sign}";

        $body = [
            'code' => $code,
            'shop_id' => (int)$shopId,
        ];

        Log::info('Shopee - Exchanging authorization code', [
            'shop_id' => $shopId,
        ]);

        $http = app()->isLocal()
            ? Http::timeout(30)->connectTimeout(10)->withoutVerifying()
            : Http::timeout(30)->connectTimeout(10);
        $response = $http
            ->withBody(json_encode($body), 'application/json')
            ->post($url);

        $result = $response->json();

        Log::info('Shopee - Token exchange response', [
            'status' => $response->status(),
            'has_access_token' => !empty($result['access_token']),
            'has_refresh_token' => !empty($result['refresh_token']),
            'error' => $result['error'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        // Simpan data token jika berhasil
        $shopData = $result;

        if ($shopData && !empty($shopData['access_token']) && !empty($shopData['refresh_token'])) {
            Log::info('Shopee - Saving Store Data', ['shop_id' => $shopId]);

            $accessToken = $result['access_token'];
            $refreshToken = $result['refresh_token'];
            $tokenExpiredAt = Carbon::createFromTimestamp($result['expire_in'] + $timestamp)
                ->setTimezone('Asia/Jakarta');


            $store = new Store();
            $store->shopee_shop_id = $shopId;
            $store->access_token = $accessToken;
            $store->refresh_token = $refreshToken;
            $store->token_expired_at = $tokenExpiredAt;

            $shopInfo = $shopee->getShopProfile($store);
            Log::info('Shopee - Shop Info received', [
                'shop_id' => $shopId,
                'shop_name' => data_get($shopInfo, 'response.shop_name') ?? data_get($shopInfo, 'shop_name'),
                'error' => $shopInfo['error'] ?? null,
                'message' => $shopInfo['message'] ?? null,
            ]);

            $shopName = data_get($shopInfo, 'response.shop_name')
                ?? data_get($shopInfo, 'shop_name')
                ?? "Toko Shopee {$shopId}";
            $shopExpireTime = data_get($shopInfo, 'response.expire_time')
                ?? data_get($shopInfo, 'expire_time');

            $store =  Auth::user()->stores()->updateOrCreate(
                ['shopee_shop_id' => $shopId],
                [
                    'platform'              => 'Shopee',
                    'store_name'            => $shopName,
                    'shop_expired_at'       => $shopExpireTime
                        ? Carbon::createFromTimestamp($shopExpireTime)
                        : now()->addYear(),
                    'access_token'          => $accessToken,
                    'refresh_token'         => $refreshToken,
                    'token_expired_at'      => $tokenExpiredAt,
                ]
            );

            // Log hasil penyimpanan
            Log::info('Data store berhasil diupdate/ditambahkan', [
                'store_id' => $store->id,
                'data'     => $store->toArray()
            ]);

            \App\Jobs\SyncShopeeProductJob::dispatch($store->id)->onQueue('products');
            $initialOrderSync->dispatch($store);

            return redirect('/#/stores')->with('success', 'Toko Shopee berhasil terhubung.');
        }

        Log::error('Shopee token exchange failed', [
            'shop_id' => $shopId,
            'status' => $response->status(),
            'error' => $result['error'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        return redirect('/#/stores')->with('error', 'Otorisasi Shopee gagal. Token tidak diterima dari Shopee.');
    }

    public function updateProducts(Request $request, ShopeeService $shopee)
    {
        $store = Auth::user()->stores()->where('platform', 'Shopee')->first();
        $accessToken = $shopee->ensureValidToken($store);

        if (!$store) {
            Log::warning('Tidak ada toko Shopee yang terhubung.');
            return response()->json(['error' => 'No Shopee store linked'], 404);
        }

        $itemList = $shopee->getItemList($store);
        $itemIds = collect($itemList['items'] ?? $itemList)->pluck('item_id')->take(20)->toArray();

        if (empty($itemIds)) {
            Log::warning('Item list kosong.');
            return;
        }

        $itemDetails = $shopee->getItemBaseInfo($store, $itemIds);
        $itemVariants = $shopee->getItemsVariant($store, $itemIds);


        if (empty($itemDetails)) {
            Log::warning('Item base info kosong.', ['item_id_list' => $itemIds]);
            return;
        }

        foreach ($itemDetails as $item) {
            try {
                $savedItems = Product::updateOrCreate(
                    [
                        'product_id'  => $item['item_id'],
                        'store_id' => $store->id,
                    ],
                    [
                        'platform'   => 'Shopee',
                        'product_name'  => $item['item_name'] ?? 'Unknown',
                        'image'      => $item['promotion_image']['image_url_list'][0]
                            ?? $item['images'][0]
                            ?? null,
                        'price'      => $item['price_info'][0]['current_price'] ?? $item['price'] ?? 69,
                        'product_sku'   => $item['item_sku'] ?? null,
                        'product_status' => $item['item_status'] ?? null,
                        'stock'      => $item['stock_info_v2']['summary_info']['total_available_stock'] ?? 0,
                        'category'   => $item['category_id'] ?? null,
                    ]
                );
                Log::info("Produk {$item['item_id']} berhasil disimpan");
                Log::info("Data item yang diterima dari API", [
                    'raw' => $item
                ]);
                if (!empty($itemVariants[$item['item_id']]['model'])) {
                    foreach ($itemVariants[$item['item_id']]['model'] as $model) {
                        try {
                            Log::info("Otw simpan variant", [
                                'item_id'  => $item['item_id'],
                                'model_id' => Arr::get($model, 'model_id'),
                            ]);

                            $variantSaved = VariantProduct::updateOrCreate(
                                [
                                    'product_id'  => $savedItems->id, // id dari tabel products
                                    'model_id' => Arr::get($model, 'model_id'),
                                ],
                                [
                                    'model_name' => Arr::get($model, 'model_name'),
                                    'model_sku'  => Arr::get($model, 'model_sku'),
                                    'stock'      => Arr::get($model, 'stock_info_v2.summary_info.total_available_stock', 0),
                                    'price'      => Arr::get($model, 'price_info.0.current_price', 0),
                                    'status'     => Arr::get($model, 'model_status'),
                                    'tier_index' => Arr::get($model, 'tier_index'),
                                    'variant_name' => Arr::get($model, 'variant_name'),
                                    'variant_options' => Arr::get($model, 'variant_options'),
                                    'variant_image' => Arr::get($model, 'variant_image'),
                                ]
                            );

                            Log::info("Variant {$model['model_id']} untuk item {$item['item_id']} berhasil disimpan ke DB", [
                                'db_id' => $variantSaved->id,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error("Gagal simpan variant ke DB", [
                                'item_id'  => $item['item_id'],
                                'model_id' => $model['model_id'] ?? null,
                                'error'    => $e->getMessage(),
                                'trace'    => $e->getTraceAsString(),
                                'data'     => $model
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Gagal simpan produk Shopee', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'data' => $item
                ]);
            }
        }
        // dd("Variant berhasil disimpan: {$model['model_id']} - {$model['model_name']}");

        return redirect('/#/products');
    }

    // AMBIL PESANAN SELAMA 3 BULAN KEBELAKANG
    public function getShopeeOrders(Request $request, ShopeeService $shopee)
    {
        $store = Auth::user()->stores()
            ->where('platform', 'Shopee')
            ->where('id', $request->store_id)
            ->first();


        if (!$store) {
            return response()->json(['error' => 'Shopee store not found.'], 404);
        }

        $accessToken = $shopee->ensureValidToken($store);

        $now = Carbon::now('UTC');
        $threeMonthsAgo = $now->copy()->subMonths(3)->startOfDay(); // 3 bulan lalu
        $intervalDays = 15;

        $allOrders = [];

        while ($threeMonthsAgo < $now) {
            $startTime = $threeMonthsAgo->copy();
            $endTime = $threeMonthsAgo->copy()->addDays($intervalDays);

            if ($endTime > $now) {
                $endTime = $now;
            }

            $orders = $shopee->getOrderList(
                $accessToken,
                (string) $store->shopee_shop_id,
                $startTime->timestamp,
                $endTime->timestamp
            );

            if (isset($orders['response']['order_list'])) {
                $orderSnList = [];

                // Simpan order_sn dan insert awal ke DB
                foreach ($orders['response']['order_list'] as $order) {
                    $orderSnList[] = $order['order_sn'];

                    \App\Models\Order::updateOrCreate(
                        ['order_sn' => $order['order_sn']],
                        [
                            'platform'      => 'Shopee',
                            'booking_sn'    => $order['booking_sn'] ?? null,
                            'store_id'      => $store->id,
                            'item_id'       => 0,
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]
                    );
                }

                // Ambil detail pesanan (maks 50 order_sn sekaligus)
                $chunks = array_chunk($orderSnList, 50);

                foreach ($chunks as $chunk) {
                    $detailsResponse = $shopee->getOrderDetails($store, $chunk);

                    if (isset($detailsResponse['response']['order_list'])) {
                        foreach ($detailsResponse['response']['order_list'] as $detail) {
                            $escrowResponse = $shopee->getEscrowDetail($store, $detail['order_sn']);
                            $escrow = $escrowResponse['response'] ?? [];

                            $itemQuery = Product::query();

                            $itemConditions = [];

                            // Update kembali dengan detail pesanan
                            $orderModel = \App\Models\Order::updateOrCreate(
                                ['order_sn' => $detail['order_sn']],
                                [
                                    'platform'          => 'Shopee',
                                    'order_status'      => $detail['order_status'] ?? null,
                                    'order_time'        => isset($detail['create_time']) ? Carbon::createFromTimestamp($detail['create_time'])->setTimezone(config('app.timezone')) : now(),
                                    'cod'               => $detail['cod'] ?? null,
                                    'ship_by_date'      => isset($detail['ship_by_date']) ? Carbon::createFromTimestamp($detail['ship_by_date'])->setTimezone(config('app.timezone')) : now(),
                                    'message_to_seller' => $detail['message_to_seller'] ?? null,
                                    'updated_at'        => isset($detail['updated_at']) ? Carbon::createFromTimestamp($detail['updated_at'])->setTimezone(config('app.timezone')) : Carbon::now()->timezone('Asia/Jakarta'),

                                    // escrow fields
                                    'order_selling_price' => $escrow['order_income']['order_selling_price'] ?? null,
                                    'escrow_amount' => $escrow['order_income']['escrow_amount'] ?? null,
                                    'escrow_amount_after_adjustment' => $escrow['order_income']['escrow_amount_after_adjustment'] ?? null,

                                ]
                            );

                            if (!empty($detail['item_list'])) {
                                foreach ($detail['item_list'] as $shopeeItem) {
                                    $price = $shopeeItem['model_discounted_price'] ?? $shopeeItem['model_original_price'] ?? 0;
                                    $imageUrl = $shopeeItem['image_info']['image_url'] ?? null;
                                    
                                    \App\Models\OrderProduct::updateOrCreate(
                                        [
                                            'order_id' => $orderModel->id,
                                            'product_id' => $shopeeItem['item_id']
                                        ],
                                        [
                                            'product_name' => $shopeeItem['item_name'] ?? null,
                                            'quantity_purchased' => $shopeeItem['model_quantity_purchased'] ?? 0,
                                            'price' => $price,
                                            'image' => $imageUrl,
                                            'model_name' => $shopeeItem['model_name'] ?: 'without variant',
                                        ]
                                    );
                                }
                            }
                        }
                    }
                }

                $allOrders = array_merge($allOrders, $orders['response']['order_list']);
            }

            $threeMonthsAgo->addDays($intervalDays);
            // sleep(1); // hindari rate limit
        }

        return redirect('/#/profit-tracker');
        // dd($detailsResponse);
    }
}
