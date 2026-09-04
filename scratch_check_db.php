<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$orders = \App\Models\Order::where('platform', 'Shopee')->get();
echo "Total Shopee orders in DB: " . $orders->count() . "\n";
foreach($orders as $o) {
    echo $o->order_sn . " - Products: " . $o->orderProducts()->count() . "\n";
}
