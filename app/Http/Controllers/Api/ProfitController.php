<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Services\OrderEscrowService;
use Illuminate\Support\Facades\Auth;

class ProfitController extends Controller
{
    public function index(Request $request, OrderEscrowService $escrowService)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $orders = Order::with('returns')->whereIn('store_id', $storeIds)
            ->orderByDesc('order_time')
            ->get()
            ->groupBy('store_id');

        // Escrow Filters
        $includePerluDikirim = filter_var($request->query('include_perlu_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeDikirim = filter_var($request->query('include_dikirim', true), FILTER_VALIDATE_BOOLEAN);
        $includeReturn = filter_var($request->query('include_return', false), FILTER_VALIDATE_BOOLEAN);

        $includedCategories = array_keys(array_filter([
            OrderEscrowService::CATEGORY_NEEDS_SHIPPING => $includePerluDikirim,
            OrderEscrowService::CATEGORY_SHIPPED => $includeDikirim,
            OrderEscrowService::CATEGORY_RETURN_CANCEL => $includeReturn,
        ]));

        $storeSummaries = $stores->map(function ($store) use ($orders, $escrowService, $includedCategories) {
            $summary = $escrowService->summarize(
                $orders->get($store->id, collect()),
                $includedCategories,
            );

            return [
                'id' => $store->id,
                'store_name' => $store->store_name,
                'platform' => $store->platform,
                'escrow' => $summary['total_escrow'],
                'included_order_count' => $summary['total_orders'],
                'status_counts' => $summary['status_counts'],
                'status_escrow' => $summary['status_escrow'],
            ];
        });

        $totalOrderSellingPrice = $orders->collapse()->sum('order_selling_price');
        $totalEscrowAmount = (float) $storeSummaries->sum('escrow');
        
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

        $storeSummaries = $storeSummaries
            ->map(function (array $store) use ($totalEscrowAmount) {
                $store['percent'] = $totalEscrowAmount > 0
                    ? round(($store['escrow'] / $totalEscrowAmount) * 100, 1)
                    : 0;

                return $store;
            })
            ->sortByDesc('escrow')
            ->values();

        return response()->json([
            'total_order_selling_price' => $totalOrderSellingPrice,
            'total_escrow_amount' => $totalEscrowAmount,
            'total_supplier_debt' => $totalSupplierDebt,
            'net_estimation' => $netEstimation,
            'margin' => $totalEscrowAmount > 0
                ? round(($netEstimation / $totalEscrowAmount) * 100, 1)
                : 0,
            'stores' => $storeSummaries,
        ]);
    }
}
