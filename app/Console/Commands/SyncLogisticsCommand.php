<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrderPackage;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Support\Facades\Log;

class SyncLogisticsCommand extends Command
{
    protected $signature = 'sync:logistics {--store_id= : Only sync logistics for one store}';
    protected $description = 'Sync logistics and tracking info for active packages';

    public function handle()
    {
        Log::info('SyncLogisticsCommand started');

        // Fetch packages that are not yet completely delivered or failed
        $packages = OrderPackage::where(function ($q) {
                $q->whereNotIn('normalized_logistics_status', ['DELIVERED', 'DELIVERY_FAILED'])
                    ->orWhereNull('normalized_logistics_status');
            })
            ->when($this->option('store_id'), function ($q, $storeId) {
                $q->whereHas('order', function ($orderQuery) use ($storeId) {
                    $orderQuery->where('store_id', $storeId);
                });
            })
            ->with('order.store')
            ->get();

        $shopee = new ShopeeService();
        $tiktok = new TiktokService();

        foreach ($packages as $pkg) {
            try {
                $order = $pkg->order;
                if (!$order || !$order->store) continue;

                if ($pkg->platform === 'Shopee') {
                    $this->syncShopeeLogistics($pkg, $order, $shopee);
                } elseif ($pkg->platform === 'Tiktokshop') {
                    $this->syncTiktokLogistics($pkg, $order, $tiktok);
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync logistics for package {$pkg->package_id}", ['error' => $e->getMessage()]);
            }
        }

        Log::info('SyncLogisticsCommand finished');
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

    private function syncTiktokLogistics($pkg, $order, $tiktok)
    {
        $res = $tiktok->getTrackingInfo($order->store, $order->order_sn);
        $trackingList = $res['data']['tracking_info_list']
            ?? $res['data']['packages']
            ?? $res['data']['package_list']
            ?? [];

        if (empty($trackingList) && !empty($res['data'])) {
            $trackingList = [$res['data']];
        }
        
        foreach ($trackingList as $t) {
            $packageId = $t['package_id']
                ?? $t['package_id_str']
                ?? $t['package_number']
                ?? $t['id']
                ?? $order->order_sn;
            
            // Match with the current package or create a new one if multiple
            $targetPkg = $packageId === $pkg->package_id ? $pkg : OrderPackage::firstOrNew([
                'order_id' => $order->id,
                'package_id' => $packageId
            ]);

            $targetPkg->platform = 'Tiktokshop';
            $targetPkg->tracking_number = $t['tracking_number']
                ?? $t['tracking_no']
                ?? $t['shipping_tracking_number']
                ?? $targetPkg->tracking_number;

            $failed = false;
            $delivered = false;
            $latestEventDesc = $this->extractLatestTrackingDescription($t);
            $trackingText = $this->flattenTrackingText($t);
            
            if (!empty($t['tracking_info'])) {
                foreach ($t['tracking_info'] as $event) {
                    $desc = strtolower($event['description'] ?? $event['event'] ?? $event['status'] ?? '');
                    if (str_contains($desc, 'delivered') && !$this->isFailedDeliveryText($desc)) {
                        $delivered = true;
                    }
                }
            }

            if ($this->isFailedDeliveryText($trackingText)) {
                $failed = true;
            }
            
            $normalized = $targetPkg->normalized_logistics_status;
            if ($failed) $normalized = 'DELIVERY_FAILED';
            elseif ($delivered) $normalized = 'DELIVERED';
            elseif (!empty($trackingText) || !empty($t['tracking_info'])) $normalized = 'IN_TRANSIT';
            
            $targetPkg->logistics_status = $latestEventDesc ?: $targetPkg->logistics_status;
            $targetPkg->normalized_logistics_status = $normalized;
            $targetPkg->raw_data = $t;
            $targetPkg->save();
        }
    }

    private function extractLatestTrackingDescription(array $tracking): string
    {
        $events = $tracking['tracking_info']
            ?? $tracking['tracking_info_list']
            ?? $tracking['events']
            ?? [];

        if (!empty($events) && is_array($events)) {
            $latest = reset($events);
            if (is_array($latest)) {
                return $latest['description']
                    ?? $latest['event']
                    ?? $latest['status']
                    ?? $latest['message']
                    ?? '';
            }
        }

        return $tracking['description']
            ?? $tracking['logistics_status']
            ?? $tracking['status']
            ?? $tracking['sub_status']
            ?? '';
    }

    private function flattenTrackingText(array $value): string
    {
        $parts = [];
        array_walk_recursive($value, function ($item) use (&$parts) {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        });

        return strtolower(implode(' ', $parts));
    }

    private function isFailedDeliveryText(string $text): bool
    {
        $needles = [
            'delivery_failed',
            'delivery failed',
            'delivery unsuccessful',
            'failed delivery',
            'failed to deliver',
            'could not be delivered',
            'unable to deliver',
            'returned to seller',
            'returned to sender',
            'return to seller',
            'return to sender',
            'dikembalikan',
            'dikembalikan kepada',
            'dikembalikan ke',
            'paket gagal',
            'pengiriman gagal',
            'pengantaran gagal',
            'gagal dikirim',
            'gagal antar',
            'gagal diantar',
            'tidak berhasil dikirim',
            'tidak dapat dikirim',
        ];

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
