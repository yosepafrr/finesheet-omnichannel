<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopeeBoostJob;
use App\Models\Product;
use App\Models\ShopeeBoostSetting;
use App\Models\ShopeeProductBoost;
use App\Models\Store;
use App\Services\ShopeeBoostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopeeBoostController extends Controller
{
    private function ownedStore(Request $request, int $storeId): Store
    {
        return $request->user()->stores()->where('platform', 'Shopee')->findOrFail($storeId);
    }

    public function index(Request $request)
    {
        $stores = $request->user()->stores()->where('platform', 'Shopee')->get(['id', 'store_name']);
        $ids = $stores->pluck('id');
        $settings = ShopeeBoostSetting::whereIn('store_id', $ids)->get()->keyBy('store_id');
        $entries = ShopeeProductBoost::whereIn('store_id', $ids)
            ->where(fn ($q) => $q->where('selected', true)->orWhere('active_until', '>', now()))->orderBy('id')->get();
        $products = Product::whereIn('store_id', $ids)->whereIn('product_id', $entries->pluck('item_id'))
            ->get(['store_id', 'product_id', 'product_name', 'image', 'product_sku'])
            ->keyBy(fn ($p) => $p->store_id.':'.$p->product_id);

        return response()->json(['stores' => $stores->map(function ($store) use ($settings, $entries, $products) {
            $setting = $settings->get($store->id);
            $rows = $entries->where('store_id', $store->id)->map(function ($entry) use ($products, $setting) {
                $product = $products->get($entry->store_id.':'.$entry->item_id);
                $active = $entry->active_until?->isFuture() ?? false;

                return [
                    'item_id' => $entry->item_id,
                    'name' => $product?->product_name ?? 'Produk #'.$entry->item_id,
                    'image' => $product?->image,
                    'sku' => $product?->product_sku,
                    'selected' => $entry->selected,
                    'active_until' => $entry->active_until?->toIso8601String(),
                    'last_boosted_at' => $entry->last_boosted_at?->toIso8601String(),
                    'observed_at' => $entry->observed_at?->toIso8601String(),
                    'boost_count' => $entry->boost_count,
                    'error' => $entry->error,
                    'status' => $active ? 'active' : ($entry->error ? 'failed' : ($setting?->enabled ? 'waiting' : 'paused')),
                ];
            })->values();

            return [
                'id' => $store->id, 'name' => $store->store_name,
                'enabled' => $setting?->enabled ?? false,
                'checked_at' => $setting?->checked_at?->toIso8601String(),
                'next_check_at' => $setting?->next_check_at?->toIso8601String(),
                'error' => $setting?->error,
                'active_count' => $rows->where('status', 'active')->count(),
                'selected_count' => $rows->where('selected', true)->count(),
                'items' => $rows,
            ];
        })->values()]);
    }

    public function products(Request $request, int $storeId)
    {
        $store = $this->ownedStore($request, $storeId);
        $request->validate(['search' => 'nullable|string|max:150']);
        $search = trim((string) $request->input('search'));
        $query = Product::where('store_id', $store->id)->where('product_status', 'NORMAL');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('product_name', 'like', '%'.$search.'%')
                ->orWhere('product_sku', 'like', '%'.$search.'%')->orWhereRaw('CAST(product_id AS TEXT) LIKE ?', ['%'.$search.'%']));
        }

        // Cast bigint IDs for PostgreSQL search; response IDs remain strings for JavaScript precision.
        return response()->json($query->orderBy('product_name')->paginate(20, ['product_id', 'product_name', 'image', 'product_sku'])
            ->through(fn ($p) => ['item_id' => (string) $p->product_id, 'name' => $p->product_name, 'image' => $p->image, 'sku' => $p->product_sku]));
    }

    public function update(Request $request, int $storeId)
    {
        $store = $this->ownedStore($request, $storeId);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'item_ids' => 'present|array|max:5',
            'item_ids.*' => ['required', 'string', 'distinct', 'regex:/^[1-9][0-9]{0,18}$/'],
        ]);
        if ($data['enabled'] && ! $data['item_ids']) {
            throw ValidationException::withMessages(['item_ids' => 'Pilih minimal satu produk untuk otomatisasi.']);
        }
        $lock = Cache::lock(ShopeeBoostService::lockKey($storeId), 240);
        abort_unless($lock->get(), 409, 'Toko sedang diperiksa. Coba simpan kembali sebentar lagi.');
        try {
            DB::transaction(function () use ($store, $data) {
                $valid = Product::where('store_id', $store->id)->where('product_status', 'NORMAL')
                    ->whereIn('product_id', $data['item_ids'])->pluck('product_id')->map(fn ($id) => (string) $id)->all();
                // Existing selections may be removed/paused even if their listing is now inactive.
                $existing = ShopeeProductBoost::where('store_id', $store->id)->where('selected', true)->pluck('item_id')->all();
                if (array_diff($data['item_ids'], array_merge($valid, $existing))) {
                    throw ValidationException::withMessages(['item_ids' => 'Produk harus aktif dan berasal dari toko ini.']);
                }
                ShopeeBoostSetting::updateOrCreate(['store_id' => $store->id], [
                    'enabled' => $data['enabled'], 'next_check_at' => now(),
                ]);
                ShopeeProductBoost::where('store_id', $store->id)->update(['selected' => false]);
                foreach ($data['item_ids'] as $id) {
                    ShopeeProductBoost::updateOrCreate(['store_id' => $store->id, 'item_id' => $id], ['selected' => true]);
                }
            });
        } finally {
            $lock->release();
        }
        SyncShopeeBoostJob::dispatch($storeId);

        return response()->json(['message' => 'Pengaturan disimpan. Pemeriksaan Shopee dimasukkan ke antrean.']);
    }

    public function refresh(Request $request, int $storeId)
    {
        $this->ownedStore($request, $storeId);
        ShopeeBoostSetting::firstOrCreate(['store_id' => $storeId]);
        SyncShopeeBoostJob::dispatch($storeId);

        return response()->json(['message' => 'Pemeriksaan slot dimasukkan ke antrean.'], 202);
    }
}
