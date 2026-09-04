<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleTiktokProductWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $shopId;
    protected $productId;

    public function __construct($shopId, $productId)
    {
        $this->shopId = $shopId;
        $this->productId = $productId;
    }

    public function handle()
    {
        Log::info("HandleTiktokProductWebhookJob started for Product: {$this->productId}");

        // Di database, tiktok_shop_id disimpan sebagai Cipher (ROW_...) di dalam kolom shopee_shop_id.
        // Webhook TikTok mengirimkan shop_id berupa angka (numeric).
        // Sehingga pencarian strict menggunakan $this->shopId akan gagal.
        // Solusi sementara: Ambil toko TikTok pertama milik user, ATAU cari berdasarkan platform.
        $store = \App\Models\Store::where('platform', 'Tiktokshop')
                      ->where(function($query) {
                          $query->where('shopee_shop_id', $this->shopId)
                                ->orWhere('shopee_shop_id', 'LIKE', 'ROW_%');
                      })
                      ->first();

        if (!$store) {
            Log::warning("TikTok Store not found for shop_id: {$this->shopId}");
            return;
        }

        try {
            $tiktok = new \App\Services\TiktokService();
            $tiktok->ensureValidToken($store);

            $detailResponse = $tiktok->getProductDetail($store, $this->productId);
            $item = $detailResponse['data'] ?? null;

            if (!$item) {
                Log::warning("No product details found from TikTok for {$this->productId}");
                return;
            }

            $productName = $item['product_name'] ?? $item['title'] ?? 'Unknown';
            $imageUrl = $item['images'][0]['url_list'][0] ?? $item['main_images'][0]['urls'][0] ?? null;
            $stock = array_sum(array_column($item['skus'] ?? [], 'inventory.0.quantity')) ?? 0;

            $savedItem = \App\Models\Product::updateOrCreate(
                [
                    'product_id' => $item['id'] ?? $this->productId,
                    'store_id' => $store->id,
                ],
                [
                    'platform' => 'Tiktokshop',
                    'product_name' => $productName,
                    'image' => $imageUrl,
                    'price' => $item['skus'][0]['price']['tax_exclusive_price'] ?? $item['skus'][0]['price']['sale_price'] ?? 0,
                    'product_sku' => $item['skus'][0]['seller_sku'] ?? null,
                    'product_status' => $item['status'] ?? null,
                    'stock' => $stock,
                    'category' => $item['category_id'] ?? $item['category_list'][0]['id'] ?? null,
                ]
            );

            if (!empty($item['skus'])) {
                $tierMap = [];
                foreach ($item['skus'] as $s) {
                    foreach ($s['sales_attributes'] ?? [] as $i => $attr) {
                        $attrName = $attr['attribute_name'] ?? $i;
                        $valName = $attr['value_name'] ?? '';
                        if (!isset($tierMap[$attrName])) $tierMap[$attrName] = [];
                        if (!in_array($valName, $tierMap[$attrName])) $tierMap[$attrName][] = $valName;
                    }
                }

                foreach ($item['skus'] as $sku) {
                    $variantOptions = [];
                    $tierIndex = [];
                    $variantImage = null;
                    foreach ($sku['sales_attributes'] ?? [] as $i => $attr) {
                        $valName = $attr['value_name'] ?? '';
                        $attrName = $attr['attribute_name'] ?? $i;
                        $variantOptions[] = $valName;
                        $tierIndex[] = array_search($valName, $tierMap[$attrName]);
                        if (!empty($attr['sku_img']['urls'][0])) {
                            $variantImage = $attr['sku_img']['urls'][0];
                        }
                    }
                    $variantName = implode(' - ', array_filter($variantOptions));
                    $variantStock = $sku['inventory'][0]['quantity'] ?? $sku['stock_infos'][0]['available_stock'] ?? 0;
                    $variantPrice = $sku['price']['tax_exclusive_price'] ?? $sku['price']['sale_price'] ?? 0;

                    \App\Models\VariantProduct::updateOrCreate(
                        [
                            'product_id' => $savedItem->id,
                            'model_id' => $sku['id'],
                        ],
                        [
                            'model_name' => $variantName ?: ($sku['seller_sku'] ?: 'Default'),
                            'model_sku' => $sku['seller_sku'] ?? null,
                            'stock' => $variantStock,
                            'price' => $variantPrice,
                            'status' => 'NORMAL',
                            'variant_name' => $variantName,
                            'variant_options' => $variantOptions,
                            'tier_index' => $tierIndex,
                            'variant_image' => $variantImage,
                        ]
                    );
                }
            }

            Log::info("HandleTiktokProductWebhookJob completed for Product: {$this->productId}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleTiktokProductWebhookJob for {$this->productId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
