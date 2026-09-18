<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\LogisticsStatusNormalizer;

class HandleTiktokOrderWebhookJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $uniqueFor = 300;

    protected $shopId;
    protected $orderId;

    public function __construct($shopId, $orderId)
    {
        $this->shopId = $shopId;
        $this->orderId = $orderId;
    }

    public function uniqueId(): string
    {
        return $this->shopId . ':' . $this->orderId;
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

            $cancelSource = $order['cancellation_initiator'] ?? null;
            $cancelReason = $order['cancel_reason'] ?? null;
            $normalizedCancelCategory = null;
            if (in_array($order['status'] ?? '', ['CANCEL', 'CANCELLED', 'IN_CANCEL'])) {
                if (!empty($cancelSource) || !empty($cancelReason)) {
                    $normalizedCancelCategory = \App\Services\OrderCancellationMapper::normalize('Tiktokshop', $cancelSource, $cancelReason);
                }
            }

            $orderModel = \App\Models\Order::firstOrNew(['order_sn' => $order['id']]);
            $wasNew = !$orderModel->exists;
            $previousStatus = $orderModel->order_status;
            $incomingStatus = $order['status'] ?? null;

            $orderModel->fill([
                'platform' => 'Tiktokshop',
                'store_id' => $store->id,
                'order_status' => $incomingStatus,
                'cancel_source' => $cancelSource,
                'cancel_reason' => $cancelReason,
                'normalized_cancel_category' => $normalizedCancelCategory,
                'order_time' => isset($order['create_time']) ? \Carbon\Carbon::createFromTimestamp($order['create_time'])->setTimezone(config('app.timezone')) : now(),
                'cod' => (isset($order['payment_method_name']) && strtoupper($order['payment_method_name']) === 'CASH ON DELIVERY' || (isset($order['is_cod']) && $order['is_cod'] === true)),
                'message_to_seller' => $order['buyer_message'] ?? null,
                'order_selling_price' => $order['payment']['total_amount'] ?? 0,
                'raw_data' => $order,
            ]);

            if ($wasNew || $orderModel->escrow_amount === null) {
                $orderModel->escrow_amount = $order['payment']['original_total_product_price'] ?? 0;
            }

            $orderModel->save();

            $orderPackage = \App\Models\OrderPackage::firstOrCreate(
                [
                    'order_id' => $orderModel->id,
                    'package_id' => $orderModel->order_sn,
                ],
                ['platform' => 'Tiktokshop']
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
            if ($wasNew || $previousStatus !== $incomingStatus) {
                $grossAmount = $order['payment']['original_total_product_price'] ?? 0;
                \App\Jobs\SyncTiktokEscrowJob::dispatch($store->id, $order['id'], $incomingStatus ?? '', $grossAmount)->onQueue('orders');
            }

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

            // OrderCreated notification moved to Order::saved model event

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
