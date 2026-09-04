<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Store;
use App\Models\OrderProduct;
use App\Events\OrderCreated;
use Illuminate\Bus\Queueable;
use App\Services\ShopeeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleShopeeOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $shopId;
    protected $orderSn;

    public function __construct($shopId, $orderSn)
    {
        $this->shopId = $shopId;
        $this->orderSn = $orderSn;
    }

    public function handle(ShopeeService $shopee)
    {
        Log::info("HandleShopeeOrderWebhookJob started for Order: {$this->orderSn}");

        $store = Store::where('platform', 'Shopee')
                      ->where('shopee_shop_id', $this->shopId)
                      ->first();

        if (!$store) {
            Log::warning("Shopee Store not found for shop_id: {$this->shopId}");
            return;
        }

        try {
            // Memastikan access token valid, jika tidak otomatis di-refresh oleh service
            $shopee->ensureValidToken($store);

            // Ambil detail 1 order
            $detailsResponse = $shopee->getOrderDetails($store, [$this->orderSn]);

            if (empty($detailsResponse['response']['order_list'])) {
                Log::warning("No order details found from Shopee for {$this->orderSn}");
                return;
            }

            $detail = $detailsResponse['response']['order_list'][0];

            // Ambil detail escrow/income (Opsional, tapi penting untuk harga)
            $escrowResponse = $shopee->getEscrowDetail($store, $this->orderSn);
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
                    OrderProduct::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'product_id' => $escrowItem['item_id']
                        ],
                        [
                            'product_name' => $escrowItem['item_name'] ?? null,
                            'quantity_purchased' => $escrowItem['quantity_purchased'] ?? 0,
                            'price' => $escrowItem['selling_price'] ?? 0,
                            'model_name' => $detail['item_list'][0]['model_name'] ?? null,
                        ]
                    );
                }
            }

            // Fire event HANYA jika pesanan baru
            if ($orderModel->wasRecentlyCreated) {
                event(new OrderCreated($orderModel));
            }

            Log::info("HandleShopeeOrderWebhookJob successfully completed for {$this->orderSn}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleShopeeOrderWebhookJob for {$this->orderSn}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Throw exception to let the queue worker retry
        }
    }
}
