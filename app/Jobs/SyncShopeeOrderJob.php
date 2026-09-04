<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Store;
use App\Events\OrderCreated;
use Illuminate\Bus\Queueable;
use App\Services\ShopeeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncShopeeOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $storeId;
    public $daysToSync;

    public function __construct($storeId = null, $daysToSync = 14)
    {
        $this->storeId = $storeId;
        $this->daysToSync = $daysToSync;
    }

    public function handle()
    {
        Log::info('SyncShopeeOrderJob started', ['time' => now(), 'daysToSync' => $this->daysToSync]);

        $query = Store::where('platform', 'Shopee');
        if ($this->storeId) {
            $query->where('id', $this->storeId);
        }
        $stores = $query->get();
        if ($stores->isEmpty()) {
            Log::info('No Shopee stores found');
            return;
        }

        $shopee = new ShopeeService();
        $now = Carbon::now('UTC');
        $intervalDays = 15;

        foreach ($stores as $store) {
            try {
                $accessToken = $shopee->ensureValidToken($store);

                $cursorDate = $now->copy()->subDays($this->daysToSync)->startOfDay();

                while ($cursorDate < $now) {
                    $startTime = $cursorDate->copy();
                    $endTime = $cursorDate->copy()->addDays($intervalDays);

                    if ($endTime > $now) {
                        $endTime = $now;
                    }

                    $orders = $shopee->getOrderList(
                        $accessToken,
                        (string) $store->shopee_shop_id,
                        $startTime->timestamp,
                        $endTime->timestamp
                    );
                    
                    // Pindahkan kursor waktu ke depan untuk iterasi selanjutnya
                    $cursorDate->addDays($intervalDays);

                    if (empty($orders['response']['order_list'])) {
                        Log::info("No orders found for store {$store->id} in range {$startTime->toDateString()} to {$endTime->toDateString()}");
                        continue;
                    }

                    $orderSnList = array_column($orders['response']['order_list'], 'order_sn');
                    $chunks = array_chunk($orderSnList, 50);

                    foreach ($chunks as $chunk) {
                        // Refresh store model to get latest token
                        $store->refresh();
                        
                        $detailsResponse = $shopee->getOrderDetails($store, $chunk);

                        if (empty($detailsResponse['response']['order_list'])) {
                            Log::warning("No order details for store {$store->id}, saving with minimal data", [
                                'order_sns' => $chunk,
                                'api_error' => $detailsResponse['error'] ?? 'unknown',
                                'api_message' => $detailsResponse['message'] ?? 'unknown',
                            ]);
                            // Save orders with minimal data when details API fails
                            foreach ($chunk as $orderSn) {
                                try {
                                    $orderModel = Order::updateOrCreate(
                                        ['order_sn' => $orderSn],
                                        [
                                            'store_id' => $store->id,
                                            'platform' => 'Shopee',
                                            'order_time' => now(),
                                        ]
                                    );
                                    if ($orderModel->wasRecentlyCreated) {
                                        event(new OrderCreated($orderModel));
                                    }
                                } catch (\Throwable $e) {
                                    Log::error("Error saving minimal order {$orderSn}", ['message' => $e->getMessage()]);
                                }
                            }
                            continue;
                        }

                        foreach ($detailsResponse['response']['order_list'] as $detail) {
                            try {
                                // Try escrow but don't let it block order creation
                                $escrow = [];
                                try {
                                    $escrowResponse = $shopee->getEscrowDetail($store, $detail['order_sn']);
                                    $escrow = $escrowResponse['response'] ?? [];
                                } catch (\Throwable $escrowEx) {
                                    Log::warning("Escrow failed for {$detail['order_sn']}, saving order without escrow data", [
                                        'error' => $escrowEx->getMessage()
                                    ]);
                                }

                                $orderModel = Order::updateOrCreate(
                                    ['order_sn' => $detail['order_sn']],
                                    [
                                        'store_id' => $store->id,
                                        'platform' => 'Shopee',
                                        'booking_sn' => $detail['booking_sn'] ?? null,
                                        'order_status' => $detail['order_status'] ?? null,
                                        'order_time' => isset($detail['create_time'])
                                            ? Carbon::createFromTimestamp($detail['create_time'])
                                            : now(),
                                        'cod' => $detail['cod'] ?? null,
                                        'ship_by_date' => isset($detail['ship_by_date'])
                                            ? Carbon::createFromTimestamp($detail['ship_by_date'])
                                            : null,
                                        'message_to_seller' => $detail['message_to_seller'] ?? null,
                                        'order_selling_price' => $escrow['order_income']['order_selling_price'] ?? null,
                                        'escrow_amount' => $escrow['order_income']['escrow_amount'] ?? null,
                                        'escrow_amount_after_adjustment' => $escrow['order_income']['escrow_amount_after_adjustment'] ?? null,
                                        'fee_details' => $escrow['income_details'] ?? null,
                                        'quantity_purchased' => $detail['item_list'][0]['model_quantity_purchased'] ?? null,
                                        'product_id' => $detail['item_list'][0]['item_id'] ?? null,
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

                                if ($orderModel->wasRecentlyCreated) {
                                    event(new OrderCreated($orderModel));
                                }
                            } catch (\Throwable $inner) {
                                Log::error("Error saving order_sn {$detail['order_sn']}", [
                                    'message' => $inner->getMessage(),
                                    'store_id' => $store->id
                                ]);
                                continue;
                            }
                        }
                    }
                }

                Log::info("Sync finished for store {$store->id}");
            } catch (\Throwable $e) {
                Log::error("Error syncing store {$store->id}", [
                    'message' => $e->getMessage()
                ]);
                // jangan throw biar job tetap dianggap sukses untuk store lain
                continue;
            }
        }

        Log::info('SyncShopeeOrderJob finished', ['time' => now()]);
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("SyncShopeeOrderJob failed permanently", [
            'message' => $exception->getMessage()
        ]);
    }
}
