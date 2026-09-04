<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $order = \App\Models\Order::first();
    if ($order) {
        echo "Firing OrderCreated for Order: {$order->order_sn}\n";
        event(new \App\Events\OrderCreated($order));
        echo "Event fired successfully!\n";
    } else {
        echo "No orders found to test.\n";
    }
} catch (\Throwable $e) {
    echo "ERROR during broadcast:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
