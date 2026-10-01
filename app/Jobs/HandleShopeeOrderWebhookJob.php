<?php

namespace App\Jobs;

use App\Events\OrderStockSyncRequested;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\OrderProduct;
use App\Models\Store;
use App\Services\LogisticsStatusNormalizer;
use App\Services\OrderCancellationMapper;
use App\Services\ShopeeEscrowAmountResolver;
use App\Services\ShopeeService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HandleShopeeOrderWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public $backoff = [10, 30, 60];

    public $uniqueFor = 300;

    protected $shopId;

    protected $orderSn;

    public function __construct($shopId, $orderSn)
    {
        $this->shopId = $shopId;
        $this->orderSn = $orderSn;
    }

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->orderSn;
    }

    public function handle(
        ShopeeService $shopee,
        LogisticsStatusNormalizer $logisticsNormalizer,
        ShopeeEscrowAmountResolver $escrowResolver
    ) {
        Log::info("HandleShopeeOrderWebhookJob started for Order: {$this->orderSn}");

        $store = Store::where('platform', 'Shopee')
            ->where('shopee_shop_id', $this->shopId)
            ->first();

        if (! $store) {
            Log::warning("Shopee Store not found for shop_id: {$this->shopId}");

            return;
        }

        try {
            // Memastikan access token valid, jika tidak otomatis di-refresh oleh service
            $shopee->ensureValidToken($store);

            // Ambil detail 1 order
            $detailsResponse = $shopee->getOrderDetails($store, [$this->orderSn]);

            if (empty($detailsResponse['response']['order_list'])) {
                $message = $detailsResponse['message'] ?? 'order detail is not available yet';
                Log::warning("No order details found from Shopee for {$this->orderSn}", [
                    'error' => $detailsResponse['error'] ?? null,
                    'message' => $message,
                ]);

                throw new \RuntimeException("Shopee order detail unavailable: {$message}");
            }

            $detail = $detailsResponse['response']['order_list'][0];
            $cancelSource = $detail['cancel_by'] ?? null;
            $cancelReason = $detail['cancel_reason'] ?? null;
            $buyerCancelReason = $detail['buyer_cancel_reason'] ?? null;
            $normalizedCancelCategory = null;
            if (in_array($detail['order_status'] ?? '', ['CANCEL', 'CANCELLED', 'IN_CANCEL']) || ! empty($cancelSource) || ! empty($cancelReason)) {
                $normalizedCancelCategory = OrderCancellationMapper::normalize(
                    'Shopee',
                    $cancelSource,
                    $cancelReason,
                    $buyerCancelReason
                );
            }

            $orderModel = Order::updateOrCreate(
                ['order_sn' => $detail['order_sn']],
                [
                    'store_id' => $store->id,
                    'platform' => 'Shopee',
                    'booking_sn' => $detail['booking_sn'] ?? null,
                    'order_status' => $detail['order_status'] ?? null,
                    'cancel_source' => $cancelSource,
                    'cancel_reason' => $cancelReason,
                    'buyer_cancel_reason' => $buyerCancelReason,
                    'normalized_cancel_category' => $normalizedCancelCategory,
                    'order_time' => isset($detail['create_time'])
                        ? Carbon::createFromTimestamp($detail['create_time'])->setTimezone(config('app.timezone'))
                        : now(),
                    'cod' => $detail['cod'] ?? null,
                    'ship_by_date' => isset($detail['ship_by_date'])
                        ? Carbon::createFromTimestamp($detail['ship_by_date'])->setTimezone(config('app.timezone'))
                        : null,
                    'message_to_seller' => $detail['message_to_seller'] ?? null,
                    'raw_data' => $detail,
                ]
            );

            if (! empty($detail['package_list'])) {
                $realPackageNumbers = [];
                foreach ($detail['package_list'] as $package) {
                    $packageNumber = $package['package_number'] ?? $orderModel->order_sn;
                    $logisticsStatus = $package['logistics_status'] ?? null;
                    $normalizedLogisticsStatus = $logisticsNormalizer
                        ->normalizeShopeePackage($package);

                    if ($packageNumber !== $orderModel->order_sn) {
                        $realPackageNumbers[] = $packageNumber;
                    }

                    OrderPackage::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'package_id' => $packageNumber,
                        ],
                        [
                            'platform' => 'Shopee',
                            'tracking_number' => $package['tracking_number'] ?? null,
                            'logistics_status' => $logisticsStatus,
                            'normalized_logistics_status' => $normalizedLogisticsStatus,
                            'raw_data' => $package,
                        ]
                    );
                }

                if (! empty($realPackageNumbers)) {
                    OrderPackage::where('order_id', $orderModel->id)
                        ->where('package_id', $orderModel->order_sn)
                        ->delete();
                }
            } else {
                OrderPackage::firstOrCreate(
                    [
                        'order_id' => $orderModel->id,
                        'package_id' => $orderModel->order_sn,
                    ],
                    ['platform' => 'Shopee']
                );
            }

            if (! empty($detail['item_list'])) {
                foreach ($detail['item_list'] as $shopeeItem) {
                    $price = $shopeeItem['model_discounted_price'] ?? $shopeeItem['model_original_price'] ?? 0;
                    $imageUrl = $shopeeItem['image_info']['image_url'] ?? null;
                    $modelName = ! empty($shopeeItem['model_name'])
                        ? $shopeeItem['model_name']
                        : 'without variant';

                    OrderProduct::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'product_id' => $shopeeItem['item_id'],
                            'model_name' => $modelName,
                        ],
                        [
                            'product_name' => $shopeeItem['item_name'] ?? null,
                            'platform_variant_id' => isset($shopeeItem['model_id']) ? (string) $shopeeItem['model_id'] : null,
                            'sku' => $shopeeItem['model_sku'] ?? $shopeeItem['item_sku'] ?? null,
                            'quantity_purchased' => $shopeeItem['model_quantity_purchased'] ?? 0,
                            'price' => $price,
                            'image' => $imageUrl,
                        ]
                    );
                }
            }

            event(new OrderStockSyncRequested($orderModel));

            try {
                $escrowResponse = $shopee->getEscrowDetail($store, $this->orderSn);
                $escrow = $escrowResponse['response'] ?? [];
                $income = $escrow['order_income'] ?? null;
                if (is_array($income) && $income !== []) {
                    $orderModel->update($escrowResolver->updates($orderModel, $income));
                }
            } catch (\Throwable $escrowException) {
                Log::warning("Escrow failed for {$this->orderSn}, webhook order detail was still saved", [
                    'error' => $escrowException->getMessage(),
                ]);
            }

            Log::info("HandleShopeeOrderWebhookJob successfully completed for {$this->orderSn}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleShopeeOrderWebhookJob for {$this->orderSn}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Throw exception to let the queue worker retry
        }
    }
}
