<?php

namespace App\Console\Commands;

use App\Models\MasterProductVariant;
use App\Services\MasterSkuSyncService;
use Illuminate\Console\Command;

class SyncMasterProductsCommand extends Command
{
    protected $signature = 'master-products:sync {--user= : Batasi sinkronisasi ke satu user ID}';

    protected $description = 'Tautkan ulang SKU master manual dengan listing marketplace';

    public function handle(MasterSkuSyncService $syncService): int
    {
        $query = MasterProductVariant::query()
            ->whereHas('masterProduct', fn ($productQuery) => $productQuery->where('source', 'manual'))
            ->when($this->option('user'), fn ($variantQuery, $userId) => $variantQuery->where('user_id', $userId))
            ->orderBy('id');
        $total = $query->count();
        $this->info("Memproses {$total} SKU master manual.");

        $processed = 0;
        $query->chunkById(100, function ($variants) use ($syncService, $total, &$processed) {
            foreach ($variants as $variant) {
                $syncService->syncVariant($variant);
                $processed++;
                $this->output->write("\rTertaut: {$processed}/{$total}");
            }
        });

        $this->newLine();
        $this->info('Relasi SKU master selesai diperbarui.');

        return self::SUCCESS;
    }
}
