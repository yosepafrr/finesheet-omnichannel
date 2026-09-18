<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\OrderPackage;
use App\Services\LogisticsStatusNormalizer;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Support\Facades\Log;

class SyncLogisticsCommand extends Command
{
    protected $signature = 'sync:logistics
        {--store_id= : Only sync logistics for one store}
        {--order_sn= : Only sync logistics for one order}
        {--force : Ignore the TikTok eight-hour refresh interval}';
    protected $description = 'Sync logistics and tracking info for active packages';

    public function handle(LogisticsStatusNormalizer $normalizer)
    {
        Log::info('SyncLogisticsCommand started');

        $this->ensureRequestedOrderHasPackage($normalizer);
        $this->backfillTiktokPackages($normalizer);

        // Fetch packages that are not yet completely delivered or failed
        $packages = OrderPackage::query()
            ->when(!$this->option('order_sn'), function ($q) {
                $q->where(function ($statusQuery) {
                    $statusQuery->whereNotIn('normalized_logistics_status', ['DELIVERED', 'DELIVERY_FAILED'])
                        ->orWhereNull('normalized_logistics_status');
                });
            })
            ->when(!$this->option('order_sn') && !$this->option('force'), function ($q) {
                $q->where(function ($freshnessQuery) {
                    $freshnessQuery->where('platform', '!=', 'Tiktokshop')
                        ->orWhereNull('raw_data')
                        ->orWhere('updated_at', '<=', now()->subHours(8));
                });
            })
            ->when($this->option('store_id'), function ($q, $storeId) {
                $q->whereHas('order', function ($orderQuery) use ($storeId) {
                    $orderQuery->where('store_id', $storeId);
                });
            })
            ->when($this->option('order_sn'), function ($q, $orderSn) {
                $q->whereHas('order', function ($orderQuery) use ($orderSn) {
                    $orderQuery->where('order_sn', $orderSn);
                });
            })
            ->with('order.store')
            ->get();

        $this->info("Memproses {$packages->count()} paket.");

        $shopee = new ShopeeService();
        $tiktok = new TiktokService();
        $processedTiktokOrders = [];

        foreach ($packages as $pkg) {
            try {
                $order = $pkg->order;
                if (!$order || !$order->store) continue;

                if ($pkg->platform === 'Shopee') {
                    $this->syncShopeeLogistics($pkg, $order, $shopee);
                } elseif ($pkg->platform === 'Tiktokshop') {
                    if (isset($processedTiktokOrders[$order->id])) {
                        continue;
                    }
                    $processedTiktokOrders[$order->id] = true;
                    $this->syncTiktokLogistics($pkg, $order, $tiktok, $normalizer);
                    usleep(200000);
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync logistics for package {$pkg->package_id}", ['error' => $e->getMessage()]);
            }
        }

        Log::info('SyncLogisticsCommand finished');
        $this->info('Sinkronisasi logistik selesai.');

        return self::SUCCESS;
    }

    private function ensureRequestedOrderHasPackage(LogisticsStatusNormalizer $normalizer): void
    {
        $orderSn = $this->option('order_sn');
        if (!$orderSn) {
            return;
        }

        $order = Order::where('order_sn', $orderSn)->first();
        if (!$order) {
            $this->error("Order {$orderSn} tidak ditemukan.");
            return;
        }

        $package = OrderPackage::firstOrCreate(
            ['order_id' => $order->id, 'package_id' => $order->order_sn],
            ['platform' => $order->platform]
        );

        if ($normalizer->isFailedDelivery([$order->cancel_reason, $order->raw_data])) {
            $package->update([
                'logistics_status' => $order->cancel_reason ?: $package->logistics_status,
                'normalized_logistics_status' => 'DELIVERY_FAILED',
            ]);
        }
    }

    private function backfillTiktokPackages(LogisticsStatusNormalizer $normalizer): void
    {
        if ($this->option('order_sn')) {
            return;
        }

        Order::where('platform', 'Tiktokshop')
            ->whereIn('order_status', ['SHIPPED', 'IN_TRANSIT'])
            ->whereDoesntHave('packages')
            ->when($this->option('store_id'), function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
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
            ->when($this->option('store_id'), function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
            ->chunkById(200, function ($orders) use ($normalizer) {
                foreach ($orders as $order) {
                    if (!$normalizer->isFailedDelivery([$order->cancel_reason, $order->raw_data])) {
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

    private function syncShopeeLogistics($pkg, $order, $shopee)
    {
        // Try getting specific package if it differs from order_sn
        $packageNumber = $pkg->package_id !== $order->order_sn ? $pkg->package_id : '';
        $res = $shopee->getTrackingInfo($order->store, $order->order_sn, $packageNumber);
        
        if (empty($res['response'])) {
            return;
        }

        $status = $res['response']['logistics_status'] ?? null;
        $trackingNumber = $res['response']['tracking_number'] ?? null;
        
        $normalized = $pkg->normalized_logistics_status; // fallback
        if ($status === 'LOGISTICS_DELIVERY_FAILED') {
            $normalized = 'DELIVERY_FAILED';
        } elseif ($status === 'LOGISTICS_DELIVERED') {
            $normalized = 'DELIVERED';
        } elseif (!empty($status)) {
            $normalized = 'IN_TRANSIT';
        }

        $pkg->update([
            'tracking_number' => $trackingNumber ?: $pkg->tracking_number,
            'logistics_status' => $status ?: $pkg->logistics_status,
            'normalized_logistics_status' => $normalized,
            'raw_data' => $res['response'],
        ]);
    }

    private function syncTiktokLogistics($pkg, $order, $tiktok, LogisticsStatusNormalizer $normalizer)
    {
        $res = $tiktok->getTrackingInfo($order->store, $order->order_sn);
        $tracking = $res['data'] ?? [];

        if (empty($tracking) || !is_array($tracking)) {
            Log::warning('TikTok tracking response has no data', [
                'order_sn' => $order->order_sn,
                'code' => $res['code'] ?? null,
                'message' => $res['message'] ?? null,
            ]);
            return;
        }

        $pkg->update([
            'tracking_number' => $normalizer->trackingNumber($tracking) ?? $pkg->tracking_number,
            'logistics_status' => $normalizer->latestDescription($tracking) ?: $pkg->logistics_status,
            'normalized_logistics_status' => $normalizer->normalize($tracking, $pkg->normalized_logistics_status),
            'raw_data' => $tracking,
        ]);
    }
}
