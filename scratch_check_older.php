<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$store = \App\Models\Store::where('platform', 'Shopee')->first();
$shopee = app(\App\Services\ShopeeService::class);
$accessToken = $shopee->ensureValidToken($store);

$timestamp = time();
$shopId = (string) $store->shopee_shop_id;

$orders = [];
// check last 12 months in 15 days intervals
$start = \Carbon\Carbon::now()->subMonths(12);
while ($start < \Carbon\Carbon::now()) {
    $end = $start->copy()->addDays(15);
    if ($end > \Carbon\Carbon::now()) {
        $end = \Carbon\Carbon::now();
    }
    
    $res = $shopee->getOrderList($accessToken, $shopId, $start->timestamp, $end->timestamp);
    if (isset($res['response']['order_list']) && is_array($res['response']['order_list'])) {
        foreach($res['response']['order_list'] as $o) {
            $orders[] = $o['order_sn'];
        }
    }
    $start->addDays(15);
}

echo "Found " . count($orders) . " orders in last 12 months: \n";
echo implode(", ", $orders) . "\n";
