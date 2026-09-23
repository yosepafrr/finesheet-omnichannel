<?php

namespace App\Http\Controllers;

use App\Events\OrderStockSyncRequested;
use App\Jobs\SyncTiktokEscrowJob;
use App\Jobs\SyncTiktokProductJob;
use App\Jobs\SyncTiktokUnsettledJob;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\VariantProduct;
use App\Services\InitialOrderSyncDispatcher;
use App\Services\LogisticsStatusNormalizer;
use App\Services\OrderCancellationMapper;
use App\Services\TiktokEscrowAmountResolver;
use App\Services\TiktokService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TikTokController extends Controller
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

    public function handleTiktokCallback(
        Request $request,
        TiktokService $tiktok,
        InitialOrderSyncDispatcher $initialOrderSync
    ) {
        $code = $request->query('code');

        if (! $code) {
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
        $platformShopId = $shopList[0]['id'] ?? null;
        $shopName = $shopList[0]['name'] ?? 'Toko TikTok';

        $store = Auth::user()->stores()->updateOrCreate(
            ['shopee_shop_id' => $shopId], // Reusing shopee_shop_id for tiktok shop_id/cipher to save schema
            [
                'platform' => 'Tiktokshop',
                'platform_shop_id' => $platformShopId,
                'store_name' => $shopName,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expired_at' => $tokenExpiredAt,
                'shop_expired_at' => $shopExpiredAt,
            ]
        );

        Log::info('TikTok - Store saved', ['store' => $store]);

        SyncTiktokProductJob::dispatch($store->id)->onQueue('products');
        $initialOrderSync->dispatch($store);

        return redirect('/#/stores')->with('success', 'Toko TikTok berhasil terhubung. Sinkronisasi awal sedang berjalan di latar belakang.');
    }

    public function syncProducts($store, TiktokService $tiktok)
    {
        try {
            $products = [];
            $pageToken = '';
            $seenTokens = [];

            do {
                $response = $tiktok->getProductList($store, $pageToken);
                $products = array_merge($products, $response['data']['products'] ?? []);
                $nextPageToken = (string) ($response['data']['next_page_token'] ?? '');

                if ($nextPageToken === '' || isset($seenTokens[$nextPageToken])) {
                    break;
                }

                $seenTokens[$nextPageToken] = true;
                $pageToken = $nextPageToken;
            } while (true);

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
                if (! empty($item['skus'])) {
                    $syncedSkuIds = [];
                    // Build tier map to calculate tier_index correctly
                    $tierMap = [];
                    foreach ($item['skus'] as $s) {
                        foreach ($s['sales_attributes'] ?? [] as $i => $attr) {
                            $attrName = $attr['attribute_name'] ?? $i;
                            $valName = $attr['value_name'] ?? '';
                            if (! isset($tierMap[$attrName])) {
                                $tierMap[$attrName] = [];
                            }
                            if (! in_array($valName, $tierMap[$attrName])) {
                                $tierMap[$attrName][] = $valName;
                            }
                        }
                    }

                    foreach ($item['skus'] as $sku) {
                        $syncedSkuIds[] = $sku['id'];
                        // Extract variant name from sales attributes
                        $variantOptions = [];
                        $tierIndex = [];
                        $variantImage = null;
                        foreach ($sku['sales_attributes'] ?? [] as $i => $attr) {
                            $valName = $attr['value_name'] ?? '';
                            $attrName = $attr['attribute_name'] ?? $i;
                            $variantOptions[] = $valName;
                            $tierIndex[] = array_search($valName, $tierMap[$attrName]);
                            if (! empty($attr['sku_img']['urls'][0])) {
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

                    $savedItem->variantProducts()
                        ->whereNotIn('model_id', $syncedSkuIds)
                        ->delete();
                } else {
                    $savedItem->variantProducts()->delete();
                }
            }
            Log::info('TikTok - Successfully synced '.count($products).' products.');
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
            $escrowResolver = app(TiktokEscrowAmountResolver::class);
            $shouldSyncUnsettled = false;

            while ($hasMore) {
                $response = $tiktok->getOrderList($store, $timeFrom, $timeTo, $pageToken);
                $orders = $response['data']['orders'] ?? [];

                $pageToken = $response['data']['next_page_token'] ?? '';
                $hasMore = ! empty($pageToken);

                foreach ($orders as $order) {
                    $cancelSource = $order['cancellation_initiator'] ?? null;
                    $cancelReason = $order['cancel_reason'] ?? null;
                    $normalizedCancelCategory = null;
                    if (in_array($order['status'] ?? '', ['CANCEL', 'CANCELLED', 'IN_CANCEL'])) {
                        if (! empty($cancelSource) || ! empty($cancelReason)) {
                            $normalizedCancelCategory = OrderCancellationMapper::normalize('Tiktokshop', $cancelSource, $cancelReason);
                        }
                    }

                    $orderModel = Order::firstOrNew(['order_sn' => $order['id']]);
                    $wasNew = ! $orderModel->exists;
                    $previousStatus = $orderModel->order_status;
                    $incomingStatus = $order['status'] ?? null;
                    $fallbackSalePrice = $escrowResolver->fallbackSalePrice($order);
                    $needsEscrowRefresh = $wasNew
                        || $previousStatus !== $incomingStatus
                        || $escrowResolver->needsRefresh(
                            $orderModel->fee_details,
                            $incomingStatus,
                            $orderModel->escrow_amount
                        );

                    $orderModel->fill([
                        'platform' => 'Tiktokshop',
                        'store_id' => $store->id,
                        'order_status' => $incomingStatus,
                        'cancel_source' => $cancelSource,
                        'cancel_reason' => $cancelReason,
                        'normalized_cancel_category' => $normalizedCancelCategory,
                        'order_time' => isset($order['create_time']) ? Carbon::createFromTimestamp($order['create_time'])->setTimezone(config('app.timezone')) : now(),
                        'cod' => (isset($order['payment_method_name']) && strtoupper($order['payment_method_name']) === 'CASH ON DELIVERY' || (isset($order['is_cod']) && $order['is_cod'] === true)),
                        'message_to_seller' => $order['buyer_message'] ?? null,
                        'order_selling_price' => $order['payment']['total_amount'] ?? 0,
                        'raw_data' => $order,
                    ]);

                    if (empty($orderModel->fee_details)) {
                        $orderModel->escrow_amount = $fallbackSalePrice;
                    }

                    $orderModel->save();

                    // Insert default package to be picked up by logistics sync
                    // We don't have tracking info here, SyncLogisticsCommand will fetch it
                    $orderPackage = OrderPackage::firstOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'package_id' => $orderModel->order_sn,
                        ],
                        [
                            'platform' => 'Tiktokshop',
                        ]
                    );

                    $logisticsNormalizer = app(LogisticsStatusNormalizer::class);
                    if ($logisticsNormalizer->isFailedDelivery($order)) {
                        $orderPackage->update([
                            'logistics_status' => $cancelReason ?: 'Pengiriman paket gagal',
                            'normalized_logistics_status' => 'DELIVERY_FAILED',
                            'raw_data' => $order,
                        ]);
                    }

                    // Fetch actual/estimated escrow in the background
                    if ($needsEscrowRefresh) {
                        if (strtoupper((string) $incomingStatus) === 'COMPLETED') {
                            SyncTiktokEscrowJob::dispatch(
                                $store->id,
                                $order['id'],
                                $incomingStatus ?? '',
                                $fallbackSalePrice
                            )->onQueue('orders-low');
                        } else {
                            $shouldSyncUnsettled = true;
                        }
                    }

                    if (! empty($order['line_items'])) {
                        // TikTok lists multiple same items as separate line_item entries. We should group them by product_id and sku_name to get quantity.
                        $groupedItems = [];
                        foreach ($order['line_items'] as $item) {
                            $variantIdentity = $item['sku_id'] ?? $item['seller_sku'] ?? $item['sku_name'] ?? 'without variant';
                            $key = $item['product_id'].'_'.$variantIdentity;
                            if (! isset($groupedItems[$key])) {
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
                                    'platform_variant_id' => isset($item['sku_id']) ? (string) $item['sku_id'] : null,
                                    'sku' => $item['seller_sku'] ?? null,
                                    'quantity_purchased' => $item['computed_quantity'],
                                    'price' => $item['sale_price'] ?? 0,
                                    'image' => $item['sku_image'] ?? null,
                                ]
                            );
                        }
                    }

                    event(new OrderStockSyncRequested($orderModel));
                    $totalSynced++;
                }
            }

            if ($shouldSyncUnsettled) {
                SyncTiktokUnsettledJob::dispatch($store->id)
                    ->onQueue('orders-low');
            }

            Log::info("TikTok - Successfully synced {$totalSynced} orders.");
        } catch (\Exception $e) {
            Log::error('TikTok - Sync Orders Failed', ['error' => $e->getMessage()]);
        }
    }
}
