<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class ProfitController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        // Original unfiltered orders (for total selling price if needed)
        $orders = Order::with('returns')->whereIn('store_id', $storeIds)
            ->latest()
            ->get()
            ->sortByDesc('order_time');

        // Escrow Filters
        $includePerluDikirim = filter_var($request->query('include_perlu_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeDikirim = filter_var($request->query('include_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeReturn = filter_var($request->query('include_return', false), FILTER_VALIDATE_BOOLEAN);

        \Illuminate\Support\Facades\Log::info('Profit Tracker Filters', [
            'raw_request' => $request->all(),
            'perlu_dikirim' => $includePerluDikirim,
            'dikirim' => $includeDikirim,
            'return' => $includeReturn
        ]);

        $escrowOrders = $orders->filter(function ($order) use ($includePerluDikirim, $includeDikirim, $includeReturn) {
            $status = strtoupper(trim($order->order_status ?? ''));

            // 1. Abaikan pesanan yang SUDAH SELESAI batal/retur
            if (in_array($status, ['CANCELLED', 'RETURNED'])) {
                return false;
            }

            $hasActiveReturn = false;
            if ($order->returns && $order->returns->count() > 0) {
                $returnStatus = strtoupper(trim($order->returns->first()->normalized_status ?? ''));
                
                // Jika return selesai/refund selesai/unsupported, profit hilang = abaikan pesanan
                if (in_array($returnStatus, ['REFUND_COMPLETED', 'COMPLETED', 'UNSUPPORTED'])) {
                    return false;
                }
                
                // Jika return ditolak/dibatalkan oleh buyer, pesanan lanjut secara normal (tidak ada retur aktif)
                if (in_array($returnStatus, ['REJECTED', 'CANCELLED'])) {
                    $hasActiveReturn = false;
                } else {
                    // Masih ada proses retur aktif (Menunggu pembeli, proses refund, dll)
                    $hasActiveReturn = true;
                }
            }

            // 2. Tentukan kategori pesanan
            $category = 'OTHER';
            if ($hasActiveReturn || in_array($status, ['IN_CANCEL', 'TO_RETURN'])) {
                $category = 'RETURN';
            } elseif (in_array($status, ['SHIPPED', 'IN_TRANSIT', 'DELIVERED', 'TO_CONFIRM_RECEIVE'])) {
                $category = 'DIKIRIM';
            } elseif (in_array($status, ['READY_TO_SHIP', 'PROCESSED', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION'])) {
                $category = 'PERLU_DIKIRIM';
            }

            // 3. Filter sesuai kategori yang dinyalakan di UI
            if ($category === 'RETURN' && $includeReturn) return true;
            if ($category === 'DIKIRIM' && $includeDikirim) return true;
            if ($category === 'PERLU_DIKIRIM' && $includePerluDikirim) return true;

            return false;
        });

        $totalOrderSellingPrice = $orders->sum('order_selling_price'); // Keep total order selling price unfiltered (or you can filter it if desired)
        $totalEscrowAmount = $escrowOrders->sum('escrow_amount');
        
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
            
        $netEstimation = $totalEscrowAmount - $totalSupplierDebt;

        $storeEscrowTotal = [];
        foreach ($stores as $store) {
            $storeEscrowTotal[$store->id] = $escrowOrders
                ->where('store_id', $store->id)
                ->sum('escrow_amount');
        }

        return response()->json([
            'total_order_selling_price' => $totalOrderSellingPrice,
            'total_escrow_amount' => $totalEscrowAmount,
            'total_supplier_debt' => $totalSupplierDebt,
            'net_estimation' => $netEstimation,
            'margin' => $totalEscrowAmount > 0
                ? round(($netEstimation / $totalEscrowAmount) * 100, 1)
                : 0,
            'stores' => $stores->map(function ($store) use ($storeEscrowTotal, $totalEscrowAmount) {
                $escrow = $storeEscrowTotal[$store->id] ?? 0;
                $percent = $totalEscrowAmount > 0 ? ($escrow / $totalEscrowAmount) * 100 : 0;
                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                    'escrow' => $escrow,
                    'percent' => round($percent, 1),
                ];
            }),
        ]);
    }
}
