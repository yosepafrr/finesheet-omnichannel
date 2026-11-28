<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopeeBoostSetting;
use App\Models\ShopeeProductBoost;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopeeBoostService
{
    public static function lockKey(int $storeId): string
    {
        return 'shopee-boost-store:'.$storeId;
    }

    public function run(int $storeId, ShopeeService $api): void
    {
        $lock = Cache::lock(self::lockKey($storeId), 240);
        if (! $lock->get()) {
            return;
        }
        try {
            $store = Store::where('platform', 'Shopee')->find($storeId);
            $setting = ShopeeBoostSetting::where('store_id', $storeId)->first();
            if (! $store || ! $setting) {
                return;
            }

            try {
                $this->reconcile($storeId, $api->getBoostedList($store));
                if ($setting->enabled) {
                    $active = ShopeeProductBoost::where('store_id', $storeId)->where('active_until', '>', now())->count();
                    $candidates = ShopeeProductBoost::where('store_id', $storeId)->where('selected', true)
                        ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '<=', now()))
                        ->orderBy('id')->get();
                    $eligible = [];
                    foreach ($candidates as $candidate) {
                        $product = Product::where('store_id', $storeId)->where('product_id', $candidate->item_id)->first();
                        if (! $product || $product->product_status !== 'NORMAL') {
                            $candidate->update(['error' => 'Produk tidak tersedia atau tidak aktif di katalog marketplace.']);

                            continue;
                        }
                        if (count($eligible) < max(0, 5 - $active)) {
                            $eligible[] = $candidate->item_id;
                        }
                    }
                    if ($eligible) {
                        // Persist a cooldown before the call: an interrupted request may already have succeeded remotely.
                        $setting->update(['next_check_at' => now()->addMinutes(15)]);
                        $result = $api->boostItems($store, $eligible);
                        $success = array_map('strval', $result['success_list']['item_id_list'] ?? []);
                        foreach ($eligible as $itemId) {
                            $entry = ShopeeProductBoost::where('store_id', $storeId)->where('item_id', $itemId)->firstOrFail();
                            if (in_array((string) $itemId, $success, true)) {
                                $entry->update(['last_boosted_at' => now(), 'active_until' => now()->addHours(4),
                                    'observed_at' => now(), 'boost_count' => $entry->boost_count + 1, 'error' => null]);
                            } else {
                                $failure = collect($result['failure_list'] ?? [])->first(fn ($row) => (string) ($row['item_id'] ?? '') === (string) $itemId);
                                $entry->update(['error' => mb_substr((string) ($failure['failed_reason'] ?? $failure['message'] ?? 'Shopee belum mengonfirmasi keberhasilan. Akan diperiksa kembali.'), 0, 1000)]);
                            }
                        }
                        // Re-read platform cooldowns rather than relying only on the four-hour fallback.
                        $this->reconcile($storeId, $api->getBoostedList($store));
                    }
                }
                $hasErrors = ShopeeProductBoost::where('store_id', $storeId)->where('selected', true)->whereNotNull('error')->exists();
                $setting->update(['checked_at' => now(), 'error' => null,
                    'next_check_at' => now()->addMinutes($hasErrors ? 15 : 5)]);
            } catch (\Throwable $e) {
                $setting->update(['error' => mb_substr($e->getMessage(), 0, 1500), 'next_check_at' => now()->addMinutes(15)]);
                report($e);
            }
        } finally {
            $lock->release();
        }
    }

    private function reconcile(int $storeId, array $data): void
    {
        if (! is_array($data['item_list'] ?? null)) {
            throw new RuntimeException('Daftar slot Shopee tidak valid; proses boost ditunda.');
        }
        $observed = now();
        $items = [];
        foreach ($data['item_list'] as $item) {
            if (! is_array($item) || ! preg_match('/^[1-9][0-9]*$/', (string) ($item['item_id'] ?? ''))
                || ! is_numeric($item['cool_down_second'] ?? null) || $item['cool_down_second'] < 0) {
                throw new RuntimeException('Data cooldown Shopee tidak valid; proses boost ditunda.');
            }
            $items[(string) $item['item_id']] = (int) $item['cool_down_second'];
        }
        DB::transaction(function () use ($storeId, $items, $observed) {
            ShopeeProductBoost::where('store_id', $storeId)->update(['active_until' => null]);
            foreach ($items as $id => $seconds) {
                ShopeeProductBoost::updateOrCreate(['store_id' => $storeId, 'item_id' => (string) $id], [
                    'active_until' => $seconds > 0 ? $observed->copy()->addSeconds($seconds) : null,
                    'observed_at' => $observed,
                    ...($seconds > 0 ? ['error' => null] : []),
                ]);
            }
        });
    }
}
