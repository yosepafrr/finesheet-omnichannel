<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TiktokService;
use App\Models\Product;
use App\Models\VariantProduct;
use App\Models\Order;
use App\Models\OrderProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TiktokController extends Controller
{
    public function redirectToTiktok()
    {
        $appKey = config('services.tiktok.app_key') ?? env('TIKTOK_APP_KEY');
        // TikTok Shop Authorization URL
        // In actual implementation, state can be a random string or user id.
        $baseUrl = config('services.tiktok.open_url');
        $url = "{$baseUrl}?app_key={$appKey}&state=connect";
        
        return redirect($url);
    }

    public function handleTiktokCallback(Request $request, TiktokService $tiktok)
    {
        $code = $request->query('code');
        
        if (!$code) {
            Log::error('TikTok Callback error: No authorization code received');
            return redirect('/#/stores')->with('error', 'Gagal mendapatkan otorisasi dari TikTok.');
        }

        $tokenData = $tiktok->getAccessToken($code);
        
        if (empty($tokenData['data']['access_token'])) {
            Log::error('TikTok - Token fetch failed', ['response' => $tokenData]);
            return redirect('/#/stores')->with('error', 'Gagal mendapatkan akses token dari TikTok.');
        }
        
        $data = $tokenData['data'];
        $accessToken = $data['access_token'];
        $refreshToken = $data['refresh_token'];
        $tokenExpiredAt = Carbon::createFromTimestamp($data['access_token_expire_in'])->setTimezone('Asia/Jakarta');
        $shopExpiredAt = Carbon::createFromTimestamp($data['refresh_token_expire_in'])->setTimezone('Asia/Jakarta');
        
        $shopInfo = $tiktok->getAuthorizedShop($accessToken);
        $shopList = $shopInfo['data']['shops'] ?? [];
        
        if (empty($shopList)) {
            Log::warning('TikTok - Authorized shop list is empty', ['response' => $shopInfo]);
            return redirect('/#/stores')->with('error', 'Toko TikTok tidak ditemukan (atau tidak ada otorisasi).');
        }
        
        // Take the first authorized shop
        $shopId = $shopList[0]['cipher'] ?? $shopList[0]['id'] ?? 'unknown';
        $shopName = $shopList[0]['name'] ?? 'Toko TikTok';
        
        $store = Auth::user()->stores()->updateOrCreate(
            ['shopee_shop_id' => $shopId], // Reusing shopee_shop_id for tiktok shop_id/cipher to save schema
            [
                'platform' => 'Tiktokshop',
                'store_name' => $shopName,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expired_at' => $tokenExpiredAt,
                'shop_expired_at' => $shopExpiredAt,
            ]
        );
        
        Log::info('TikTok - Store saved', ['store' => $store]);

        // Sync Products (synchronous as requested)
        $this->syncProducts($store, $tiktok);
        
        // Sync Orders synchronously for 180 days (Initial Sync)
        \App\Jobs\SyncTiktokOrderJob::dispatchSync($store->id, 180);
        
        return redirect('/#/stores')->with('success', 'Toko TikTok berhasil terhubung dan sinkronisasi awal selesai.');
    }

    public function syncProducts($store, TiktokService $tiktok)
    {
        try {
            $response = $tiktok->getProductList($store);
            $products = $response['data']['products'] ?? [];

            foreach ($products as $listItem) {
                // Fetch product details for image, name etc.
                $detailResponse = $tiktok->getProductDetail($store, $listItem['id']);
                $item = $detailResponse['data'] ?? $listItem;

                $productName = $item['product_name'] ?? $item['title'] ?? 'Unknown';
                $imageUrl = $item['images'][0]['url_list'][0] ?? $item['main_images'][0]['urls'][0] ?? null;
                $stock = array_sum(array_column($item['skus'] ?? [], 'inventory.0.quantity')) ?? 0;

                $savedItem = Product::updateOrCreate(
                    [
                        'product_id' => $listItem['id'],
                        'store_id' => $store->id,
                    ],
                    [
                        'platform' => 'Tiktokshop',
                        'product_name' => $productName,
                        'image' => $imageUrl,
                        'price' => $item['skus'][0]['price']['tax_exclusive_price'] ?? $item['skus'][0]['price']['sale_price'] ?? 0,
                        'product_sku' => $item['skus'][0]['seller_sku'] ?? null,
                        'product_status' => $item['status'] ?? $listItem['status'] ?? null,
                        'stock' => $stock,
                        'category' => $item['category_id'] ?? $item['category_list'][0]['id'] ?? null,
                    ]
                );

                // Save Skus as variants
                if (!empty($item['skus'])) {
                    // Build tier map to calculate tier_index correctly
                    $tierMap = [];
                    foreach ($item['skus'] as $s) {
                        foreach ($s['sales_attributes'] ?? [] as $i => $attr) {
                            $attrName = $attr['attribute_name'] ?? $i;
                            $valName = $attr['value_name'] ?? '';
                            if (!isset($tierMap[$attrName])) $tierMap[$attrName] = [];
                            if (!in_array($valName, $tierMap[$attrName])) $tierMap[$attrName][] = $valName;
                        }
                    }

                    foreach ($item['skus'] as $sku) {
                        // Extract variant name from sales attributes
                        $variantOptions = [];
                        $tierIndex = [];
                        $variantImage = null;
                        foreach ($sku['sales_attributes'] ?? [] as $i => $attr) {
                            $valName = $attr['value_name'] ?? '';
                            $attrName = $attr['attribute_name'] ?? $i;
                            $variantOptions[] = $valName;
                            $tierIndex[] = array_search($valName, $tierMap[$attrName]);
                            if (!empty($attr['sku_img']['urls'][0])) {
                                $variantImage = $attr['sku_img']['urls'][0];
                            }
                        }
                        $variantName = implode(' - ', array_filter($variantOptions));

                        $variantStock = $sku['inventory'][0]['quantity'] ?? $sku['stock_infos'][0]['available_stock'] ?? 0;
                        $variantPrice = $sku['price']['tax_exclusive_price'] ?? $sku['price']['sale_price'] ?? 0;

                        VariantProduct::updateOrCreate(
                            [
                                'product_id' => $savedItem->id,
                                'model_id' => $sku['id'],
                            ],
                            [
                                'model_name' => $variantName ?: ($sku['seller_sku'] ?: 'Default'),
                                'model_sku' => $sku['seller_sku'] ?? null,
                                'stock' => $variantStock,
                                'price' => $variantPrice,
                                'status' => 'NORMAL',
                                'variant_name' => $variantName,
                                'variant_options' => $variantOptions,
                                'tier_index' => $tierIndex,
                                'variant_image' => $variantImage,
                            ]
                        );
                    }
                }
            }
            Log::info("TikTok - Successfully synced " . count($products) . " products.");
        } catch (\Exception $e) {
            Log::error('TikTok - Sync Products Failed', ['error' => $e->getMessage()]);
        }
    }

    public function syncOrders($store, TiktokService $tiktok, $timeFrom, $timeTo)
    {
        try {
            $hasMore = true;
            $pageToken = '';
            $totalSynced = 0;

            while ($hasMore) {
                $response = $tiktok->getOrderList($store, $timeFrom, $timeTo, $pageToken);
                $orders = $response['data']['orders'] ?? [];
                
                $pageToken = $response['data']['next_page_token'] ?? '';
                $hasMore = !empty($pageToken);

                foreach ($orders as $order) {
                    $cancelSource = $order['cancellation_initiator'] ?? null;
                    $cancelReason = $order['cancel_reason'] ?? null;
                    $normalizedCancelCategory = null;
                    if (in_array($order['status'] ?? '', ['CANCEL', 'CANCELLED', 'IN_CANCEL'])) {
                        if (!empty($cancelSource) || !empty($cancelReason)) {
                            $normalizedCancelCategory = \App\Services\OrderCancellationMapper::normalize('Tiktokshop', $cancelSource, $cancelReason);
                        }
                    }

                    $orderModel = Order::updateOrCreate(
                        ['order_sn' => $order['id']],
                        [
                            'platform' => 'Tiktokshop',
                            'store_id' => $store->id,
                            'order_status' => $order['status'] ?? null,
                            'cancel_source' => $cancelSource,
                            'cancel_reason' => $cancelReason,
                            'normalized_cancel_category' => $normalizedCancelCategory,
                            'order_time' => isset($order['create_time']) ? Carbon::createFromTimestamp($order['create_time'])->setTimezone(config('app.timezone')) : now(),
                            'cod' => (isset($order['payment_method_name']) && strtoupper($order['payment_method_name']) === 'CASH ON DELIVERY' || (isset($order['is_cod']) && $order['is_cod'] === true)),
                            'message_to_seller' => $order['buyer_message'] ?? null,
                            'order_selling_price' => $order['payment']['total_amount'] ?? 0,
                            'escrow_amount' => $order['payment']['original_total_product_price'] ?? 0,
                            'raw_data' => $order,
                        ]
                    );

                    // Insert default package to be picked up by logistics sync
                    // We don't have tracking info here, SyncLogisticsCommand will fetch it
                    \App\Models\OrderPackage::firstOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'package_id' => $orderModel->order_sn
                        ],
                        [
                            'platform' => 'Tiktokshop'
                        ]
                    );

                    // Fetch actual/estimated escrow in the background
                    $grossAmount = $order['payment']['original_total_product_price'] ?? 0;
                    \App\Jobs\SyncTiktokEscrowJob::dispatch($store->id, $order['id'], $order['status'] ?? '', $grossAmount)->onQueue('orders');

                    if (!empty($order['line_items'])) {
                        // TikTok lists multiple same items as separate line_item entries. We should group them by product_id and sku_name to get quantity.
                        $groupedItems = [];
                        foreach ($order['line_items'] as $item) {
                            $key = $item['product_id'] . '_' . ($item['sku_name'] ?? 'without variant');
                            if (!isset($groupedItems[$key])) {
                                $groupedItems[$key] = $item;
                                $groupedItems[$key]['computed_quantity'] = 1;
                            } else {
                                $groupedItems[$key]['computed_quantity'] += 1;
                            }
                        }

                        foreach ($groupedItems as $item) {
                            OrderProduct::updateOrCreate(
                                [
                                    'order_id' => $orderModel->id,
                                    'product_id' => $item['product_id'],
                                    'model_name' => $item['sku_name'] ?? 'without variant',
                                ],
                                [
                                    'product_name' => $item['product_name'] ?? null,
                                    'quantity_purchased' => $item['computed_quantity'],
                                    'price' => $item['sale_price'] ?? 0,
                                    'image' => $item['sku_image'] ?? null,
                                ]
                            );
                        }
                    }
                    $totalSynced++;
                }
            }
            Log::info("TikTok - Successfully synced {$totalSynced} orders.");
        } catch (\Exception $e) {
            Log::error('TikTok - Sync Orders Failed', ['error' => $e->getMessage()]);
        }
    }
}
