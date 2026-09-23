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

class ShopeeController extends Controller
{
    public function redirectToShopee()
    {
        $partnerId = config('shopee.partner_id');
        $partnerKey = config('shopee.partner_key');
        $redirectUrl = config('shopee.redirect_uri');
        $baseUrl = rtrim((string) config('shopee.base_url'), '/');

        if (empty($partnerId) || empty($partnerKey) || empty($redirectUrl) || empty($baseUrl)) {
            Log::error('Shopee authorization configuration is incomplete', [
                'has_partner_id' => !empty($partnerId),
                'has_partner_key' => !empty($partnerKey),
                'redirect_uri' => $redirectUrl,
                'base_url' => $baseUrl,
            ]);

            return redirect('/#/stores')->with(
                'error',
                'Konfigurasi otorisasi Shopee belum lengkap. Hubungi administrator.'
            );
        }

        if (app()->isProduction() && preg_match('/sandbox|test-stable/i', $baseUrl)) {
            Log::error('Shopee production authorization points to a sandbox endpoint', [
                'base_url' => $baseUrl,
                'redirect_uri' => $redirectUrl,
                'partner_id' => $partnerId,
            ]);

            return redirect('/#/stores')->with(
                'error',
                'Konfigurasi Shopee production masih menggunakan endpoint sandbox.'
            );
        }

        $timestamp = time();
        $path = '/api/v2/shop/auth_partner';
        $baseString = $partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $baseString, $partnerKey);
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
        $code = $request->query('code');
        $shopId = (int) $request->query('shop_id');

        Log::info('Shopee Callback received', [
            'has_code' => !empty($code),
            'shop_id' => $shopId,
            'error' => $request->query('error'),
            'message' => $request->query('message'),
        ]);

        if (empty($code) || $shopId <= 0) {
            Log::error('Shopee callback missing required query parameters', [
                'has_code' => !empty($code),
                'shop_id' => $shopId,
            ]);

            return redirect('/#/stores')->with('error', 'Otorisasi Shopee gagal. Code atau shop_id tidak diterima.');
        }

        try {
            $result = $shopee->exchangeAuthorizationCode((string) $code, $shopId);
            if (empty($result['access_token']) || empty($result['refresh_token'])) {
                Log::error('Shopee token exchange failed', [
                    'shop_id' => $shopId,
                    'error' => $result['error'] ?? null,
                    'message' => $result['message'] ?? null,
                    'request_id' => $result['request_id'] ?? null,
                ]);

                return redirect('/#/stores')->with(
                    'error',
                    'Otorisasi Shopee gagal: '.($result['message'] ?? 'token tidak diterima dari Shopee.')
                );
            }

            $existingStore = Store::query()
                ->where('platform', 'Shopee')
                ->where('shopee_shop_id', (string) $shopId)
                ->first();

            if ($existingStore && (int) $existingStore->user_id !== (int) Auth::id()) {
                Log::warning('Shopee authorization rejected because shop belongs to another user', [
                    'shop_id' => $shopId,
                    'existing_store_id' => $existingStore->id,
                    'request_user_id' => Auth::id(),
                ]);

                return redirect('/#/stores')->with(
                    'error',
                    'Toko Shopee tersebut sudah terhubung ke akun Finesheet lain.'
                );
            }

            $accessToken = $result['access_token'];
            $refreshToken = $result['refresh_token'];
            $tokenExpiredAt = now()->addSeconds((int) ($result['expire_in'] ?? 14400));

            $temporaryStore = new Store([
                'shopee_shop_id' => (string) $shopId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expired_at' => $tokenExpiredAt,
            ]);

            try {
                $shopInfo = $shopee->getShopProfile($temporaryStore);
            } catch (\Throwable $profileException) {
                $shopInfo = [];
                Log::warning('Shopee shop profile could not be loaded during callback', [
                    'shop_id' => $shopId,
                    'error' => $profileException->getMessage(),
                ]);
            }

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

            $storeData = [
                'platform' => 'Shopee',
                'store_name' => $shopName,
                'shop_expired_at' => $shopExpireTime
                    ? Carbon::createFromTimestamp($shopExpireTime)
                    : ($existingStore?->shop_expired_at ?? now()->addYear()),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expired_at' => $tokenExpiredAt,
            ];

            if ($existingStore) {
                $existingStore->update($storeData);
                $store = $existingStore->fresh();
            } else {
                $store = Auth::user()->stores()->create([
                    'shopee_shop_id' => (string) $shopId,
                    ...$storeData,
                ]);
            }

            Log::info('Shopee store authorization saved', [
                'store_id' => $store->id,
                'shop_id' => $shopId,
                'user_id' => Auth::id(),
            ]);

            \App\Jobs\SyncShopeeProductJob::dispatch($store->id)->onQueue('products');
            $initialOrderSync->dispatch($store);

            return redirect('/#/stores')->with('success', 'Toko Shopee berhasil terhubung.');
        } catch (\Throwable $e) {
            $reference = substr(hash('sha256', $shopId.'|'.microtime(true)), 0, 10);
            Log::error('Shopee callback failed', [
                'reference' => $reference,
                'shop_id' => $shopId,
                'user_id' => Auth::id(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect('/#/stores')->with(
                'error',
                "Otorisasi Shopee gagal diproses. Referensi: {$reference}."
            );
        }
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
                                            'platform_variant_id' => isset($shopeeItem['model_id']) ? (string) $shopeeItem['model_id'] : null,
                                            'sku' => $shopeeItem['model_sku'] ?? $shopeeItem['item_sku'] ?? null,
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
