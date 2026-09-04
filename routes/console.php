<?php

use App\Jobs\SyncShopeeOrderJob;
use App\Jobs\SyncShopeeProductJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    dispatch(new SyncShopeeOrderJob())->onQueue('orders');
    dispatch(new \App\Jobs\SyncTiktokOrderJob())->onQueue('orders');
})->everyThirtyMinutes();

Schedule::call(function () {
    dispatch(new SyncShopeeProductJob())->onQueue('products');
    dispatch(new \App\Jobs\SyncTiktokProductJob())->onQueue('products');
})->hourly();
