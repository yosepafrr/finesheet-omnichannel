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

    public function __construct($storeId = null)
    {
        $this->storeId = $storeId;
    }

    public function handle()
    {
        Log::info('SyncShopeeOrderJob started', ['time' => now()]);

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
        $startTime = $now->copy()->subHours(1);

        foreach ($stores as $store) {
            try {
                $accessToken = $shopee->ensureValidToken($store);

                $orders = $shopee->getOrderList(
                    $accessToken,
                    (string) $store->shopee_shop_id,
                    $startTime->timestamp,
                    $now->timestamp
                );

                if (empty($orders['response']['order_list'])) {
                    Log::info("No orders found for store {$store->id}");
                    continue;
                }

                $orderSnList = array_column($orders['response']['order_list'], 'order_sn');
                $chunks = array_chunk($orderSnList, 50);

                foreach ($chunks as $chunk) {
                    $detailsResponse = $shopee->getOrderDetails($store, $chunk);

                    if (empty($detailsResponse['response']['order_list'])) {
                        Log::warning("No order details for store {$store->id}");
                        continue;
                    }

                    foreach ($detailsResponse['response']['order_list'] as $detail) {
                        try {
                            $escrowResponse = $shopee->getEscrowDetail($store, $detail['order_sn']);
                            $escrow = $escrowResponse['response'] ?? [];
                            $firstItem = $escrow['order_income']['items'][0] ?? null;

                            $orderModel = Order::updateOrCreate(
                                ['order_sn' => $detail['order_sn']],
                                [
                                    'store_id' => $store->id,
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
                                    'quantity_purchased' => $firstItem['quantity_purchased'] ?? null,
                                    'product_id' => $firstItem['item_id'] ?? null,
                                ]
                            );

                            if (!empty($escrow['order_income']['items'])) {
                                foreach ($escrow['order_income']['items'] as $escrowItem) {
                                    \App\Models\OrderProduct::updateOrCreate(
                                        [
                                            'order_id' => $orderModel->id,
                                            'product_id' => $escrowItem['item_id']
                                        ],
                                        [
                                            'product_name' => $escrowItem['item_name'] ?? null,
                                            'quantity_purchased' => $escrowItem['quantity_purchased'] ?? 0,
                                            'price' => $escrowItem['selling_price'] ?? 0,

                                            // model fields
                                            'model_name' => $detail['item_list'][0]['model_name'] ?? null,

                                        ]
                                    );
                                }
                            }

                            event(new OrderCreated($orderModel));
                        } catch (\Throwable $inner) {
                            Log::error("Error saving order_sn {$detail['order_sn']}", [
                                'message' => $inner->getMessage(),
                                'store_id' => $store->id
                            ]);
                            continue;
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
