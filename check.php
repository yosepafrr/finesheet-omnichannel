<?php
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$variants = \App\Models\VariantProduct::whereNotNull("variant_name")->take(5)->get(["id", "variant_name", "model_name", "tier_index"]);
echo "Variants in DB:\n";
print_r($variants->toArray());

