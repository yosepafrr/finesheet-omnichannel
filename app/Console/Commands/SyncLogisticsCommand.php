<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrderPackage;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Support\Facades\Log;

class SyncLogisticsCommand extends Command
{
    protected $signature = 'sync:logistics';
    protected $description = 'Sync logistics and tracking info for active packages';

    public function handle()
    {
        Log::info('SyncLogisticsCommand started');

        // Fetch packages that are not yet completely delivered or failed
        $packages = OrderPackage::whereNotIn('normalized_logistics_status', ['DELIVERED', 'DELIVERY_FAILED'])
            ->orWhereNull('normalized_logistics_status')
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
        $trackingList = $res['data']['tracking_info_list'] ?? [];
        
        foreach ($trackingList as $t) {
            $packageId = $t['package_id'] ?? $order->order_sn;
            
            // Match with the current package or create a new one if multiple
            $targetPkg = $packageId === $pkg->package_id ? $pkg : OrderPackage::firstOrNew([
                'order_id' => $order->id,
                'package_id' => $packageId
            ]);

            $targetPkg->platform = 'Tiktokshop';
            $targetPkg->tracking_number = $t['tracking_number'] ?? $targetPkg->tracking_number;

            $failed = false;
            $delivered = false;
            $latestEventDesc = '';
            
            if (!empty($t['tracking_info'])) {
                foreach ($t['tracking_info'] as $event) {
                    $desc = strtolower($event['description'] ?? '');
                    $latestEventDesc = $desc;
                    if (str_contains($desc, 'fail') || str_contains($desc, 'exception') || str_contains($desc, 'returned to seller') || str_contains($desc, 'could not be delivered') || str_contains($desc, 'dikembalikan') || str_contains($desc, 'gagal') || str_contains($desc, 'retur')) {
                        $failed = true;
                    }
                    if (str_contains($desc, 'delivered') && !str_contains($desc, 'fail')) {
                        $delivered = true;
                    }
                }
            }
            
            $normalized = $targetPkg->normalized_logistics_status;
            if ($failed) $normalized = 'DELIVERY_FAILED';
            elseif ($delivered) $normalized = 'DELIVERED';
            elseif (!empty($t['tracking_info'])) $normalized = 'IN_TRANSIT';
            
            $targetPkg->logistics_status = $latestEventDesc ?: $targetPkg->logistics_status;
            $targetPkg->normalized_logistics_status = $normalized;
            $targetPkg->raw_data = $t;
            $targetPkg->save();
        }
    }
}
