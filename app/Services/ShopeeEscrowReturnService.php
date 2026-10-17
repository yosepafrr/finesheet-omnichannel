<?php

namespace App\Services;

use App\Jobs\SyncShopeeReturnJob;
use App\Models\Order;
use App\Models\OrderReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ShopeeEscrowReturnService
{
    public function __construct(private ShopeeEscrowAmountResolver $escrowResolver) {}

    public function sync(Order $order, array $escrow): int
    {
        if (strcasecmp((string) $order->platform, 'Shopee') !== 0) {
            return 0;
        }

        $returnSns = $this->escrowResolver->returnOrderSns($escrow);
        if ($returnSns === []) {
            return 0;
        }

        Log::info('Shopee escrow return references detected', [
            'order_sn' => $order->order_sn,
            'return_sns' => $returnSns,
        ]);

        $synced = 0;
        foreach ($returnSns as $returnSn) {
            $return = OrderReturn::firstOrNew([
                'platform' => 'Shopee',
                'external_return_id' => $returnSn,
            ]);
            $rawData = is_array($return->raw_data) ? $return->raw_data : [];
            $rawData['escrow_reference'] = [
                'order_sn' => $order->order_sn,
                'return_order_sn_list' => $returnSns,
            ];

            $return->fill([
                'order_id' => $order->id,
                'return_status' => $return->return_status ?: 'ESCROW_RECORDED',
                'platform_status' => $return->platform_status ?: 'ESCROW_RECORDED',
                'normalized_status' => $return->normalized_status ?: 'PENDING',
                'return_type' => $return->return_type ?: 'RETURN_OR_REFUND',
                'raw_data' => $rawData,
            ]);

            if (! $return->created_at_platform) {
                $return->created_at_platform = $this->orderUpdateTime($order);
            }
            $return->save();

            SyncShopeeReturnJob::dispatch(
                $order->store_id,
                null,
                null,
                $returnSn,
                [
                    'return_sn' => $returnSn,
                    'order_sn' => $order->order_sn,
                    'status' => 'PROCESSING',
                    'create_time' => $return->created_at_platform?->timestamp,
                ],
            )->onQueue('orders');

            $synced++;
        }

        return $synced;
    }

    private function orderUpdateTime(Order $order): Carbon
    {
        $timestamp = data_get($order->raw_data, 'update_time');
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            $timestamp = (int) $timestamp;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return Carbon::createFromTimestamp($timestamp)->setTimezone(config('app.timezone'));
        }

        return Carbon::parse($order->updated_at ?? now());
    }
}
