<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\MasterCatalogService;
use Illuminate\Console\Command;

class SyncMasterProductsCommand extends Command
{
    protected $signature = 'master-products:sync {--user= : Batasi sinkronisasi ke satu user ID}';

    protected $description = 'Bangun ulang katalog master dari seluruh produk toko terotorisasi';

    public function handle(MasterCatalogService $catalog): int
    {
        $query = Store::query()->orderBy('id');
        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $stores = $query->get();
        $this->info("Memproses {$stores->count()} toko.");

        foreach ($stores as $store) {
            $count = $catalog->syncStore($store);
            $this->line("{$store->store_name}: {$count} produk");
        }

        $this->info('Katalog master selesai diselaraskan.');

        return self::SUCCESS;
    }
}
