<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleTiktokOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $shopId;
    protected $orderId;

    public function __construct($shopId, $orderId)
    {
        $this->shopId = $shopId;
        $this->orderId = $orderId;
    }

    public function handle()
    {
        Log::info("HandleTiktokOrderWebhookJob started for Order: {$this->orderId}");

        // Di database, tiktok_shop_id disimpan sebagai Cipher (ROW_...) di dalam kolom shopee_shop_id.
        // Webhook TikTok mengirimkan shop_id berupa angka (numeric).
        // Sehingga pencarian strict menggunakan $this->shopId akan gagal.
        // Solusi sementara: Ambil toko TikTok pertama milik user, ATAU cari berdasarkan platform.
        $store = \App\Models\Store::where('platform', 'Tiktokshop')
                      ->where(function($query) {
                          $query->where('shopee_shop_id', $this->shopId)
                                ->orWhere('shopee_shop_id', 'LIKE', 'ROW_%');
                      })
                      ->first();

        if (!$store) {
            Log::warning("TikTok Store not found for shop_id: {$this->shopId}");
            return;
        }

        try {
            $tiktok = new \App\Services\TiktokService();
            $tiktok->ensureValidToken($store);

            $response = $tiktok->getOrderDetail($store, $this->orderId);
            $orders = $response['data']['orders'] ?? [];

            if (empty($orders)) {
                Log::warning("No order details found from TikTok for {$this->orderId}");
                return;
            }

            $order = $orders[0];

            $orderModel = \App\Models\Order::updateOrCreate(
                ['order_sn' => $order['id']],
                [
                    'platform' => 'Tiktokshop',
                    'store_id' => $store->id,
                    'order_status' => $order['status'] ?? null,
                    'order_time' => isset($order['create_time']) ? \Carbon\Carbon::createFromTimestamp($order['create_time']) : now(),
                    'cod' => (isset($order['payment_method_name']) && strtoupper($order['payment_method_name']) === 'CASH ON DELIVERY' || (isset($order['is_cod']) && $order['is_cod'] === true)),
                    'message_to_seller' => $order['buyer_message'] ?? null,
                    'order_selling_price' => $order['payment']['total_amount'] ?? 0,
                    'escrow_amount' => $order['payment']['original_total_product_price'] ?? 0,
                ]
            );

            // Fetch actual/estimated escrow in the background
            $grossAmount = $order['payment']['original_total_product_price'] ?? 0;
            \App\Jobs\SyncTiktokEscrowJob::dispatch($store->id, $order['id'], $order['status'] ?? '', $grossAmount)->onQueue('orders');

            if (!empty($order['line_items'])) {
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
                    \App\Models\OrderProduct::updateOrCreate(
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

            // Fire event HANYA jika ini adalah pesanan baru (bukan update dari worker)
            if ($orderModel->wasRecentlyCreated) {
                event(new \App\Events\OrderCreated($orderModel));
            }

            Log::info("HandleTiktokOrderWebhookJob completed for Order: {$this->orderId}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleTiktokOrderWebhookJob for {$this->orderId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
