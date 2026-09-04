<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$store = \App\Models\Store::where('platform', 'Shopee')->first();
$shopee = app(\App\Services\ShopeeService::class);
$accessToken = $shopee->ensureValidToken($store);

$now = \Carbon\Carbon::now('UTC');
$threeMonthsAgo = $now->copy()->subMonths(3)->startOfDay(); // 3 bulan lalu
$intervalDays = 15;

while ($threeMonthsAgo < $now) {
    $startTime = $threeMonthsAgo->copy();
    $endTime = $threeMonthsAgo->copy()->addDays($intervalDays);

    if ($endTime > $now) {
        $endTime = $now;
    }

    $orders = $shopee->getOrderList(
        $accessToken,
        (string) $store->shopee_shop_id,
        $startTime->timestamp,
        $endTime->timestamp
    );

    echo "Start: {$startTime->toDateString()} | End: {$endTime->toDateString()} \n";
    if (isset($orders['error']) && !empty($orders['error'])) {
        echo "ERROR: " . json_encode($orders) . "\n";
    } else {
        echo "Orders found: " . count($orders['response']['order_list'] ?? []) . "\n";
    }

    $threeMonthsAgo->addDays($intervalDays);
}
