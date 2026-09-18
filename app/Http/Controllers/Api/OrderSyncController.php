<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncTiktokOrderJob;
use App\Models\Store;
use App\Services\OrderSyncStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderSyncController extends Controller
{
    public function status(Request $request, OrderSyncStatusService $statusService): JsonResponse
    {
        $stores = $request->user()->stores()->get(['id', 'store_name', 'platform']);
        $syncs = $statusService->forStores($stores);

        return response()->json([
            'syncs' => $syncs,
            'has_active' => count($syncs) > 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer'],
        ]);

        $stores = $request->user()->stores()
            ->when(
                $validated['store_id'] ?? null,
                fn ($query, $storeId) => $query->whereKey($storeId)
            )
            ->get();

        if (($validated['store_id'] ?? null) && $stores->isEmpty()) {
            return response()->json(['message' => 'Toko tidak ditemukan.'], 404);
        }

        $dispatched = 0;
        foreach ($stores as $store) {
            if ($this->dispatchForStore($store)) {
                $dispatched++;
            }
        }

        return response()->json([
            'message' => $dispatched > 0
                ? 'Sinkronisasi pesanan berjalan di latar belakang.'
                : 'Tidak ada toko yang dapat disinkronkan.',
            'stores_queued' => $dispatched,
        ], 202);
    }

    private function dispatchForStore(Store $store): bool
    {
        if ($store->platform === 'Shopee') {
            SyncShopeeOrderJob::dispatch($store->id, 14, true, 'manual')->onQueue('orders');
            return true;
        }

        if ($store->platform === 'Tiktokshop') {
            SyncTiktokOrderJob::dispatch($store->id, 14, null, null, true, 'manual')->onQueue('orders');
            return true;
        }

        return false;
    }
}
