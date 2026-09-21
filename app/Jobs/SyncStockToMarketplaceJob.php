<?php

namespace App\Jobs;

use App\Models\SkuSyncMember;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncStockToMarketplaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60];

    public int $memberId;

    public int $stock;

    /**
     * Create a new job instance.
     */
    public function __construct(int $memberId, int $stock)
    {
        $this->memberId = $memberId;
        $this->stock = $stock;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("stock-sync-member:{$this->memberId}"))
                ->releaseAfter(5)
                ->expireAfter(90),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(ShopeeService $shopeeService, TiktokService $tiktokService): void
    {
        $member = SkuSyncMember::with(['store', 'group', 'product', 'variant'])
            ->find($this->memberId);

        if (! $member || ! $member->store) {
            Log::warning("SyncStockToMarketplaceJob: Member {$this->memberId} or store not found.");

            return;
        }

        $store = $member->store;
        $targetStock = (int) ($member->group?->master_stock ?? $this->stock);

        if ($targetStock !== $this->stock) {
            Log::info('SyncStockToMarketplaceJob: Using newer master stock', [
                'member_id' => $this->memberId,
                'queued_stock' => $this->stock,
                'current_stock' => $targetStock,
            ]);
        }

        try {
            $platform = strtolower(trim((string) $store->platform));

            if ($platform === 'shopee') {
                $modelId = $member->platform_variant_id ?? '0';
                $shopeeService->updateStock($store, $member->platform_product_id, $modelId, $targetStock);
            } elseif ($platform === 'tiktokshop') {
                $tiktokService->updateInventory($store, $member->platform_product_id, $member->platform_variant_id, $targetStock);
            } else {
                throw new RuntimeException("Platform {$store->platform} belum mendukung sinkronisasi stok.");
            }

            if ($member->variant_product_id && $member->variant) {
                $member->variant->update(['stock' => $targetStock]);
            } elseif ($member->product) {
                $member->product->update(['stock' => $targetStock]);
            }

            $member->update([
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'last_sync_error' => null,
            ]);

            $group = $member->group;
            if ($group && ! $group->members()->where('sync_status', '!=', 'synced')->exists()) {
                $group->update(['last_synced_at' => now()]);
            }

            Log::info('SyncStockToMarketplaceJob: Stock push completed', [
                'member_id' => $this->memberId,
                'store_id' => $store->id,
                'platform' => $store->platform,
                'product_id' => $member->platform_product_id,
                'variant_id' => $member->platform_variant_id,
                'stock' => $targetStock,
            ]);
        } catch (Throwable $e) {
            $member->update([
                'sync_status' => 'failed',
                'last_sync_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('SyncStockToMarketplaceJob: Stock push failed', [
                'member_id' => $this->memberId,
                'store_id' => $store->id,
                'platform' => $store->platform,
                'product_id' => $member->platform_product_id,
                'variant_id' => $member->platform_variant_id,
                'stock' => $targetStock,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
