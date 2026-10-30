<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\VariantProduct;
use App\Services\PayableSyncStatusService;
use App\Services\ProductHppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request, ProductHppService $hppService)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $pages = $this->paginationMap($request->input('pages', []));
        $limits = $this->paginationMap($request->input('limits', []));
        $search = trim((string) $request->input('search', ''));

        $serializeProduct = function (Product $product) use ($hppService) {
            $hpp = $hppService->productDetails($product);

            return [
                'id' => $product->id,
                'platform_product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'product_sku' => $product->product_sku,
                'stock' => $product->stock,
                'price' => $product->price,
                'hpp' => $hpp['hpp'],
                'hpp_source' => $hpp['source'],
                'master_product_variant_id' => $hpp['master_variant_id'],
                'supplier_id' => $product->supplier_id,
                'supplier_name' => $product->supplier?->name,
                'image' => $product->image,
                'variants' => $product->variantProducts->map(function ($variant) use ($hppService) {
                    $hpp = $hppService->variantDetails($variant);

                    return [
                        'id' => $variant->id,
                        'platform_variant_id' => $variant->model_id,
                        'model_name' => $variant->model_name,
                        'variant_name' => $variant->variant_name ?? $variant->model_name,
                        'variant_image' => $variant->variant_image,
                        'tier_index' => $variant->tier_index,
                        'variant_options' => $variant->variant_options,
                        'model_sku' => $variant->model_sku,
                        'stock' => $variant->stock,
                        'price' => $variant->price,
                        'hpp' => $hpp['hpp'],
                        'hpp_source' => $hpp['source'],
                        'master_product_variant_id' => $hpp['master_variant_id'],
                    ];
                }),
            ];
        };

        return response()->json([
            'stores' => $stores->map(function ($store) use ($pages, $limits, $search, $serializeProduct) {
                $page = max((int) ($pages[(string) $store->id] ?? 1), 1);
                $perPage = min(max((int) ($limits[(string) $store->id] ?? 30), 1), 100);

                $query = Product::query()
                    ->where('store_id', $store->id)
                    ->when($search !== '', function ($productQuery) use ($search) {
                        $searchPattern = '%'.mb_strtolower($search).'%';
                        $productQuery->where(function ($searchQuery) use ($searchPattern) {
                            $searchQuery
                                ->whereRaw('LOWER(product_name) LIKE ?', [$searchPattern])
                                ->orWhereRaw('LOWER(product_sku) LIKE ?', [$searchPattern])
                                ->orWhereHas('variantProducts', function ($variantQuery) use ($searchPattern) {
                                    $variantQuery
                                        ->whereRaw('LOWER(model_sku) LIKE ?', [$searchPattern])
                                        ->orWhereRaw('LOWER(model_name) LIKE ?', [$searchPattern]);
                                });
                        });
                    })
                    ->with([
                        'store',
                        'supplier',
                        'skuSyncMember.group.masterVariant',
                        'variantProducts' => fn ($variantQuery) => $variantQuery->with([
                            'product.store',
                            'skuSyncMember.group.masterVariant',
                        ]),
                    ])
                    ->latest();

                $productCount = (clone $query)->count();
                $lastPage = max((int) ceil($productCount / $perPage), 1);
                $page = min($page, $lastPage);
                $storeProducts = $query
                    ->forPage($page, $perPage)
                    ->get();

                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                    'product_count' => $productCount,
                    'pagination' => [
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'per_page' => $perPage,
                        'total' => $productCount,
                        'from' => $productCount === 0 ? null : (($page - 1) * $perPage) + 1,
                        'to' => $productCount === 0 ? null : min($page * $perPage, $productCount),
                    ],
                    'products' => $storeProducts->map($serializeProduct),
                ];
            }),
            'suppliers' => Supplier::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    private function paginationMap(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    public function updateSuppliers(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('user_id', $user->id),
            ],
        ]);
        $storeIds = $user->stores()->pluck('id');
        $products = Product::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('id', $data['product_ids'])
            ->with('variantProducts')
            ->get();

        if ($products->count() !== count($data['product_ids'])) {
            return response()->json([
                'message' => 'Sebagian produk marketplace tidak ditemukan atau tidak dapat diakses.',
            ], 422);
        }

        $supplierId = $data['supplier_id'] ?? null;
        DB::transaction(function () use ($products, $supplierId, $user) {
            foreach ($products as $product) {
                if ($supplierId === null) {
                    SupplierProductMapping::query()
                        ->where('user_id', $user->id)
                        ->where('product_id', $product->id)
                        ->delete();
                    $product->updateQuietly(['supplier_id' => null]);

                    continue;
                }

                $product->updateQuietly(['supplier_id' => $supplierId]);
                $skus = collect([$product->product_sku])
                    ->merge($product->variantProducts->pluck('model_sku'))
                    ->map(fn ($sku) => trim((string) $sku))
                    ->filter(fn (string $sku) => $sku !== '' && $sku !== '0')
                    ->unique(fn (string $sku) => mb_strtolower($sku));

                if ($skus->isEmpty()) {
                    SupplierProductMapping::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'platform_product_id' => (string) $product->product_id,
                        ],
                        ['supplier_id' => $supplierId]
                    );

                    continue;
                }

                foreach ($skus as $sku) {
                    SupplierProductMapping::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'sku' => $sku,
                        ],
                        [
                            'supplier_id' => $supplierId,
                            'platform_product_id' => (string) $product->product_id,
                        ]
                    );
                }
            }
        });

        $this->queuePayableSync($user->id);

        return response()->json([
            'message' => $supplierId
                ? "Supplier {$products->count()} produk marketplace berhasil diperbarui."
                : "Supplier {$products->count()} produk marketplace berhasil dilepas.",
            'updated_count' => $products->count(),
            'supplier_id' => $supplierId,
        ]);
    }

    public function updateItemHpp(Request $request, $id, ProductHppService $hppService)
    {
        $request->validate(['hpp' => 'required|numeric|min:0']);

        $product = Product::findOrFail($id);

        // Verify ownership
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        if (! $storeIds->contains($product->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $result = $hppService->updateProduct($product, (float) $request->hpp);
        $this->queuePayableSync($user->id);

        return response()->json([
            'message' => $result['source'] === 'master'
                ? 'HPP master berhasil diperbarui.'
                : 'HPP produk marketplace berhasil diperbarui.',
            'hpp' => $result['hpp'],
            'hpp_source' => $result['source'],
            'master_product_variant_id' => $result['master_variant_id'],
        ]);
    }

    public function updateVariantHpp(Request $request, $id, ProductHppService $hppService)
    {
        $request->validate(['hpp' => 'required|numeric|min:0']);

        $variant = VariantProduct::findOrFail($id);

        // Verify ownership through product -> store
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        $product = Product::find($variant->product_id);

        if (! $product || ! $storeIds->contains($product->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $result = $hppService->updateVariant($variant, (float) $request->hpp);
        $this->queuePayableSync($user->id);

        return response()->json([
            'message' => $result['source'] === 'master'
                ? 'HPP master berhasil diperbarui.'
                : 'HPP varian marketplace berhasil diperbarui.',
            'hpp' => $result['hpp'],
            'hpp_source' => $result['source'],
            'master_product_variant_id' => $result['master_variant_id'],
        ]);
    }

    public function updateBulkVariantHpp(Request $request, ProductHppService $hppService)
    {
        $request->validate([
            'variant_ids' => 'required|array',
            'variant_ids.*' => 'integer',
            'hpp' => 'required|numeric|min:0',
        ]);

        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');

        $variants = VariantProduct::whereIn('id', $request->variant_ids)->get();

        $updatedCount = 0;
        foreach ($variants as $variant) {
            $product = Product::find($variant->product_id);
            if ($product && $storeIds->contains($product->store_id)) {
                $hppService->updateVariant($variant, (float) $request->hpp);
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            $this->queuePayableSync($user->id);
        }

        return response()->json(['message' => "$updatedCount variants updated", 'hpp' => $request->hpp]);
    }

    private function queuePayableSync(int $userId): void
    {
        $payableStart = Supplier::query()
            ->where('user_id', $userId)
            ->whereNotNull('first_period_start')
            ->min('first_period_start');

        if ($payableStart) {
            app(PayableSyncStatusService::class)->dispatch((string) $payableStart, $userId, 'product_update');
        }
    }
}
