<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Store;
use App\Services\TiktokService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTiktokReturnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $storeId;
    protected $timeFrom;
    protected $timeTo;

    public function __construct(Store|int $store, $timeFrom = null, $timeTo = null)
    {
        $this->storeId = $store instanceof Store ? $store->id : $store;
        $this->timeTo = $timeTo ?? time();
        $this->timeFrom = $timeFrom ?? Carbon::now()->subDays(30)->timestamp;
    }

    public function handle(TiktokService $tiktokService)
    {
        try {
            $store = Store::find($this->storeId);
            if (!$store) {
                Log::warning("SyncTiktokReturnJob skipped: Store {$this->storeId} not found");
                return;
            }

            Log::info("Starting SyncTiktokReturnJob for Store ID: {$store->id}");

            $hasMore = true;
            $pageToken = '';

            while ($hasMore) {
                $response = $tiktokService->searchReturns($store, $this->timeFrom, $this->timeTo, $pageToken);

                if (isset($response['code']) && $response['code'] !== 0) {
                    if ((int) $response['code'] === 36009002) {
                        Log::warning('TikTok return sync rate limited, retrying later', [
                            'store_id' => $store->id,
                            'code' => $response['code'],
                            'message' => $response['message'] ?? null,
                        ]);

                        $this->release(300);
                        return;
                    }

                    Log::error("TikTok return list error", ['response' => $response]);
                    break;
                }

                $data = $response['data'] ?? [];
                $returns = $data['return_orders'] ?? $data['returns'] ?? [];
                $pageToken = $data['next_page_token'] ?? '';
                $hasMore = !empty($pageToken);

                foreach ($returns as $returnItem) {
                    try {
                        $returnId = $returnItem['return_id'] ?? $returnItem['reverse_order_id'] ?? null;
                        $orderId = $returnItem['order_id'] ?? null;

                        if (!$returnId || !$orderId) {
                            continue;
                        }

                        $order = Order::where('platform', 'Tiktokshop')->where('order_sn', $orderId)->first();
                        
                        if (!$order) {
                            Log::warning("Order {$orderId} not found for TikTok return {$returnId}");
                            continue;
                        }

                        $platformStatus = $returnItem['return_status'] ?? $returnItem['reverse_status'] ?? 'UNKNOWN';
                        $normalizedStatus = self::normalizeStatus($platformStatus);

                        $refundAmount = 0;
                        if (isset($returnItem['refund_amount']['refund_total'])) {
                            $refundAmount = floatval($returnItem['refund_amount']['refund_total']);
                        } elseif (isset($returnItem['refund_total'])) {
                            $refundAmount = floatval($returnItem['refund_total']);
                        }

                        $orderReturn = OrderReturn::updateOrCreate(
                            [
                                'platform' => 'Tiktokshop',
                                'external_return_id' => $returnId,
                            ],
                            [
                                'order_id' => $order->id,
                                'return_status' => $platformStatus,
                                'platform_status' => $platformStatus,
                                'normalized_status' => $normalizedStatus,
                                'return_type' => $returnItem['return_type'] ?? $returnItem['reverse_type'] ?? null,
                                'refund_amount' => $refundAmount,
                                'return_reason' => $returnItem['return_reason'] ?? $returnItem['reverse_reason'] ?? null,
                                'text_reason' => $returnItem['return_reason_text'] ?? $returnItem['reverse_reason_text'] ?? null,
                                'tracking_number' => $returnItem['tracking_number'] ?? $returnItem['return_tracking_number'] ?? null,
                                'created_at_platform' => isset($returnItem['create_time']) ? Carbon::createFromTimestamp($returnItem['create_time'])->setTimezone(config('app.timezone')) : null,
                                'updated_at_platform' => isset($returnItem['update_time']) ? Carbon::createFromTimestamp($returnItem['update_time'])->setTimezone(config('app.timezone')) : null,
                                'raw_data' => $returnItem,
                            ]
                        );

                        // Sync Items
                        if (isset($returnItem['return_line_items']) && is_array($returnItem['return_line_items'])) {
                            foreach ($returnItem['return_line_items'] as $item) {
                                OrderReturnItem::updateOrCreate(
                                    [
                                        'order_return_id' => $orderReturn->id,
                                        'sku_id' => $item['sku_id'] ?? null,
                                    ],
                                    [
                                        'external_line_item_id' => $item['return_line_item_id'] ?? null,
                                        'product_name' => $item['product_name'] ?? null,
                                        'quantity' => $item['quantity'] ?? 1,
                                        'refund_amount' => isset($item['refund_amount']) ? floatval($item['refund_amount']) : null,
                                        'raw_data' => $item,
                                    ]
                                );
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error("Error processing TikTok return " . ($returnItem['return_id'] ?? 'unknown') . ": " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("SyncTiktokReturnJob failed for Store ID: {$this->storeId} - " . $e->getMessage());
        }
    }

    public static function normalizeStatus($status)
    {
        $map = [
            'RETURN_OR_REFUND_REQUEST_PENDING' => 'PENDING',
            'AWAITING_BUYER_SHIP' => 'WAITING_FOR_BUYER',
            'RETURN_WAITING_FOR_RETURN' => 'WAITING_FOR_BUYER',
            'BUYER_SHIPPED_ITEM' => 'SHIPPED_BACK',
            'RETURN_BUYER_RETURNED' => 'SHIPPED_BACK',
            'REQUEST_SUCCESS' => 'PROCESSING_REFUND',
            'REFUND_PROCESSING' => 'PROCESSING_REFUND',
            'RETURN_OR_REFUND_REQUEST_COMPLETE' => 'REFUND_COMPLETED',
            'COMPLETED' => 'REFUND_COMPLETED',
            'REQUEST_REJECTED' => 'REJECTED',
            'RECEIVE_REJECTED' => 'REJECTED',
            'CLOSED' => 'REJECTED',
            'RETURN_OR_REFUND_CANCEL' => 'CANCELLED',
            'CANCELLED' => 'CANCELLED',
            'ARBITRATING' => 'DISPUTED',
            'DISPUTE' => 'DISPUTED',
            'UNSUPPORTED' => 'REFUND_COMPLETED',
        ];

        return $map[strtoupper($status)] ?? 'UNKNOWN';
    }
}
