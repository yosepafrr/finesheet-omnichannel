<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Store;
use App\Events\OrderStockSyncRequested;
use Illuminate\Bus\Queueable;
use App\Services\ShopeeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncShopeeOrderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 120;
    public $maxExceptions = 3;
    public $timeout = 300;
    public $uniqueFor = 1800;
    public $storeId;
    public $daysToSync;
    public $showProgress;
    public $syncContext;
    public $timeFrom;
    public $timeTo;

    public function __construct(
        $storeId = null,
        $daysToSync = 14,
        $showProgress = false,
        $syncContext = 'manual',
        $timeFrom = null,
        $timeTo = null
    )
    {
        $this->storeId = $storeId;
        $this->daysToSync = $daysToSync;
        $this->showProgress = $showProgress;
        $this->syncContext = $syncContext;
        $this->timeFrom = $timeFrom;
        $this->timeTo = $timeTo;
    }

    public function uniqueId(): string
    {
        if ($this->timeFrom !== null && $this->timeTo !== null) {
            return ($this->storeId ?? 'all').":range:{$this->timeFrom}:{$this->timeTo}";
        }

        return ($this->storeId ?? 'all') . ':' . $this->daysToSync;
    }

    public function middleware(): array
    {
        if (!$this->storeId) {
            return [];
        }

        return [
            (new WithoutOverlapping("order-sync:{$this->storeId}"))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(330),
        ];
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
        $now = $this->timeTo !== null
            ? Carbon::createFromTimestamp($this->timeTo, 'UTC')
            : Carbon::now('UTC');
        $intervalDays = 15;

        foreach ($stores as $store) {
            try {
                $accessToken = $shopee->ensureValidToken($store);

                $cursorDate = $this->timeFrom !== null
                    ? Carbon::createFromTimestamp($this->timeFrom, 'UTC')
                    : $now->copy()->subDays($this->daysToSync)->startOfDay();

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

                    if (!empty($orders['error'])) {
                        throw new \RuntimeException(
                            'Shopee order list API failed: '.$orders['error'].' - '
                            .($orders['message'] ?? 'unknown error')
                        );
                    }
                    
                    // Pindahkan kursor waktu ke depan untuk iterasi selanjutnya
                    $cursorDate->addDays($intervalDays);

                    if (empty($orders['response']['order_list'])) {
                        Log::info("No orders found for store {$store->id} in range {$startTime->toDateString()} to {$endTime->toDateString()}");
                        continue;
                    }

                    $orderSnMap = [];
                    foreach ($orders['response']['order_list'] as $listItem) {
                        $orderSnMap[$listItem['order_sn']] = $listItem['order_status'] ?? null;
                    }

                    $orderSnList = array_column($orders['response']['order_list'], 'order_sn');
                    $chunks = array_chunk($orderSnList, 50);
                    $escrowOrderSns = [];

                    foreach ($chunks as $chunk) {
                        // Refresh store model to get latest token
                        $store->refresh();
                        
                        $detailsResponse = $shopee->getOrderDetails($store, $chunk);

                        if (empty($detailsResponse['response']['order_list'])) {
                            $apiError = $detailsResponse['error'] ?? null;
                            Log::warning("No order details for store {$store->id}, saving with minimal data", [
                                'order_sns' => $chunk,
                                'api_error' => $apiError ?? 'unknown',
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
                                            'order_status' => $orderSnMap[$orderSn] ?? null,
                                        ]
                                    );
                                    
                                    // Buat package dummy agar job logistik tetap bisa mengecek status resi
                                    \App\Models\OrderPackage::firstOrCreate(
                                        [
                                            'order_id' => $orderModel->id,
                                            'package_id' => $orderModel->order_sn
                                        ],
                                        [
                                            'platform' => 'Shopee'
                                        ]
                                    );

                                    // OrderCreated notification moved to Order::saved model event
                                } catch (\Throwable $e) {
                                    Log::error("Error saving minimal order {$orderSn}", ['message' => $e->getMessage()]);
                                }
                            }

                            if (!empty($apiError)) {
                                throw new \RuntimeException(
                                    "Shopee order detail API failed: {$apiError} - "
                                    . ($detailsResponse['message'] ?? 'unknown error')
                                );
                            }

                            continue;
                        }

                        foreach ($detailsResponse['response']['order_list'] as $detail) {
                            try {
                                $cancelSource = $detail['cancel_by'] ?? null;
                                $cancelReason = $detail['cancel_reason'] ?? null;
                                $buyerCancelReason = $detail['buyer_cancel_reason'] ?? null;
                                $normalizedCancelCategory = null;
                                if (in_array($detail['order_status'] ?? '', ['CANCEL', 'CANCELLED', 'IN_CANCEL']) || !empty($cancelSource) || !empty($cancelReason)) {
                                    $normalizedCancelCategory = \App\Services\OrderCancellationMapper::normalize('Shopee', $cancelSource, $cancelReason, $buyerCancelReason);
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
                                        'quantity_purchased' => $detail['item_list'][0]['model_quantity_purchased'] ?? null,
                                        'product_id' => $detail['item_list'][0]['item_id'] ?? null,
                                        'raw_data' => $detail,
                                    ]
                                );

                                // Extract packages
                                if (!empty($detail['package_list'])) {
                                    $realPackageNumbers = [];
                                    foreach ($detail['package_list'] as $pkg) {
                                        $packageNumber = $pkg['package_number'] ?? $orderModel->order_sn;
                                        $logisticsStatus = $pkg['logistics_status'] ?? null;
                                        $normalizedLogisticsStatus = match ($logisticsStatus) {
                                            'LOGISTICS_DELIVERY_FAILED' => 'DELIVERY_FAILED',
                                            'LOGISTICS_DELIVERED', 'LOGISTICS_DELIVERY_DONE' => 'DELIVERED',
                                            default => null,
                                        };

                                        if ($packageNumber !== $orderModel->order_sn) {
                                            $realPackageNumbers[] = $packageNumber;
                                        }

                                        \App\Models\OrderPackage::updateOrCreate(
                                            [
                                                'order_id' => $orderModel->id,
                                                'package_id' => $packageNumber
                                            ],
                                            [
                                                'platform' => 'Shopee',
                                                'tracking_number' => $pkg['tracking_number'] ?? null,
                                                'logistics_status' => $logisticsStatus,
                                                'normalized_logistics_status' => $normalizedLogisticsStatus,
                                                'raw_data' => $pkg,
                                            ]
                                        );
                                    }

                                    if (!empty($realPackageNumbers)) {
                                        \App\Models\OrderPackage::where('order_id', $orderModel->id)
                                            ->where('package_id', $orderModel->order_sn)
                                            ->delete();
                                    }
                                } else {
                                    // Default single package if no package_list
                                    \App\Models\OrderPackage::firstOrCreate(
                                        [
                                            'order_id' => $orderModel->id,
                                            'package_id' => $orderModel->order_sn
                                        ],
                                        [
                                            'platform' => 'Shopee'
                                        ]
                                    );
                                }

                                if (!empty($detail['item_list'])) {
                                    foreach ($detail['item_list'] as $shopeeItem) {
                                        $price = $shopeeItem['model_discounted_price'] ?? $shopeeItem['model_original_price'] ?? 0;
                                        $imageUrl = $shopeeItem['image_info']['image_url'] ?? null;
                                        $modelName = !empty($shopeeItem['model_name'])
                                            ? $shopeeItem['model_name']
                                            : 'without variant';
                                        
                                        \App\Models\OrderProduct::updateOrCreate(
                                            [
                                                'order_id' => $orderModel->id,
                                                'product_id' => $shopeeItem['item_id'],
                                                'model_name' => $modelName,
                                            ],
                                            [
                                                'product_name' => $shopeeItem['item_name'] ?? null,
                                                'quantity_purchased' => $shopeeItem['model_quantity_purchased'] ?? 0,
                                                'price' => $price,
                                                'image' => $imageUrl,
                                            ]
                                        );
                                    }
                                }

                                event(new OrderStockSyncRequested($orderModel));

                                $escrowOrderSns[$detail['order_sn']] = true;

                                // OrderCreated notification moved to Order::saved model event
                            } catch (\Throwable $inner) {
                                Log::error("Error saving order_sn {$detail['order_sn']}", [
                                    'message' => $inner->getMessage(),
                                    'store_id' => $store->id
                                ]);
                                continue;
                            }
                        }
                    }

                    // Payment calls are intentionally last so a slow endpoint cannot
                    // leave order details and products half-synchronized.
                    foreach (array_keys($escrowOrderSns) as $orderSn) {
                        try {
                            $orderModel = Order::where('order_sn', $orderSn)->first();
                            if (!$orderModel) {
                                continue;
                            }

                            $escrowResponse = $shopee->getEscrowDetail($store, $orderSn);
                            $escrow = $escrowResponse['response'] ?? [];
                            if (!empty($escrow)) {
                                $orderModel->update([
                                    'order_selling_price' => $escrow['order_income']['order_selling_price'] ?? $orderModel->order_selling_price,
                                    'escrow_amount' => $escrow['order_income']['escrow_amount'] ?? $orderModel->escrow_amount,
                                    'escrow_amount_after_adjustment' => $escrow['order_income']['escrow_amount_after_adjustment'] ?? $orderModel->escrow_amount_after_adjustment,
                                    'fee_details' => $escrow['income_details'] ?? $orderModel->fee_details,
                                ]);
                            }
                        } catch (\Throwable $escrowEx) {
                            Log::warning("Escrow failed for {$orderSn}, order detail was still saved", [
                                'error' => $escrowEx->getMessage()
                            ]);
                        }
                    }
                }

                Log::info("Sync finished for store {$store->id}");

                if ($this->showProgress && $this->timeFrom === null) {
                    SyncStoreLogisticsJob::dispatch(
                        $store->id,
                        true,
                        $this->syncContext
                    )->onQueue('logistics');
                }
            } catch (\Throwable $e) {
                Log::error("Error syncing store {$store->id}", [
                    'message' => $e->getMessage()
                ]);

                if ($this->storeId) {
                    throw $e;
                }

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
