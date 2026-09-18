<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPackage;
use Illuminate\Support\Facades\Log;

class LogisticsSyncService
{
    public function __construct(
        private readonly LogisticsStatusNormalizer $normalizer,
        private readonly ShopeeService $shopee,
        private readonly TiktokService $tiktok
    ) {
    }

    public function prepare(?int $storeId = null, ?string $orderSn = null): void
    {
        if ($orderSn) {
            $this->ensureOrderHasPackage($orderSn);
            return;
        }

        $this->backfillTiktokPackages($storeId);
    }

    public function packageIds(?int $storeId = null, ?string $orderSn = null, bool $force = false): array
    {
        return OrderPackage::query()
            ->when(!$orderSn, function ($query) {
                $query->where(function ($statusQuery) {
                    $statusQuery->whereNotIn('normalized_logistics_status', ['DELIVERED', 'DELIVERY_FAILED'])
                        ->orWhereNull('normalized_logistics_status');
                })->whereHas('order', function ($orderQuery) {
                    $orderQuery->whereIn('order_status', ['SHIPPED', 'IN_TRANSIT']);
                });
            })
            ->when(!$orderSn && !$force, function ($query) {
                $query->where(function ($freshnessQuery) {
                    $freshnessQuery->where('platform', '!=', 'Tiktokshop')
                        ->orWhereNull('raw_data')
                        ->orWhere('updated_at', '<=', now()->subHours(8));
                });
            })
            ->when($storeId, function ($query, $id) {
                $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('store_id', $id));
            })
            ->when($orderSn, function ($query, $sn) {
                $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('order_sn', $sn));
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function syncPackages(array $packageIds): int
    {
        $packages = OrderPackage::whereIn('id', $packageIds)
            ->with('order.store')
            ->get();
        $processedTiktokOrders = [];
        $processed = 0;

        foreach ($packages as $package) {
            try {
                $order = $package->order;
                if (!$order || !$order->store) {
                    continue;
                }

                if ($package->platform === 'Shopee') {
                    $this->syncShopeePackage($package, $order);
                } elseif ($package->platform === 'Tiktokshop') {
                    if (isset($processedTiktokOrders[$order->id])) {
                        continue;
                    }

                    $processedTiktokOrders[$order->id] = true;
                    $this->syncTiktokPackage($package, $order);
                    usleep(200000);
                }

                $processed++;
            } catch (\Throwable $exception) {
                Log::error("Failed to sync logistics for package {$package->package_id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    private function ensureOrderHasPackage(string $orderSn): void
    {
        $order = Order::where('order_sn', $orderSn)->first();
        if (!$order) {
            return;
        }

        $package = OrderPackage::firstOrCreate(
            ['order_id' => $order->id, 'package_id' => $order->order_sn],
            ['platform' => $order->platform]
        );

        if ($this->normalizer->isFailedDelivery([$order->cancel_reason, $order->raw_data])) {
            $package->update([
                'logistics_status' => $order->cancel_reason ?: $package->logistics_status,
                'normalized_logistics_status' => 'DELIVERY_FAILED',
            ]);
        }
    }

    private function backfillTiktokPackages(?int $storeId): void
    {
        Order::where('platform', 'Tiktokshop')
            ->whereIn('order_status', ['SHIPPED', 'IN_TRANSIT'])
            ->whereDoesntHave('packages')
            ->when($storeId, fn ($query, $id) => $query->where('store_id', $id))
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    OrderPackage::firstOrCreate(
                        ['order_id' => $order->id, 'package_id' => $order->order_sn],
                        ['platform' => 'Tiktokshop']
                    );
                }
            });

        Order::where('platform', 'Tiktokshop')
            ->whereIn('order_status', ['CANCEL', 'CANCELLED', 'IN_CANCEL'])
            ->whereDoesntHave('packages', function ($query) {
                $query->where('normalized_logistics_status', 'DELIVERY_FAILED');
            })
            ->where(function ($query) {
                $query->whereRaw('LOWER(cancel_reason) LIKE ?', ['%gagal%'])
                    ->orWhereRaw('LOWER(cancel_reason) LIKE ?', ['%deliver%'])
                    ->orWhereRaw('LOWER(cancel_reason) LIKE ?', ['%return%'])
                    ->orWhereRaw('LOWER(cancel_reason) LIKE ?', ['%dikembalikan%']);
            })
            ->when($storeId, fn ($query, $id) => $query->where('store_id', $id))
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    if (!$this->normalizer->isFailedDelivery([$order->cancel_reason, $order->raw_data])) {
                        continue;
                    }

                    $package = OrderPackage::firstOrCreate(
                        ['order_id' => $order->id, 'package_id' => $order->order_sn],
                        ['platform' => 'Tiktokshop']
                    );

                    $package->update([
                        'logistics_status' => $order->cancel_reason ?: 'Pengiriman paket gagal',
                        'normalized_logistics_status' => 'DELIVERY_FAILED',
                    ]);
                }
            });
    }

    private function syncShopeePackage(OrderPackage $package, Order $order): void
    {
        $packageNumber = $package->package_id !== $order->order_sn ? $package->package_id : '';
        $response = $this->shopee->getTrackingInfo($order->store, $order->order_sn, $packageNumber);

        if (empty($response['response'])) {
            return;
        }

        $status = $response['response']['logistics_status'] ?? null;
        $trackingNumber = $response['response']['tracking_number'] ?? null;
        $normalized = $package->normalized_logistics_status;

        if ($status === 'LOGISTICS_DELIVERY_FAILED') {
            $normalized = 'DELIVERY_FAILED';
        } elseif ($status === 'LOGISTICS_DELIVERED') {
            $normalized = 'DELIVERED';
        } elseif (!empty($status)) {
            $normalized = 'IN_TRANSIT';
        }

        $package->update([
            'tracking_number' => $trackingNumber ?: $package->tracking_number,
            'logistics_status' => $status ?: $package->logistics_status,
            'normalized_logistics_status' => $normalized,
            'raw_data' => $response['response'],
        ]);
    }

    private function syncTiktokPackage(OrderPackage $package, Order $order): void
    {
        $response = $this->tiktok->getTrackingInfo($order->store, $order->order_sn);
        $tracking = $response['data'] ?? [];

        if (empty($tracking) || !is_array($tracking)) {
            Log::warning('TikTok tracking response has no data', [
                'order_sn' => $order->order_sn,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
            ]);
            return;
        }

        $package->update([
            'tracking_number' => $this->normalizer->trackingNumber($tracking) ?? $package->tracking_number,
            'logistics_status' => $this->normalizer->latestDescription($tracking) ?: $package->logistics_status,
            'normalized_logistics_status' => $this->normalizer->normalize($tracking, $package->normalized_logistics_status),
            'raw_data' => $tracking,
        ]);
    }
}
