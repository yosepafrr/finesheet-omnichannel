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
            ->pluck('count', 'order_status')
            ->toArray();

        $statusCounts['gagal_kirim'] = (clone $baseQuery)
            ->whereHas('packages', function ($q) {
                $q->where('normalized_logistics_status', 'DELIVERY_FAILED');
            })
            ->count();

        $statusCounts['return'] = (clone $baseQuery)
            ->where(function ($q) {
                $q->where('order_status', 'TO_RETURN')
                    ->orWhereHas('returns');
            })
            ->count();

        $cancelBase = (clone $baseQuery)->whereIn('order_status', ['CANCEL', 'CANCELLED', 'IN_CANCEL'])
            ->whereDoesntHave('packages', function ($q) {
                $q->where('normalized_logistics_status', 'DELIVERY_FAILED');
            });
        $cancelSubCounts = [
            'all' => (clone $cancelBase)->count(),
            'SELLER_LATE_SHIPMENT' => (clone $cancelBase)->where('normalized_cancel_category', 'SELLER_LATE_SHIPMENT')->count(),
            'BUYER_SIDE' => (clone $cancelBase)->where('normalized_cancel_category', 'BUYER_SIDE')->count(),
        ];

        $query = (clone $baseQuery)
            ->with(['orderProducts.product', 'returns', 'packages'])
            ->latest();

        // Filter by failed delivery
        if ($request->has('is_failed_delivery') && $request->is_failed_delivery === 'true') {
            $query->whereHas('packages', function ($q) {
                $q->where('normalized_logistics_status', 'DELIVERY_FAILED');
            });
        }

        // Filter by return/refund
        if ($request->has('is_return') && $request->is_return === 'true') {
            $query->where(function ($q) {
                $q->where('order_status', 'TO_RETURN')
                    ->orWhereHas('returns');
            });
        }

        // Filter by status
        if ($request->has('statuses') && !empty($request->statuses)) {
            $statuses = is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses);
            $query->whereIn('order_status', $statuses);

            // Exclude DELIVERY_FAILED from regular status filters (like CANCELLED) so they only show in Pengiriman Gagal tab
            if (!($request->has('is_failed_delivery') && $request->is_failed_delivery === 'true')) {
                if (in_array('CANCELLED', $statuses) || in_array('CANCEL', $statuses) || in_array('IN_CANCEL', $statuses)) {
                    $query->whereDoesntHave('packages', function ($q) {
                        $q->where('normalized_logistics_status', 'DELIVERY_FAILED');
                    });
                }
            }
        }

        // Filter by cancel category
        if ($request->filled('cancel_category') && $request->cancel_category !== 'all') {
            $query->where('normalized_cancel_category', $request->cancel_category);
        }

        $orders = $query->get()->sortByDesc('order_time')->values();

        return response()->json([
            'status_counts' => $statusCounts,
            'cancel_sub_counts' => $cancelSubCounts,
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
                    'order_time' => $order->order_time?->setTimezone('Asia/Jakarta')->toIso8601String(),
                    'created_at' => $order->created_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                    'order_selling_price' => $order->order_selling_price,
                    'escrow_amount' => $order->escrow_amount,
                    'cancel_source' => $order->cancel_source,
                    'cancel_reason' => $order->cancel_reason,
                    'buyer_cancel_reason' => $order->buyer_cancel_reason,
                    'normalized_cancel_category' => $order->normalized_cancel_category,
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
                    'returns' => $order->returns->map(function ($ret) {
                        return [
                            'id' => $ret->id,
                            'platform' => $ret->platform,
                            'external_return_id' => $ret->external_return_id,
                            'return_status' => $ret->return_status,
                            'platform_status' => $ret->platform_status ?? $ret->return_status,
                            'normalized_status' => $ret->normalized_status,
                        ];
                    }),
                    'shipping_provider' => $order->platform === 'Shopee' 
                        ? ($order->raw_data['shipping_carrier'] ?? null) 
                        : ($order->raw_data['shipping_provider'] ?? null),
                    'tracking_number' => $order->platform === 'Shopee' 
                        ? ($order->raw_data['tracking_no'] ?? null) 
                        : ($order->raw_data['tracking_number'] ?? null),
                    'packages' => $order->packages->map(function ($pkg) {
                        return [
                            'package_id' => $pkg->package_id,
                            'tracking_number' => $pkg->tracking_number,
                            'logistics_status' => $pkg->logistics_status,
                            'normalized_logistics_status' => $pkg->normalized_logistics_status,
                        ];
                    }),
                ];
            }),
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = Auth::user();
        $stores = $user->stores()->pluck('id');

        $order = Order::with(['orderProducts.product', 'returns.items', 'store', 'packages'])
            ->whereIn('store_id', $stores)
            ->findOrFail($id);

        $customerInfo = null;
        $shippingInfo = null;
        
        $rawData = $order->raw_data;
        if ($rawData) {
            if ($order->platform === 'Shopee') {
                $customerInfo = [
                    'name' => $rawData['recipient_address']['name'] ?? null,
                    'phone' => $rawData['recipient_address']['phone'] ?? null,
                ];
                $shippingInfo = [
                    'provider' => $rawData['shipping_carrier'] ?? null,
                    'tracking_number' => $rawData['tracking_no'] ?? null,
                    'address' => $rawData['recipient_address']['full_address'] ?? null,
                ];
            } else if ($order->platform === 'Tiktokshop') {
                $customerInfo = [
                    'name' => $rawData['recipient_address']['name'] ?? null,
                    'phone' => $rawData['recipient_address']['phone_number'] ?? null,
                ];
                $shippingInfo = [
                    'provider' => $rawData['shipping_provider'] ?? null,
                    'tracking_number' => $rawData['tracking_number'] ?? null,
                    'address' => $rawData['recipient_address']['full_address'] ?? null,
                ];
            }
        }

        $orderData = [
            'id' => $order->id,
            'store_name' => $order->store->store_name ?? 'Unknown',
            'platform' => $order->platform,
            'order_sn' => $order->order_sn,
            'order_status' => $order->order_status,
            'order_time' => $order->order_time?->setTimezone('Asia/Jakarta')->format('d M Y, H:i'),
            'created_at' => $order->created_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i'),
            'updated_at' => $order->updated_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i'),
            'order_selling_price' => $order->order_selling_price,
            'escrow_amount' => $order->escrow_amount,
            'fee_details' => $order->fee_details,
            'cancel_source' => $order->cancel_source,
            'cancel_reason' => $order->cancel_reason,
            'buyer_cancel_reason' => $order->buyer_cancel_reason,
            'normalized_cancel_category' => $order->normalized_cancel_category,
            'customer_info' => $customerInfo,
            'shipping_info' => $shippingInfo,
            'raw_data' => $rawData, // For developer mode
            'products' => $order->orderProducts->map(function ($product) {
                $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $product->model_name);
                $variant = \App\Models\VariantProduct::where('product_id', $product->product?->id)
                    ->where('model_name', $normalizedModelName)
                    ->first();
                return [
                    'product_name' => $product->product_name,
                    'model_name' => $product->model_name,
                    'sku' => $variant ? $variant->model_sku : null,
                    'quantity_purchased' => $product->quantity_purchased,
                    'price' => $product->price,
                    'subtotal' => $product->price * $product->quantity_purchased,
                    'image' => $product->product->image ?? null,
                    'variant_image' => $variant ? $variant->variant_image : null,
                ];
            }),
            'returns' => $order->returns->map(function ($ret) {
                return [
                    'id' => $ret->id,
                    'platform' => $ret->platform,
                    'external_return_id' => $ret->external_return_id,
                    'return_status' => $ret->return_status,
                    'platform_status' => $ret->platform_status ?? $ret->return_status,
                    'normalized_status' => $ret->normalized_status,
                    'return_type' => $ret->return_type,
                    'refund_amount' => $ret->refund_amount,
                    'return_reason' => $ret->return_reason,
                    'text_reason' => $ret->text_reason,
                    'tracking_number' => $ret->tracking_number,
                    'created_at_platform' => $ret->created_at_platform?->format('d M Y, H:i'),
                    'updated_at_platform' => $ret->updated_at_platform?->format('d M Y, H:i'),
                    'items' => $ret->items->map(function ($item) {
                        return [
                            'product_name' => $item->product_name,
                            'quantity' => $item->quantity,
                        ];
                    }),
                ];
            }),
            'packages' => $order->packages->map(function ($pkg) {
                return [
                    'package_id' => $pkg->package_id,
                    'tracking_number' => $pkg->tracking_number,
                    'logistics_status' => $pkg->logistics_status,
                    'normalized_logistics_status' => $pkg->normalized_logistics_status,
                    'raw_data' => $pkg->raw_data,
                ];
            }),
        ];

        return response()->json(['order' => $orderData]);
    }
}
