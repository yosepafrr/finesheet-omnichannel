<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class ProfitController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $orders = Order::whereIn('store_id', $storeIds)
            ->latest()
            ->get()
            ->sortByDesc('order_time');

        $totalOrderSellingPrice = $orders->sum('order_selling_price');
        $totalEscrowAmount = $orders->sum('escrow_amount');
        $totalAds = 1000500; // Static for now (can come from DB later)
        $netEstimation = $totalEscrowAmount - $totalAds;

        $storeEscrowTotal = [];
        foreach ($stores as $store) {
            $storeEscrowTotal[$store->id] = $orders
                ->where('store_id', $store->id)
                ->sum('escrow_amount');
        }

        return response()->json([
            'total_order_selling_price' => $totalOrderSellingPrice,
            'total_escrow_amount' => $totalEscrowAmount,
            'total_ads' => $totalAds,
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
