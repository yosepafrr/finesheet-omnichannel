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

        $query = Order::whereIn('store_id', $storeIds)
            ->with('orderItems.item')
            ->latest();

        // Filter by status
        if ($request->has('statuses') && !empty($request->statuses)) {
            $statuses = is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses);
            $query->whereIn('order_status', $statuses);
        }

        // Filter by store
        if ($request->has('store_id') && !empty($request->store_id)) {
            $query->where('store_id', $request->store_id);
        }

        $orders = $query->get()->sortByDesc('order_time')->values();

        return response()->json([
            'stores' => $stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                ];
            }),
            'orders' => $orders->map(function ($order) {
                $firstItem = $order->orderItems->first();
                return [
                    'id' => $order->id,
                    'store_id' => $order->store_id,
                    'order_sn' => $order->order_sn,
                    'order_status' => $order->order_status,
                    'order_time' => $order->order_time?->format('d M Y, H:i'),
                    'created_at' => $order->created_at?->format('d M Y, H:i'),
                    'order_selling_price' => $order->order_selling_price,
                    'escrow_amount' => $order->escrow_amount,
                    'first_item' => $firstItem ? [
                        'item_name' => $firstItem->item_name,
                        'model_name' => $firstItem->model_name,
                        'image' => $firstItem->item->image ?? null,
                        'quantity' => $firstItem->quantity_purchased,
                    ] : null,
                    'item_count' => $order->orderItems->count(),
                    'items' => $order->orderItems->map(function ($item) {
                        return [
                            'item_name' => $item->item_name,
                            'model_name' => $item->model_name,
                            'quantity_purchased' => $item->quantity_purchased,
                            'price' => $item->price,
                            'image' => $item->item->image ?? null,
                        ];
                    }),
                ];
            }),
        ]);
    }
}
