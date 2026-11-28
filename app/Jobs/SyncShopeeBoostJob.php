<?php

namespace App\Jobs;

use App\Services\ShopeeBoostService;
use App\Services\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShopeeBoostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $storeId)
    {
        $this->onQueue('products');
    }

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(ShopeeBoostService $boost, ShopeeService $api): void
    {
        $boost->run($this->storeId, $api);
    }
}
