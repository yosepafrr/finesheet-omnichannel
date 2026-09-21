<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\MasterCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMasterCatalogProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(public int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(MasterCatalogService $catalog): void
    {
        $product = Product::find($this->productId);
        if ($product) {
            $catalog->syncProduct($product);
        }
    }
}
