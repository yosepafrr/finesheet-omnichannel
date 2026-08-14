<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Arr;
use App\Models\VariantProduct;
use App\Events\ProductCreated;
use Illuminate\Bus\Queueable;
use App\Services\ShopeeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class HandleShopeeProductWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $shopId;
    protected $itemId;

    public function __construct($shopId, $itemId)
    {
        $this->shopId = $shopId;
        $this->itemId = $itemId;
    }

    public function handle(ShopeeService $shopee)
    {
        Log::info("HandleShopeeProductWebhookJob started for Item: {$this->itemId}");

        $store = Store::where('platform', 'Shopee')
                      ->where('shopee_shop_id', $this->shopId)
                      ->first();

        if (!$store) {
            Log::warning("Shopee Store not found for shop_id: {$this->shopId}");
            return;
        }

        try {
            $shopee->ensureValidToken($store);

            $itemDetails = $shopee->getItemBaseInfo($store, [$this->itemId]);
            $itemVariants = $shopee->getItemsVariant($store, [$this->itemId]);

            if (empty($itemDetails)) {
                Log::warning("No item base info found from Shopee for item {$this->itemId}");
                return;
            }

            $item = $itemDetails[0];

            $product = Product::updateOrCreate(
                [
                    'product_id' => $item['item_id'],
                    'store_id' => $store->id,
                ],
                [
                    'product_name'  => $item['item_name'] ?? 'Unknown',
                    'image'      => $item['promotion_image']['image_url_list'][0] ?? null,
                    'price'      => $item['price_info'][0]['current_price'] ?? 0,
                    'product_sku'   => $item['item_sku'] ?? null,
                    'product_status' => $item['item_status'] ?? null,
                    'stock'      => $item['stock_info_v2']['summary_info']['total_available_stock'] ?? 0,
                    'category'   => $item['category_id'] ?? null,
                ]
            );

            if (!empty($itemVariants[$item['item_id']]['model'])) {
                foreach ($itemVariants[$item['item_id']]['model'] as $model) {
                    try {
                        $variantSaved = VariantProduct::updateOrCreate(
                            [
                                'product_id'  => $product->id, // id dari tabel products
                                'model_id' => Arr::get($model, 'model_id'),
                            ],
                            [
                                'model_name' => Arr::get($model, 'model_name'),
                                'model_sku'  => Arr::get($model, 'model_sku'),
                                'stock'      => Arr::get($model, 'stock_info_v2.summary_info.total_available_stock', 0),
                                'price'      => Arr::get($model, 'price_info.0.current_price', 0),
                                'status'     => Arr::get($model, 'model_status'),
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::error("Gagal simpan variant ke DB", [
                            'item_id'  => $item['item_id'],
                            'model_id' => $model['model_id'] ?? null,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            event(new ProductCreated($product));
            Log::info("HandleShopeeProductWebhookJob successfully completed for {$this->itemId}");

        } catch (\Throwable $e) {
            Log::error("Error processing HandleShopeeProductWebhookJob for {$this->itemId}", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
