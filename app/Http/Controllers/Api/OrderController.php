<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopeeEscrowJob;
use App\Jobs\SyncTiktokEscrowJob;
use App\Models\Order;
use App\Models\VariantProduct;
use App\Services\LogisticsStatusNormalizer;
use App\Services\OrderEscrowService;
use App\Services\OrderFinancialBreakdownService;
use App\Services\OrderProfitService;
use App\Services\PayableService;
use App\Services\TiktokEscrowAmountResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function index(
        Request $request,
        OrderEscrowService $escrowService,
        OrderFinancialBreakdownService $financialBreakdownService,
        OrderProfitService $profitService
    ) {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $baseQuery = Order::whereIn('store_id', $storeIds);

        // Filter by store
        if ($request->has('store_id') && ! empty($request->store_id)) {
            $baseQuery->where('store_id', $request->store_id);
        }

        $platform = match (strtolower((string) $request->input('platform'))) {
            'shopee' => 'Shopee',
            'tiktokshop', 'tiktok' => 'Tiktokshop',
            default => null,
        };

        if ($platform) {
            $baseQuery->whereIn('platform', $platform === 'Shopee'
                ? ['Shopee', 'shopee', 'SHOPEE']
                : ['Tiktokshop', 'TikTokShop', 'tiktokshop', 'Tiktok', 'tiktok']);
        }

        $shippingProcessFilters = [
            'needs_processing' => [
                'Shopee' => 'READY_TO_SHIP',
                'Tiktokshop' => 'AWAITING_SHIPMENT',
            ],
            'processed' => [
                'Shopee' => 'PROCESSED',
                'Tiktokshop' => 'AWAITING_COLLECTION',
            ],
        ];

        $applyShippingProcessFilter = function ($query, string $filter) use ($shippingProcessFilters) {
            $statuses = $shippingProcessFilters[$filter] ?? null;

            if (! $statuses) {
                return $query;
            }

            return $query->where(function ($statusQuery) use ($statuses) {
                foreach ($statuses as $statusPlatform => $status) {
                    $statusQuery->orWhere(function ($platformQuery) use ($statusPlatform, $status) {
                        $platformQuery
                            ->whereIn('platform', $statusPlatform === 'Shopee'
                                ? ['Shopee', 'shopee', 'SHOPEE']
                                : ['Tiktokshop', 'TikTokShop', 'tiktokshop', 'Tiktok', 'tiktok'])
                            ->where('order_status', $status);
                    });
                }
            });
        };

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

        $cancelCounts = (clone $cancelBase)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN order_status = 'CANCEL' THEN 1 ELSE 0 END) AS cancel_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count")
            ->selectRaw("SUM(CASE WHEN order_status = 'IN_CANCEL' THEN 1 ELSE 0 END) AS in_cancel_count")
            ->selectRaw("SUM(CASE WHEN normalized_cancel_category = 'SELLER_LATE_SHIPMENT' THEN 1 ELSE 0 END) AS seller_late_count")
            ->selectRaw("SUM(CASE WHEN normalized_cancel_category = 'BUYER_SIDE' THEN 1 ELSE 0 END) AS buyer_side_count")
            ->first();

        $statusCounts['CANCEL'] = (int) ($cancelCounts->cancel_count ?? 0);
        $statusCounts['CANCELLED'] = (int) ($cancelCounts->cancelled_count ?? 0);
        $statusCounts['IN_CANCEL'] = (int) ($cancelCounts->in_cancel_count ?? 0);

        $cancelSubCounts = [
            'all' => (int) ($cancelCounts->total ?? 0),
            'SELLER_LATE_SHIPMENT' => (int) ($cancelCounts->seller_late_count ?? 0),
            'BUYER_SIDE' => (int) ($cancelCounts->buyer_side_count ?? 0),
        ];

        $shippingCounts = (clone $baseQuery)
            ->selectRaw("SUM(CASE WHEN (platform IN ('Shopee', 'shopee', 'SHOPEE') AND order_status = 'READY_TO_SHIP') OR (platform IN ('Tiktokshop', 'TikTokShop', 'tiktokshop', 'Tiktok', 'tiktok') AND order_status = 'AWAITING_SHIPMENT') THEN 1 ELSE 0 END) AS needs_processing_count")
            ->selectRaw("SUM(CASE WHEN (platform IN ('Shopee', 'shopee', 'SHOPEE') AND order_status = 'PROCESSED') OR (platform IN ('Tiktokshop', 'TikTokShop', 'tiktokshop', 'Tiktok', 'tiktok') AND order_status = 'AWAITING_COLLECTION') THEN 1 ELSE 0 END) AS processed_count")
            ->first();
        $shippingProcessCounts = [
            'needs_processing' => (int) ($shippingCounts->needs_processing_count ?? 0),
            'processed' => (int) ($shippingCounts->processed_count ?? 0),
        ];
        $shippingProcessCounts['all'] = $shippingProcessCounts['needs_processing']
            + $shippingProcessCounts['processed'];

        $query = (clone $baseQuery)
            ->with([
                'orderProducts.product.variantProducts',
                'returns',
                'packages',
                'store',
            ])
            ->orderByDesc('order_time')
            ->orderByDesc('id');

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
        if ($request->has('statuses') && ! empty($request->statuses)) {
            $statuses = is_array($request->statuses) ? $request->statuses : explode(',', $request->statuses);
            $query->whereIn('order_status', $statuses);

            // Exclude DELIVERY_FAILED from regular status filters (like CANCELLED) so they only show in Pengiriman Gagal tab
            if (! ($request->has('is_failed_delivery') && $request->is_failed_delivery === 'true')) {
                if (in_array('CANCELLED', $statuses) || in_array('CANCEL', $statuses) || in_array('IN_CANCEL', $statuses)) {
                    $query->whereDoesntHave('packages', function ($q) {
                        $q->where('normalized_logistics_status', 'DELIVERY_FAILED');
                    });
                }
            }
        }

        if ($request->filled('shipping_process')) {
            $applyShippingProcessFilter($query, (string) $request->input('shipping_process'));
        }

        // Filter by cancel category
        if ($request->filled('cancel_category') && $request->cancel_category !== 'all') {
            $query->where('normalized_cancel_category', $request->cancel_category);
        }

        if ($request->boolean('exclude_returns')) {
            $query
                ->whereNotIn('order_status', ['CANCEL', 'CANCELLED', 'IN_CANCEL', 'TO_RETURN', 'RETURNED'])
                ->whereDoesntHave('returns')
                ->whereDoesntHave('packages', function ($packageQuery) {
                    $packageQuery->where('normalized_logistics_status', 'DELIVERY_FAILED');
                });
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $searchPattern = '%'.mb_strtolower($search).'%';
            $query->where(function ($searchQuery) use ($searchPattern) {
                $searchQuery
                    ->whereRaw('LOWER(order_sn) LIKE ?', [$searchPattern])
                    ->orWhereHas('orderProducts', function ($productQuery) use ($searchPattern) {
                        $productQuery
                            ->whereRaw('LOWER(product_name) LIKE ?', [$searchPattern])
                            ->orWhereRaw('LOWER(model_name) LIKE ?', [$searchPattern])
                            ->orWhereRaw('LOWER(sku) LIKE ?', [$searchPattern]);
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);
        $paginator = $query->paginate($perPage);
        $orders = collect($paginator->items());

        $financialSummaries = $profitService->summaries($orders);

        return response()->json([
            'status_counts' => $statusCounts,
            'cancel_sub_counts' => $cancelSubCounts,
            'shipping_process_counts' => $shippingProcessCounts,
            'stores' => $stores->map(function ($store) {
                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => match (strtolower((string) $store->platform)) {
                        'shopee' => 'Shopee',
                        'tiktokshop', 'tiktok' => 'Tiktokshop',
                        default => $store->platform,
                    },
                ];
            }),
            'totals' => [
                'hpp_amount' => (float) $financialSummaries->sum('hpp_amount'),
                'escrow_amount' => (float) $financialSummaries->sum('escrow_amount'),
                'profit_amount' => (float) $financialSummaries->sum('profit_amount'),
                'has_missing_hpp' => $financialSummaries->contains('has_missing_hpp', true),
            ],
            'totals_scope' => 'page',
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'orders' => $orders->map(function ($order) use ($financialBreakdownService, $financialSummaries) {
                $firstProduct = $order->orderProducts->first();
                $financialBreakdown = $financialBreakdownService->forOrder($order);
                $financialSummary = $financialSummaries->get($order->id);

                return [
                    'id' => $order->id,
                    'store_id' => $order->store_id,
                    'store_name' => $order->store?->store_name,
                    'platform' => match (strtolower((string) $order->platform)) {
                        'shopee' => 'Shopee',
                        'tiktokshop', 'tiktok' => 'Tiktokshop',
                        default => $order->platform,
                    },
                    'order_sn' => $order->order_sn,
                    'order_status' => $order->order_status,
                    'order_time' => $order->order_time?->setTimezone('Asia/Jakarta')->toIso8601String(),
                    'created_at' => $order->created_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
                    'order_selling_price' => $order->order_selling_price,
                    'hpp_amount' => $financialSummary['hpp_amount'],
                    'escrow_amount' => $financialSummary['escrow_amount'],
                    'profit_amount' => $financialSummary['profit_amount'],
                    'has_missing_hpp' => $financialSummary['has_missing_hpp'],
                    'is_affiliate' => $financialBreakdown['is_affiliate'],
                    'affiliate_percentage' => $financialBreakdown['affiliate_percentage'],
                    'cancel_source' => $order->cancel_source,
                    'cancel_reason' => $order->cancel_reason,
                    'buyer_cancel_reason' => $order->buyer_cancel_reason,
                    'normalized_cancel_category' => $order->normalized_cancel_category,
                    'first_product' => $firstProduct ? (function () use ($firstProduct) {
                        $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $firstProduct->model_name);
                        $variant = $firstProduct->product?->variantProducts->first(
                            fn (VariantProduct $candidate) => in_array(
                                (string) $candidate->model_name,
                                [$firstProduct->model_name, $normalizedModelName],
                                true,
                            ),
                        );

                        return [
                            'product_name' => $firstProduct->product_name,
                            'model_name' => $firstProduct->model_name,
                            'image' => $firstProduct->product->image ?? $firstProduct->image,
                            'variant_image' => $variant ? $variant->variant_image : null,
                            'quantity' => $firstProduct->quantity_purchased,
                        ];
                    })() : null,
                    'product_count' => $order->orderProducts->count(),
                    'products' => $order->orderProducts->map(function ($product) {
                        $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $product->model_name);
                        $variant = $product->product?->variantProducts->first(
                            fn (VariantProduct $candidate) => in_array(
                                (string) $candidate->model_name,
                                [$product->model_name, $normalizedModelName],
                                true,
                            ),
                        );

                        return [
                            'product_name' => $product->product_name,
                            'model_name' => $product->model_name,
                            'quantity_purchased' => $product->quantity_purchased,
                            'price' => $product->price,
                            'image' => $product->product->image ?? $product->image,
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

    public function show(
        Request $request,
        $id,
        OrderEscrowService $escrowService,
        OrderFinancialBreakdownService $financialBreakdownService,
        LogisticsStatusNormalizer $logisticsStatusNormalizer,
        TiktokEscrowAmountResolver $tiktokEscrowResolver,
        PayableService $payableService,
        OrderProfitService $profitService,
    ) {
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
            } elseif ($order->platform === 'Tiktokshop') {
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

        $financeSyncPending = false;
        if ($escrowService->needsShopeeFinanceRefresh($order)) {
            SyncShopeeEscrowJob::dispatch($order->store_id, $order->order_sn)
                ->onQueue('orders-low');
            $financeSyncPending = true;
        } elseif (
            strtolower((string) $order->platform) === 'tiktokshop'
            && $tiktokEscrowResolver->shouldTryStatement($order->order_status)
            && $tiktokEscrowResolver->needsRefresh(
                $order->fee_details,
                $order->order_status,
                $order->escrow_amount,
                $escrowService->hasReturnOrFailedDelivery($order),
            )
        ) {
            SyncTiktokEscrowJob::dispatch(
                $order->store_id,
                $order->order_sn,
                $order->order_status,
                $tiktokEscrowResolver->fallbackForOrder($order),
                true
            )->onQueue('orders-low');
            $financeSyncPending = true;
        }

        $financialBreakdown = $financialBreakdownService->forOrder($order);
        $financialBreakdown['sync_pending'] = $financeSyncPending;
        $financialSummary = $profitService->summaries(collect([$order]))->get($order->id, []);
        $financialBreakdown['has_financial_correction'] = $escrowService
            ->hasReturnOrFailedDelivery($order);
        $financialBreakdown['sale_escrow_amount'] = (float) (
            $financialSummary['sale_escrow_amount'] ?? $financialBreakdown['escrow_amount']
        );
        $financialBreakdown['sale_escrow_source'] = $financialSummary['sale_escrow_source'] ?? null;
        $financialBreakdown['sale_escrow_is_estimated'] = (bool) (
            $financialSummary['sale_escrow_is_estimated'] ?? false
        );
        $financialBreakdown['sale_escrow_sample_size'] = (int) (
            $financialSummary['sale_escrow_sample_size'] ?? 0
        );
        $financialBreakdown['display_escrow_amount'] = ! $financialBreakdown['has_financial_correction']
            && abs((float) $financialBreakdown['escrow_amount']) < 0.0001
            && (float) $financialBreakdown['sale_escrow_amount'] > 0
                ? (float) $financialBreakdown['sale_escrow_amount']
                : (float) $financialBreakdown['escrow_amount'];

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
            'escrow_amount' => $escrowService->amount($order),
            'fee_details' => $order->fee_details,
            'financial_breakdown' => $financialBreakdown,
            'cancel_source' => $order->cancel_source,
            'cancel_reason' => $order->cancel_reason,
            'buyer_cancel_reason' => $order->buyer_cancel_reason,
            'normalized_cancel_category' => $order->normalized_cancel_category,
            'customer_info' => $customerInfo,
            'shipping_info' => $shippingInfo,
            'raw_data' => $rawData, // For developer mode
            'products' => $order->orderProducts->map(function ($product) {
                $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $product->model_name);
                $variant = VariantProduct::where('product_id', $product->product?->id)
                    ->where('model_name', $normalizedModelName)
                    ->first();

                return [
                    'product_name' => $product->product_name,
                    'model_name' => $product->model_name,
                    'sku' => $variant ? $variant->model_sku : null,
                    'quantity_purchased' => $product->quantity_purchased,
                    'price' => $product->price,
                    'subtotal' => $product->price * $product->quantity_purchased,
                    'image' => $product->product->image ?? $product->image,
                    'variant_image' => $variant ? $variant->variant_image : null,
                ];
            }),
            'returns' => $order->returns->map(function ($ret) use ($payableService) {
                $effectiveEventAt = $payableService->returnEventDate($ret);
                $platformCreatedAt = $ret->created_at_platform;
                $reverseTracking = data_get($ret->raw_data, 'reverse_tracking', []);
                $reverseTrackingHistory = collect([
                    [
                        'segment' => 'Pembeli ke pusat validasi',
                        'events' => $reverseTracking['tracking_info'] ?? [],
                    ],
                    [
                        'segment' => 'Pusat validasi ke penjual',
                        'events' => $reverseTracking['post_return_logistics_tracking_info'] ?? [],
                    ],
                ])->flatMap(function ($segment) {
                    return collect($segment['events'])->map(function ($event) use ($segment) {
                        $timestamp = $event['update_time'] ?? null;

                        return [
                            'segment' => $segment['segment'],
                            'description' => $event['tracking_description']
                                ?? $event['description']
                                ?? 'Status pengembalian diperbarui',
                            'occurred_at' => $timestamp
                                ? Carbon::createFromTimestamp((int) $timestamp)->toIso8601String()
                                : null,
                        ];
                    });
                })->sortByDesc(function ($event) {
                    return $event['occurred_at'] ?? '';
                })->values();

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
                    'effective_event_at' => $effectiveEventAt->format('d M Y, H:i'),
                    'uses_logistics_failure_date' => $platformCreatedAt
                        && ! $effectiveEventAt->equalTo($platformCreatedAt),
                    'reverse_tracking_number' => $reverseTracking['tracking_number'] ?? null,
                    'rts_tracking_number' => $reverseTracking['rts_tracking_number'] ?? null,
                    'reverse_logistics_status' => $reverseTracking['reverse_logistics_status']
                        ?? $reverseTracking['post_return_logistics_status']
                        ?? null,
                    'reverse_tracking_history' => $reverseTrackingHistory,
                    'items' => $ret->items->map(function ($item) {
                        return [
                            'product_name' => $item->product_name,
                            'quantity' => $item->quantity,
                        ];
                    }),
                ];
            }),
            'packages' => $order->packages->map(function ($pkg) use ($logisticsStatusNormalizer) {
                return [
                    'package_id' => $pkg->package_id,
                    'tracking_number' => $pkg->tracking_number,
                    'logistics_status' => $pkg->logistics_status,
                    'normalized_logistics_status' => $pkg->normalized_logistics_status,
                    'failed_at' => $pkg->failed_at?->toIso8601String(),
                    'history' => $logisticsStatusNormalizer->history($pkg->raw_data ?? []),
                    'raw_data' => $pkg->raw_data,
                ];
            }),
        ];

        return response()->json(['order' => $orderData]);
    }
}
