<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Store;
use App\Services\ShopeeService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncShopeeReturnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $store;
    protected $timeFrom;
    protected $timeTo;

    public function __construct(Store $store, $timeFrom = null, $timeTo = null)
    {
        $this->store = $store;
        // Default to last 30 days if not provided
        $this->timeTo = $timeTo ?? time();
        $this->timeFrom = $timeFrom ?? Carbon::now()->subDays(30)->timestamp;
    }

    public function handle(ShopeeService $shopeeService)
    {
        try {
            Log::info("Starting SyncShopeeReturnJob for Store ID: {$this->store->id}");
            $accessToken = $shopeeService->ensureValidToken($this->store);
            $shopId = $this->store->shopee_shop_id;

            $hasMore = true;
            $pageNo = 0;

            while ($hasMore) {
                $response = $shopeeService->getReturnList($accessToken, $shopId, $pageNo, 100);

                if (isset($response['error']) && !empty($response['error'])) {
                    Log::error("Shopee return list error", ['response' => $response]);
                    break;
                }

                $returns = $response['response']['return'] ?? [];
                $hasMore = $response['response']['more'] ?? false;

                foreach ($returns as $returnItem) {
                    try {
                        $returnSn = $returnItem['return_sn'];

                        // Fetch detailed return to get order_sn and items
                        $detailRes = $shopeeService->getReturnDetail($accessToken, $shopId, $returnSn);
                        if (isset($detailRes['error']) && !empty($detailRes['error'])) {
                            Log::error("Shopee return detail error for {$returnSn}", ['response' => $detailRes]);
                            continue;
                        }

                        $detail = $detailRes['response'] ?? [];
                        if (empty($detail)) {
                            continue;
                        }

                        $orderSn = $detail['order_sn'] ?? null;
                        if (!$orderSn) {
                            continue;
                        }

                        $order = Order::where('platform', 'shopee')->where('order_sn', $orderSn)->first();
                        
                        if (!$order) {
                            Log::warning("Order {$orderSn} not found for return {$returnSn}");
                            // Can't link, skip for now. We could still store it without order_id but order_id is required
                            continue;
                        }

                        $platformStatus = $detail['status'] ?? 'UNKNOWN';
                        $normalizedStatus = $this->normalizeStatus($platformStatus);

                        $orderReturn = OrderReturn::updateOrCreate(
                            [
                                'platform' => 'shopee',
                                'external_return_id' => $returnSn,
                            ],
                            [
                                'order_id' => $order->id,
                                'return_status' => $platformStatus,
                                'normalized_status' => $normalizedStatus,
                                'return_type' => $detail['return_type'] ?? null,
                                'refund_amount' => $detail['refund_amount'] ?? 0,
                                'return_reason' => $detail['reason'] ?? null,
                                'text_reason' => $detail['text_reason'] ?? null,
                                'tracking_number' => $detail['tracking_number'] ?? null,
                                'created_at_platform' => isset($detail['create_time']) ? Carbon::createFromTimestamp($detail['create_time'])->setTimezone(config('app.timezone')) : null,
                                'updated_at_platform' => isset($detail['update_time']) ? Carbon::createFromTimestamp($detail['update_time'])->setTimezone(config('app.timezone')) : null,
                                'raw_data' => $detail,
                            ]
                        );

                        // Sync Items
                        if (isset($detail['item']) && is_array($detail['item'])) {
                            foreach ($detail['item'] as $item) {
                                OrderReturnItem::updateOrCreate(
                                    [
                                        'order_return_id' => $orderReturn->id,
                                        'sku_id' => $item['model_id'] ?? $item['item_id'] ?? null,
                                    ],
                                    [
                                        'external_line_item_id' => $item['item_id'] ?? null,
                                        'quantity' => $item['amount'] ?? 1,
                                        'raw_data' => $item,
                                    ]
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error("Error processing Shopee return {$returnItem['return_sn']}: " . $e->getMessage());
                        // continue to next return
                    }
                }

                $pageNo++;
            }
        } catch (\Exception $e) {
            Log::error("SyncShopeeReturnJob failed for Store ID: {$this->store->id} - " . $e->getMessage());
        }
    }

    private function normalizeStatus($status)
    {
        // Example mapping based on Shopee statuses
        // You can adjust these based on actual Shopee returns API documentation
        $map = [
            'REQUESTED' => 'PENDING',
            'ACCEPTED' => 'APPROVED',
            'CANCELLED' => 'CANCELLED',
            'JUDGING' => 'DISPUTED',
            'REFUND_PAID' => 'COMPLETED',
            'CLOSED' => 'REJECTED',
            'PROCESSING' => 'PROCESSING',
        ];

        return $map[strtoupper($status)] ?? 'UNKNOWN';
    }
}
