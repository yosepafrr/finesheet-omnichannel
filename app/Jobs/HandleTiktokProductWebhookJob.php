<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\VariantProduct;
use App\Services\TiktokService;
use App\Services\TiktokStoreResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HandleTiktokProductWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public $uniqueFor = 300;

    protected $shopId;

    protected $productId;

    public function __construct($shopId, $productId)
    {
        $this->shopId = $shopId;
        $this->productId = $productId;
    }

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->productId;
    }

    public function handle(TiktokService $tiktok, TiktokStoreResolver $storeResolver)
    {
        Log::info("HandleTiktokProductWebhookJob started for Product: {$this->productId}");

        $store = $storeResolver->resolve($this->shopId);

        if (! $store) {
            Log::warning("TikTok Store not found for shop_id: {$this->shopId}");

            return;
        }

        try {
            $tiktok->ensureValidToken($store);

            $detailResponse = $tiktok->getProductDetail($store, $this->productId);
            $item = $detailResponse['data'] ?? null;

            if (! $item) {
                Log::warning("No product details found from TikTok for {$this->productId}");

                return;
            }

            $productName = $item['product_name'] ?? $item['title'] ?? 'Unknown';
            $imageUrl = $item['images'][0]['url_list'][0] ?? $item['main_images'][0]['urls'][0] ?? null;
            $stock = array_sum(array_column($item['skus'] ?? [], 'inventory.0.quantity')) ?? 0;

            $savedItem = Product::updateOrCreate(
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

            if (! empty($item['skus'])) {
                $syncedSkuIds = [];
                $tierMap = [];
                foreach ($item['skus'] as $s) {
                    foreach ($s['sales_attributes'] ?? [] as $i => $attr) {
                        $attrName = $attr['attribute_name'] ?? $i;
                        $valName = $attr['value_name'] ?? '';
                        if (! isset($tierMap[$attrName])) {
                            $tierMap[$attrName] = [];
                        }
                        if (! in_array($valName, $tierMap[$attrName])) {
                            $tierMap[$attrName][] = $valName;
                        }
                    }
                }

                foreach ($item['skus'] as $sku) {
                    $syncedSkuIds[] = $sku['id'];
                    $variantOptions = [];
                    $tierIndex = [];
                    $variantImage = null;
                    foreach ($sku['sales_attributes'] ?? [] as $i => $attr) {
                        $valName = $attr['value_name'] ?? '';
                        $attrName = $attr['attribute_name'] ?? $i;
                        $variantOptions[] = $valName;
                        $tierIndex[] = array_search($valName, $tierMap[$attrName]);
                        if (! empty($attr['sku_img']['urls'][0])) {
                            $variantImage = $attr['sku_img']['urls'][0];
                        }
                    }
                    $variantName = implode(' - ', array_filter($variantOptions));
                    $variantStock = $sku['inventory'][0]['quantity'] ?? $sku['stock_infos'][0]['available_stock'] ?? 0;
                    $variantPrice = $sku['price']['tax_exclusive_price'] ?? $sku['price']['sale_price'] ?? 0;

                    VariantProduct::updateOrCreate(
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

                $savedItem->variantProducts()
                    ->whereNotIn('model_id', $syncedSkuIds)
                    ->delete();
            } else {
                $savedItem->variantProducts()->delete();
            }

            RefreshMasterSkuLinksForProductJob::dispatch($savedItem->id)->onQueue('products');
            Log::info("HandleTiktokProductWebhookJob completed for Product: {$this->productId}");
        } catch (\Throwable $e) {
            Log::error("Error processing HandleTiktokProductWebhookJob for {$this->productId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
