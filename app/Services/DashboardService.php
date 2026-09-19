<?php

namespace App\Services;

class DashboardService
{
    public function getStats()
    {
        $user = auth()->user();
        $storeIds = \App\Models\Store::where('user_id', $user->id)->pluck('id');
        
        // Fetch all orders to match Profit Tracker all-time logic
        $orders = \App\Models\Order::with('returns')->whereIn('store_id', $storeIds)->get();

        $escrowOrders = $orders->filter(function ($order) {
            $status = strtoupper(trim($order->order_status ?? ''));

            if (in_array($status, ['CANCELLED', 'RETURNED'])) {
                return false;
            }

            $hasActiveReturn = false;
            if ($order->returns && $order->returns->count() > 0) {
                $returnStatus = strtoupper(trim($order->returns->first()->normalized_status ?? ''));
                if (in_array($returnStatus, ['REFUND_COMPLETED', 'COMPLETED', 'UNSUPPORTED'])) {
                    return false;
                }
                if (in_array($returnStatus, ['REJECTED', 'CANCELLED'])) {
                    $hasActiveReturn = false;
                } else {
                    $hasActiveReturn = true;
                }
            }

            // Dashboard always includes all categories for the total escrow calculation, same as Profit Tracker when all filters are true
            $category = 'OTHER';
            if ($hasActiveReturn || in_array($status, ['IN_CANCEL', 'TO_RETURN'])) {
                $category = 'RETURN';
            } elseif (in_array($status, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'TO_CONFIRM_RECEIVE'])) {
                $category = 'DIKIRIM';
            } elseif (in_array($status, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])) {
                $category = 'PERLU_DIKIRIM';
            }

            if (in_array($category, ['DIKIRIM', 'PERLU_DIKIRIM'])) {
                return true;
            }
            return false;
        });

        // Hitung Jumlah Pesanan (Perlu Dikirim)
        $perluDikirimCount = $orders->filter(function ($order) {
            $status = strtoupper(trim($order->order_status ?? ''));
            return in_array($status, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION']);
        })->count();

        // Hitung total hutang supplier
        $totalDebt = \App\Models\PayableEvent::where('user_id', $user->id)
            ->whereHas('period', function($q) { $q->where('payment_status', '!=', 'PAID'); })
            ->where('amount', '>', 0)->sum('amount');
            
        $totalReduction = \App\Models\PayableEvent::where('user_id', $user->id)
            ->whereHas('period', function($q) { $q->where('payment_status', '!=', 'PAID'); })
            ->where('amount', '<', 0)->sum('amount');
            
        $totalPaid = \App\Models\PayablePayment::where('user_id', $user->id)
            ->whereHas('period', function($q) { $q->where('payment_status', '!=', 'PAID'); })
            ->sum('amount');
            
        $totalSupplierDebt = ($totalDebt + $totalReduction) - $totalPaid;
        if ($totalSupplierDebt < 0) $totalSupplierDebt = 0;

        $totalEscrowAmount = $escrowOrders->sum('escrow_amount');
        $netEstimation = $totalEscrowAmount - $totalSupplierDebt;

        // Chart Data (Dynamic Range)
        $days = (int) request()->get('days', 7);
        $startDate = \Carbon\Carbon::now()->subDays($days)->startOfDay();

        $filteredOrders = $orders->where('order_time', '>=', $startDate);

        $orderTrend = [];
        $profitTrend = [];

        $groupedByDate = $filteredOrders->groupBy(function($order) {
            return $order->order_time ? $order->order_time->format('Y-m-d') : null;
        })->filter(function($val, $key) { return $key !== null; })->sortKeys();

        foreach($groupedByDate as $date => $dayOrders) {
            $orderTrend[] = [
                'date' => \Carbon\Carbon::parse($date)->format('d M'),
                'Total Pesanan' => $dayOrders->count()
            ];

            $dayEscrow = $dayOrders->filter(function($order) {
                $status = strtoupper(trim($order->order_status ?? ''));
                if (in_array($status, ['CANCELLED', 'RETURNED'])) return false;

                $hasActiveReturn = false;
                if ($order->returns && $order->returns->count() > 0) {
                    $returnStatus = strtoupper(trim($order->returns->first()->normalized_status ?? ''));
                    if (in_array($returnStatus, ['REFUND_COMPLETED', 'COMPLETED', 'UNSUPPORTED'])) {
                        return false;
                    }
                    if (in_array($returnStatus, ['REJECTED', 'CANCELLED'])) {
                        $hasActiveReturn = false;
                    } else {
                        $hasActiveReturn = true;
                    }
                }

                $category = 'OTHER';
                if ($hasActiveReturn || in_array($status, ['IN_CANCEL', 'TO_RETURN'])) {
                    $category = 'RETURN';
                } elseif (in_array($status, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'TO_CONFIRM_RECEIVE'])) {
                    $category = 'DIKIRIM';
                } elseif (in_array($status, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])) {
                    $category = 'PERLU_DIKIRIM';
                }

                if (in_array($category, ['DIKIRIM', 'PERLU_DIKIRIM'])) {
                    return true;
                }
                return false;
            })->sum('escrow_amount');
            
            $profitTrend[] = [
                'date' => \Carbon\Carbon::parse($date)->format('d M'),
                'Estimasi Profit' => $dayEscrow
            ];
        }

        $platformDistribution = $filteredOrders->groupBy('platform')->map(function($platformOrders, $platform) {
            return [
                'name' => ucfirst($platform),
                'value' => $platformOrders->count()
            ];
        })->values()->toArray();

        return [
            'perlu_dikirim_count' => $perluDikirimCount,
            'total_supplier_debt' => $totalSupplierDebt,
            'net_estimation' => $netEstimation,
            'order_trend' => $orderTrend,
            'profit_trend' => $profitTrend,
            'platform_distribution' => $platformDistribution,
        ];
    }
}
