<?php

namespace App\Jobs;

use App\Events\OrderStockSyncRequested;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderProduct;
use App\Services\LogisticsStatusNormalizer;
use App\Services\OrderCancellationMapper;
use App\Services\TiktokEscrowAmountResolver;
use App\Services\TiktokService;
use App\Services\TiktokStoreResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HandleTiktokOrderWebhookJob implements ShouldBeUnique, ShouldQueue
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
        return $this->shopId.':'.$this->orderId;
    }

    public function handle(
        TiktokService $tiktok,
        TiktokEscrowAmountResolver $escrowResolver,
        TiktokStoreResolver $storeResolver
    ) {
        Log::info("HandleTiktokOrderWebhookJob started for Order: {$this->orderId}");

        $store = $storeResolver->resolve($this->shopId, $this->orderId);

        if (! $store) {
            Log::warning("TikTok Store not found for shop_id: {$this->shopId}");

            return;
        }

        try {
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

            $orderPackage = OrderPackage::firstOrCreate(
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
            if ($needsEscrowRefresh) {
                if ($escrowResolver->shouldTryStatement($incomingStatus)) {
                    SyncTiktokEscrowJob::dispatch(
                        $store->id,
                        $order['id'],
                        $incomingStatus ?? '',
                        $fallbackSalePrice
                    )->onQueue('orders-low');
                } else {
                    SyncTiktokUnsettledJob::dispatch($store->id)
                        ->onQueue('orders-low');
                }
            }

            if (! empty($order['line_items'])) {
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

            Log::info("HandleTiktokOrderWebhookJob completed for Order: {$this->orderId}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleTiktokOrderWebhookJob for {$this->orderId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
