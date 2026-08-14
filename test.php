<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$job = new \App\Jobs\SyncShopeeProductJob();
$job->handle();
echo "Job executed synchronously.\n";

