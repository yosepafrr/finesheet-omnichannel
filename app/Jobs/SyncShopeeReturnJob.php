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

    public function __construct(Store|int $store, $timeFrom = null, $timeTo = null)
    {
        $this->storeId = $store instanceof Store ? $store->id : $store;
        // Default to last 30 days if not provided
        $this->timeTo = $timeTo ?? time();
        $this->timeFrom = $timeFrom ?? Carbon::now()->subDays(30)->timestamp;
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

            $rangeFrom = max(0, (int) $this->timeFrom);
            $rangeTo = max($rangeFrom, (int) $this->timeTo);

            while ($rangeFrom <= $rangeTo) {
                $windowTo = min($rangeTo, $rangeFrom + self::WINDOW_SECONDS - 1);
                $this->syncWindow(
                    $shopeeService,
                    $payableService,
                    $store,
                    $accessToken,
                    (string) $shopId,
                    $rangeFrom,
                    $windowTo,
                );
                $rangeFrom = $windowTo + 1;
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
                $this->syncReturn(
                    $shopeeService,
                    $payableService,
                    $store,
                    $accessToken,
                    $shopId,
                    $returnItem,
                );
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
        if (! empty($detailResponse['error'])) {
            throw new \RuntimeException(
                'Shopee return detail error: '.$detailResponse['error'].' - '.($detailResponse['message'] ?? 'unknown error')
            );
        }

        $detail = $detailResponse['response'] ?? [];
        $orderSn = $detail['order_sn'] ?? null;
        if (! $orderSn) {
            return;
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

        $platformStatus = $detail['status'] ?? 'UNKNOWN';
        $orderReturn = OrderReturn::firstOrNew([
            'platform' => 'Shopee',
            'external_return_id' => $returnSn,
        ]);
        $orderReturn->fill([
            'order_id' => $order->id,
            'return_status' => $platformStatus,
            'normalized_status' => $this->normalizeStatus($platformStatus),
            'return_type' => $detail['return_type'] ?? null,
            'refund_amount' => $detail['refund_amount'] ?? 0,
            'return_reason' => $detail['reason'] ?? null,
            'text_reason' => $detail['text_reason'] ?? null,
            'tracking_number' => $detail['tracking_number'] ?? null,
            'updated_at_platform' => isset($detail['update_time'])
                ? Carbon::createFromTimestamp($detail['update_time'])->setTimezone(config('app.timezone'))
                : null,
            'raw_data' => $detail,
        ]);
        if (isset($detail['create_time'])) {
            $orderReturn->preserveEarliestPlatformCreatedAt(
                Carbon::createFromTimestamp($detail['create_time'])->setTimezone(config('app.timezone'))
            );
        }
        $orderReturn->save();

        foreach ($detail['item'] ?? [] as $item) {
            OrderReturnItem::updateOrCreate(
                [
                    'order_return_id' => $orderReturn->id,
                    'sku_id' => $item['model_id'] ?? $item['item_id'] ?? null,
                ],
                [
                    'external_line_item_id' => $item['item_id'] ?? null,
                    'product_name' => $item['item_name'] ?? null,
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
