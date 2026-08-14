<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $baseQuery = Order::whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->has('store_id') && !empty($request->store_id)) {
            $baseQuery->where('store_id', $request->store_id);
        }

        $statusCounts = (clone $baseQuery)
            ->selectRaw('order_status, count(*) as count')
            ->groupBy('order_status')
            ->pluck('count', 'order_status');

        $query = (clone $baseQuery)
            ->with('orderProducts.product')
            ->latest();

        // Filter by status
        if ($request->has('statuses') && !empty($request->statuses)) {
            $statuses = is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses);
            $query->whereIn('order_status', $statuses);
        }

        $orders = $query->get()->sortByDesc('order_time')->values();

        return response()->json([
            'status_counts' => $statusCounts,
            'stores' => $stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                ];
            }),
            'orders' => $orders->map(function ($order) {
                $firstProduct = $order->orderProducts->first();
                return [
                    'id' => $order->id,
                    'store_id' => $order->store_id,
                    'order_sn' => $order->order_sn,
                    'order_status' => $order->order_status,
                    'order_time' => $order->order_time?->format('d M Y, H:i'),
                    'created_at' => $order->created_at?->format('d M Y, H:i'),
                    'order_selling_price' => $order->order_selling_price,
                    'escrow_amount' => $order->escrow_amount,
                    'first_product' => $firstProduct ? (function() use ($firstProduct) {
                        $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $firstProduct->model_name);
                        $variant = \App\Models\VariantProduct::where('product_id', $firstProduct->product?->id)
                            ->where('model_name', $normalizedModelName)
                            ->first();
                        return [
                            'product_name' => $firstProduct->product_name,
                            'model_name' => $firstProduct->model_name,
                            'image' => $firstProduct->product->image ?? null,
                            'variant_image' => $variant ? $variant->variant_image : null,
                            'quantity' => $firstProduct->quantity_purchased,
                        ];
                    })() : null,
                    'product_count' => $order->orderProducts->count(),
                    'products' => $order->orderProducts->map(function ($product) {
                        $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $product->model_name);
                        $variant = \App\Models\VariantProduct::where('product_id', $product->product?->id)
                            ->where('model_name', $normalizedModelName)
                            ->first();
                        return [
                            'product_name' => $product->product_name,
                            'model_name' => $product->model_name,
                            'quantity_purchased' => $product->quantity_purchased,
                            'price' => $product->price,
                            'image' => $product->product->image ?? null,
                            'variant_image' => $variant ? $variant->variant_image : null,
                        ];
                    }),
                ];
            }),
        ]);
    }
}
