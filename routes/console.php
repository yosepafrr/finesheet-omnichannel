<?php

use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncShopeeProductJob;
use App\Jobs\SyncStoreLogisticsJob;
use App\Jobs\SyncTiktokOrderJob;
use App\Jobs\SyncTiktokProductJob;
use App\Jobs\SyncTiktokUnsettledJob;
use App\Models\Store;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Store::query()
        ->whereIn('platform', ['Shopee', 'Tiktokshop'])
        ->select(['id', 'platform'])
        ->chunkById(100, function ($stores) {
            foreach ($stores as $store) {
                if ($store->platform === 'Shopee') {
                    SyncShopeeOrderJob::dispatch($store->id, 14, false, 'scheduled')->onQueue('orders-low');
                } else {
                    SyncTiktokOrderJob::dispatch($store->id, 14, null, null, false, 'scheduled')->onQueue('orders-low');
                }
            }
        });
})->cron('0,30 * * * *')
    ->name('dispatch-scheduled-order-sync')
    ->withoutOverlapping(25);

Schedule::call(function () {
    dispatch(new SyncShopeeProductJob)->onQueue('products');
    dispatch(new SyncTiktokProductJob)->onQueue('products');
})->hourly();

Schedule::call(function () {
    Store::query()
        ->whereIn('platform', ['Shopee', 'Tiktokshop'])
        ->select(['id'])
        ->chunkById(100, function ($stores) {
            foreach ($stores as $store) {
                SyncStoreLogisticsJob::dispatch($store->id, false, 'scheduled')->onQueue('logistics');
            }
        });
})->cron('10,40 * * * *')
    ->name('dispatch-scheduled-logistics-sync')
    ->withoutOverlapping(25);
Schedule::command('tokens:refresh')
    ->everyFifteenMinutes()
    ->name('refresh-marketplace-tokens')
    ->withoutOverlapping(10);

Schedule::call(function () {
    Store::query()
        ->where('platform', 'Tiktokshop')
        ->select('id')
        ->chunkById(100, function ($stores) {
            foreach ($stores as $store) {
                SyncTiktokUnsettledJob::dispatch($store->id)->onQueue('orders-low');
            }
        });
})->everyFifteenMinutes()
    ->name('dispatch-tiktok-unsettled-sync')
    ->withoutOverlapping(10);
