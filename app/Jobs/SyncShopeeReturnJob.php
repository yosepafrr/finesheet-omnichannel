<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Store;
use App\Services\PayableService;
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

    private const WINDOW_SECONDS = 15 * 24 * 60 * 60;

    private const MAX_PAGES_PER_WINDOW = 100;

    public int $timeout = 300;

    public int $tries = 3;

    protected $storeId;

    protected $timeFrom;

    protected $timeTo;

    public ?string $returnSn = null;

    public array $returnData = [];

    public function __construct(
        Store|int $store,
        $timeFrom = null,
        $timeTo = null,
        ?string $returnSn = null,
        array $returnData = [],
    ) {
        $this->storeId = $store instanceof Store ? $store->id : $store;
        // Default to last 30 days if not provided
        $this->timeTo = $timeTo ?? time();
        $this->timeFrom = $timeFrom ?? Carbon::now()->subDays(30)->timestamp;
        $this->returnSn = $returnSn;
        $this->returnData = $returnData;
    }

    public function handle(ShopeeService $shopeeService, PayableService $payableService): void
    {
        try {
            $store = Store::find($this->storeId);
            if (! $store) {
                Log::warning("SyncShopeeReturnJob skipped: Store {$this->storeId} not found");

                return;
            }

            Log::info("Starting SyncShopeeReturnJob for Store ID: {$store->id}");
            $accessToken = $shopeeService->ensureValidToken($store);
            $shopId = $store->shopee_shop_id;

            if ($this->returnSn) {
                $this->syncReturn(
                    $shopeeService,
                    $payableService,
                    $store,
                    $accessToken,
                    (string) $shopId,
                    array_replace($this->returnData, ['return_sn' => $this->returnSn]),
                );

                return;
            }

            $rangeFrom = max(0, (int) $this->timeFrom);
            $rangeTo = max($rangeFrom, (int) $this->timeTo);

            $windowTo = $rangeTo;
            while ($windowTo >= $rangeFrom) {
                $windowFrom = max($rangeFrom, $windowTo - self::WINDOW_SECONDS + 1);
                $this->syncWindow(
                    $shopeeService,
                    $payableService,
                    $store,
                    $accessToken,
                    (string) $shopId,
                    $windowFrom,
                    $windowTo,
                );
                $windowTo = $windowFrom - 1;
            }
        } catch (\Throwable $e) {
            Log::error("SyncShopeeReturnJob failed for Store ID: {$this->storeId} - ".$e->getMessage());

            throw $e;
        }
    }

    private function syncWindow(
        ShopeeService $shopeeService,
        PayableService $payableService,
        Store $store,
        string $accessToken,
        string $shopId,
        int $timeFrom,
        int $timeTo,
    ): void {
        $pageNo = 1;

        do {
            $response = $shopeeService->getReturnList(
                $accessToken,
                $shopId,
                $pageNo,
                100,
                $timeFrom,
                $timeTo,
            );

            if (! empty($response['error'])) {
                throw new \RuntimeException(
                    'Shopee return list error: '.$response['error'].' - '.($response['message'] ?? 'unknown error')
                );
            }

            $returns = $response['response']['return'] ?? [];
            $hasMore = (bool) ($response['response']['more'] ?? false);

            foreach ($returns as $returnItem) {
                try {
                    $this->syncReturn(
                        $shopeeService,
                        $payableService,
                        $store,
                        $accessToken,
                        $shopId,
                        $returnItem,
                    );
                } catch (\Throwable $e) {
                    Log::error('Shopee return item sync failed', [
                        'store_id' => $store->id,
                        'return_sn' => $returnItem['return_sn'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $pageNo++;
        } while ($hasMore && $pageNo <= self::MAX_PAGES_PER_WINDOW);

        if ($hasMore) {
            throw new \RuntimeException("Shopee return pagination exceeded the safety limit for store {$store->id}");
        }
    }

    private function syncReturn(
        ShopeeService $shopeeService,
        PayableService $payableService,
        Store $store,
        string $accessToken,
        string $shopId,
        array $returnItem,
    ): void {
        $returnSn = (string) ($returnItem['return_sn'] ?? '');
        if ($returnSn === '') {
            return;
        }

        $detailResponse = $shopeeService->getReturnDetail($accessToken, $shopId, $returnSn);
        $detailPayload = $detailResponse['response'] ?? [];
        if (! empty($detailResponse['error'])) {
            Log::warning('Shopee return detail unavailable, using return list payload', [
                'store_id' => $store->id,
                'return_sn' => $returnSn,
                'error' => $detailResponse['error'],
                'message' => $detailResponse['message'] ?? null,
            ]);
            $detailPayload = [];
        }

        // Some Shopee responses expose timestamps and item identifiers only in
        // the list payload. Keep both shapes, with detail values taking priority.
        $detail = array_replace($returnItem, $detailPayload);
        $orderSn = $detail['order_sn'] ?? $detail['ordersn'] ?? null;
        if (! $orderSn) {
            throw new \RuntimeException("Shopee return {$returnSn} does not contain order_sn");
        }

        $order = Order::query()
            ->where('store_id', $store->id)
            ->where('platform', 'Shopee')
            ->where('order_sn', $orderSn)
            ->first();
        if (! $order) {
            Log::warning("Order {$orderSn} not found for return {$returnSn}", [
                'store_id' => $store->id,
            ]);

            return;
        }

        $reverseTrackingPayload = $this->getReverseTrackingPayload(
            $shopeeService,
            $accessToken,
            $shopId,
            $returnSn,
            $store->id,
        );
        $orderReturn = OrderReturn::firstOrNew([
            'platform' => 'Shopee',
            'external_return_id' => $returnSn,
        ]);
        $platformStatus = $detail['status']
            ?? $detail['return_status']
            ?? $orderReturn->platform_status
            ?? $orderReturn->return_status
            ?? 'UNKNOWN';
        $existingRawData = is_array($orderReturn->raw_data) ? $orderReturn->raw_data : [];
        $effectiveReverseTracking = $reverseTrackingPayload
            ?: ($existingRawData['reverse_tracking'] ?? []);
        $normalizationDetail = array_replace([
            'refund_amount' => $orderReturn->refund_amount,
        ], $detail);
        $attributes = [
            'order_id' => $order->id,
            'return_status' => $platformStatus,
            'normalized_status' => $this->normalizeStatus(
                $platformStatus,
                $normalizationDetail,
                $effectiveReverseTracking,
            ),
            'return_type' => $detail['return_refund_type']
                ?? $detail['return_type']
                ?? $this->returnSolutionLabel($detail['return_solution'] ?? null)
                ?? $orderReturn->return_type,
            'raw_data' => [
                'list' => $returnItem,
                'detail' => $detailPayload ?: ($existingRawData['detail'] ?? []),
                'reverse_tracking' => $effectiveReverseTracking,
            ],
        ];
        foreach ([
            'refund_amount' => 'refund_amount',
            'reason' => 'return_reason',
            'text_reason' => 'text_reason',
            'tracking_number' => 'tracking_number',
        ] as $sourceField => $modelField) {
            if (array_key_exists($sourceField, $detail)) {
                $attributes[$modelField] = $detail[$sourceField];
            }
        }
        if (isset($detail['update_time'])) {
            $attributes['updated_at_platform'] = Carbon::createFromTimestamp($detail['update_time'])
                ->setTimezone(config('app.timezone'));
        }
        $orderReturn->fill($attributes);
        if (isset($detail['create_time'])) {
            $orderReturn->preserveEarliestPlatformCreatedAt(
                Carbon::createFromTimestamp($detail['create_time'])->setTimezone(config('app.timezone'))
            );
        }
        $orderReturn->save();

        $items = $detail['item'] ?? $detail['item_list'] ?? [];
        foreach ($items as $item) {
            $skuId = $item['model_id']
                ?? $item['variation_sku']
                ?? $item['model_sku']
                ?? $item['item_id']
                ?? $item['item_sku']
                ?? null;
            OrderReturnItem::updateOrCreate(
                [
                    'order_return_id' => $orderReturn->id,
                    'sku_id' => $skuId,
                ],
                [
                    'external_line_item_id' => $item['return_item_id'] ?? $item['item_id'] ?? null,
                    'product_name' => $item['name'] ?? $item['item_name'] ?? null,
                    'quantity' => $item['amount'] ?? 1,
                    'refund_amount' => $item['refund_amount'] ?? null,
                    'raw_data' => $item,
                ]
            );
        }

        $payableService->reconcileOrderLogistics(
            $order->fresh(['orderProducts', 'store', 'returns.items', 'packages'])
        );
    }

    private function getReverseTrackingPayload(
        ShopeeService $shopeeService,
        string $accessToken,
        string $shopId,
        string $returnSn,
        int $storeId,
    ): array {
        try {
            $response = $shopeeService->getReverseTrackingInfo($accessToken, $shopId, $returnSn);
            if (! empty($response['error'])) {
                Log::info('Shopee reverse tracking unavailable', [
                    'store_id' => $storeId,
                    'return_sn' => $returnSn,
                    'error' => $response['error'],
                    'message' => $response['message'] ?? null,
                ]);

                return [];
            }

            return is_array($response['response'] ?? null) ? $response['response'] : [];
        } catch (\Throwable $e) {
            Log::warning('Shopee reverse tracking request failed', [
                'store_id' => $storeId,
                'return_sn' => $returnSn,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function normalizeStatus(string $status, array $detail = [], array $reverseTracking = []): string
    {
        $status = strtoupper(trim($status));
        if ($status === 'CLOSED') {
            return $this->hasCompletedReturnOutcome($detail, $reverseTracking)
                ? 'COMPLETED'
                : 'REJECTED';
        }

        $map = [
            'REQUESTED' => 'PENDING',
            'ACCEPTED' => 'APPROVED',
            'CANCELLED' => 'CANCELLED',
            'JUDGING' => 'DISPUTED',
            'REFUND_PAID' => 'COMPLETED',
            'COMPLETED' => 'COMPLETED',
            'REFUNDING' => 'PROCESSING',
            'PROCESSING' => 'PROCESSING',
            'SELLER_DISPUTE' => 'DISPUTED',
        ];

        return $map[$status] ?? 'UNKNOWN';
    }

    private function hasCompletedReturnOutcome(array $detail, array $reverseTracking): bool
    {
        if ((float) ($detail['refund_amount'] ?? 0) > 0) {
            return true;
        }

        return ! empty($reverseTracking['post_return_logistics_tracking_info'])
            || in_array(strtoupper((string) ($reverseTracking['reverse_logistics_status'] ?? '')), [
                'LOGISTICS_DELIVERY_DONE',
                'DELIVERED',
                'RETURNED',
            ], true)
            || in_array(strtoupper((string) ($reverseTracking['post_return_logistics_status'] ?? '')), [
                'LOGISTICS_DELIVERY_DONE',
                'DELIVERED',
                'RETURNED',
            ], true);
    }

    private function returnSolutionLabel(mixed $solution): ?string
    {
        if ($solution === null || $solution === '') {
            return null;
        }

        return match ((int) $solution) {
            0 => 'RETURN_AND_REFUND',
            1 => 'REFUND_ONLY',
            default => (string) $solution,
        };
    }
}
