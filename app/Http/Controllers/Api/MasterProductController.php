<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMasterProductStocksJob;
use App\Jobs\SyncMasterProductVariantsJob;
use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Services\MasterProductClusterService;
use App\Services\MasterSkuSyncService;
use App\Services\PayableSyncStatusService;
use App\Services\StockSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterProductController extends Controller
{
    public function index(Request $request, MasterProductClusterService $clusterService)
    {
        $perPage = min(max((int) $request->input('per_page', 20), 10), 100);
        $search = trim((string) $request->input('search', ''));
        $productClusters = $this->normalizedFilterValues($request, 'product_clusters', 'product_cluster');
        $variantClusters = $this->normalizedFilterValues($request, 'variant_clusters', 'variant_cluster');
        $supplierFilter = trim((string) $request->input('supplier_id', ''));
        $userId = $request->user()->id;

        $baseQuery = MasterProductVariant::query()
            ->where('user_id', $userId)
            ->whereHas('masterProduct', fn (Builder $query) => $query->where('source', 'manual'));

        $variants = $this->applyCatalogFilters(
            clone $baseQuery,
            $search,
            $productClusters,
            $variantClusters,
            $supplierFilter
        )
            ->with($this->variantRelations())
            ->orderBy('product_cluster_key')
            ->orderBy('sku')
            ->paginate($perPage);

        $allVariants = (clone $baseQuery)->get([
            'product_cluster_key',
            'variant_cluster_keys',
        ]);
        $productClusters = $allVariants
            ->pluck('product_cluster_key')
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $key) => [
                'key' => $key,
                'label' => $clusterService->fromSku($key)['product_label'] ?? mb_strtoupper($key),
                'count' => $count,
            ])
            ->values();
        $variantClusters = $allVariants
            ->flatMap(fn (MasterProductVariant $variant) => $variant->variant_cluster_keys ?? [])
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count, string $key) => [
                'key' => $key,
                'label' => mb_strtoupper($key),
                'count' => $count,
            ])
            ->values();

        return response()->json([
            'data' => collect($variants->items())
                ->map(fn (MasterProductVariant $variant) => $this->formatVariant($variant))
                ->values(),
            'meta' => [
                'current_page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
                'per_page' => $variants->perPage(),
                'total' => $variants->total(),
                'from' => $variants->firstItem(),
                'to' => $variants->lastItem(),
            ],
            'filters' => [
                'product_clusters' => $productClusters,
                'variant_clusters' => $variantClusters,
                'suppliers' => Supplier::query()
                    ->where('user_id', $userId)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
        ]);
    }

    public function selection(Request $request)
    {
        $productClusters = $this->normalizedFilterValues($request, 'product_clusters', 'product_cluster');
        $variantClusters = $this->normalizedFilterValues($request, 'variant_clusters', 'variant_cluster');
        if ($productClusters->isEmpty() && $variantClusters->isEmpty()) {
            throw ValidationException::withMessages([
                'clusters' => 'Pilih minimal satu cluster produk atau varian.',
            ]);
        }

        $query = MasterProductVariant::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('masterProduct', fn (Builder $builder) => $builder->where('source', 'manual'));
        $query = $this->applyCatalogFilters(
            $query,
            trim((string) $request->input('search', '')),
            $productClusters,
            $variantClusters,
            trim((string) $request->input('supplier_id', ''))
        );

        $count = (clone $query)->count();
        if ($count > 2000) {
            throw ValidationException::withMessages([
                'clusters' => 'Pilihan cluster terlalu besar. Persempit filter hingga maksimal 2.000 SKU.',
            ]);
        }

        $variants = $query->orderBy('id')->get(['id', 'supplier_id']);

        return response()->json([
            'variant_ids' => $variants->pluck('id')->values(),
            'count' => $variants->count(),
            'supplier_id' => $variants->isNotEmpty() && $variants->pluck('supplier_id')->unique()->count() === 1
                ? $variants->first()->supplier_id
                : null,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $product = MasterProduct::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'manual')
            ->with(['variants' => fn ($query) => $query
                ->orderBy('variant_name')
                ->with($this->variantRelations(withProduct: false))])
            ->findOrFail($id);

        return response()->json($this->formatProduct($product));
    }

    public function store(
        Request $request,
        MasterSkuSyncService $skuSync
    ) {
        $data = $this->validateProductPayload($request);
        $user = $request->user();

        $product = DB::transaction(function () use ($data, $user) {
            $product = MasterProduct::create([
                ...$this->productAttributes($data),
                'user_id' => $user->id,
                'source' => 'manual',
            ]);

            foreach ($data['variants'] as $variantData) {
                $product->variants()->create($this->variantAttributes($variantData, $user->id));
            }

            return $product;
        });

        $product->variants->each(fn (MasterProductVariant $variant) => $skuSync->syncVariant($variant));
        if ($product->variants->contains(fn (MasterProductVariant $variant) => $variant->supplier_id !== null)) {
            $this->queuePayableSync($user->id);
        }

        return response()->json(
            $this->formatProduct($product->fresh([
                'variants' => fn ($query) => $query->with($this->variantRelations(withProduct: false)),
            ])),
            201
        );
    }

    public function bulkStore(Request $request, MasterSkuSyncService $skuSync)
    {
        $data = $request->validate([
            'skus' => ['required', 'array', 'min:1', 'max:2000'],
            'skus.*' => ['required', 'string', 'max:120', 'not_in:0', 'distinct:ignore_case'],
        ]);
        $user = $request->user();
        $requestedSkus = collect($data['skus'])
            ->map(fn ($sku) => trim((string) $sku))
            ->mapWithKeys(fn (string $sku) => [mb_strtolower($sku) => $sku]);
        $detections = $skuSync->detectUnlinkedSkus($user->id)
            ->keyBy(fn (array $detection) => mb_strtolower(trim($detection['sku'])));
        $supplierBySku = SupplierProductMapping::query()
            ->where('user_id', $user->id)
            ->whereNotNull('sku')
            ->get(['sku', 'supplier_id'])
            ->mapWithKeys(fn (SupplierProductMapping $mapping) => [
                mb_strtolower(trim((string) $mapping->sku)) => $mapping->supplier_id,
            ]);

        $result = DB::transaction(function () use ($detections, $requestedSkus, $supplierBySku, $user) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $existingSkus = MasterProductVariant::query()
                ->where('user_id', $user->id)
                ->whereNotNull('sku')
                ->pluck('sku')
                ->map(fn ($sku) => mb_strtolower(trim((string) $sku)))
                ->flip();
            $createdVariantIds = [];
            $createdSkus = [];
            $skippedSkus = [];

            foreach ($requestedSkus as $normalizedSku => $requestedSku) {
                $detection = $detections->get($normalizedSku);
                if (! $detection || $existingSkus->has($normalizedSku)) {
                    $skippedSkus[] = $requestedSku;

                    continue;
                }

                $firstListing = collect($detection['items'] ?? [])->first() ?? [];
                $sku = trim((string) $detection['sku']);
                $name = trim((string) ($firstListing['product_name'] ?? ''));
                $variantName = trim((string) ($firstListing['variant_name'] ?? ''));

                $product = MasterProduct::create([
                    'user_id' => $user->id,
                    'name' => mb_substr($name !== '' ? $name : "Master Produk {$sku}", 0, 255),
                    'brand' => null,
                    'category' => null,
                    'description' => null,
                    'image' => null,
                    'status' => 'active',
                    'source' => 'manual',
                ]);
                $variant = $product->variants()->create([
                    'user_id' => $user->id,
                    'supplier_id' => $supplierBySku->get($normalizedSku),
                    'sku' => $sku,
                    'variant_name' => $variantName !== '' ? mb_substr($variantName, 0, 255) : null,
                    'barcode' => null,
                    'hpp' => 0,
                    'stock' => max(0, (int) ($firstListing['stock'] ?? 0)),
                    'is_active' => true,
                ]);

                $createdVariantIds[] = $variant->id;
                $createdSkus[] = $sku;
                $existingSkus->put($normalizedSku, true);
            }

            return compact('createdVariantIds', 'createdSkus', 'skippedSkus');
        });

        collect($result['createdVariantIds'])
            ->chunk(50)
            ->each(fn (Collection $ids) => SyncMasterProductVariantsJob::dispatch($ids->values()->all())
                ->onQueue('products'));

        $createdCount = count($result['createdSkus']);
        $skippedCount = count($result['skippedSkus']);
        $payableStart = $createdCount > 0
            ? Supplier::query()
                ->where('user_id', $user->id)
                ->whereNotNull('first_period_start')
                ->min('first_period_start')
            : null;

        if ($payableStart) {
            app(PayableSyncStatusService::class)->dispatch((string) $payableStart, $user->id, 'master_product_update');
        }

        return response()->json([
            'message' => $createdCount > 0
                ? "{$createdCount} SKU master berhasil ditambahkan. Koneksi toko diproses di latar belakang."
                : 'Tidak ada SKU baru yang ditambahkan.',
            'requested_count' => $requestedSkus->count(),
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'created_skus' => $result['createdSkus'],
            'skipped_skus' => $result['skippedSkus'],
            'sync_queued' => $createdCount > 0,
            'payable_sync_queued' => (bool) $payableStart,
        ], $createdCount > 0 ? 201 : 200);
    }

    public function update(
        Request $request,
        int $id,
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync
    ) {
        $product = $this->ownedProduct($request, $id);
        $data = $this->validateProductPayload($request, $product);
        $originalStocks = $product->variants()->pluck('stock', 'id');
        $originalHpps = $product->variants()->pluck('hpp', 'id');
        $originalSuppliers = $product->variants()->pluck('supplier_id', 'id');

        DB::transaction(function () use ($data, $product) {
            $product->update($this->productAttributes($data));
            $keptIds = [];

            foreach ($data['variants'] as $variantData) {
                $variant = ! empty($variantData['id'])
                    ? $product->variants()->findOrFail($variantData['id'])
                    : new MasterProductVariant(['master_product_id' => $product->id]);
                $variant->fill($this->variantAttributes($variantData, $product->user_id));
                $variant->save();
                $keptIds[] = $variant->id;
            }

            $removed = $product->variants()->whereNotIn('id', $keptIds)->get();
            $removed->each(fn (MasterProductVariant $variant) => $variant->syncGroup?->delete());
            $product->variants()->whereNotIn('id', $keptIds)->delete();
        });

        foreach ($product->fresh()->variants as $variant) {
            $group = $skuSync->syncVariant($variant);
            if ($group && $originalStocks->has($variant->id) && (int) $originalStocks[$variant->id] !== (int) $variant->stock) {
                $stockSync->setMasterStock($group, (int) $variant->stock);
            }
        }
        $freshVariants = $product->fresh()->variants;
        $payableSourceChanged = $freshVariants->contains(
            fn (MasterProductVariant $variant) => (int) ($originalSuppliers->get($variant->id) ?? 0) !== (int) ($variant->supplier_id ?? 0)
                || (float) ($originalHpps->get($variant->id) ?? 0) !== (float) $variant->hpp
        );
        if ($payableSourceChanged || $freshVariants->count() !== $originalHpps->count()) {
            $this->queuePayableSync($product->user_id);
        }

        return response()->json($this->formatProduct(
            $product->fresh(['variants' => fn ($query) => $query->with($this->variantRelations(withProduct: false))])
        ));
    }

    public function updateVariant(
        Request $request,
        int $productId,
        int $variantId,
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync
    ) {
        $product = $this->ownedProduct($request, $productId);
        $variant = $product->variants()->findOrFail($variantId);
        $data = $this->validateSingleVariantPayload($request, $variant);
        $stockChanged = (int) $variant->stock !== (int) $data['stock'];
        $payableSourceChanged = (int) ($variant->supplier_id ?? 0) !== (int) ($data['supplier_id'] ?? 0)
            || (float) $variant->hpp !== (float) $data['hpp'];

        DB::transaction(function () use ($product, $variant, $data) {
            $product->update($this->productAttributes($data));
            $variant->update($this->variantAttributes($data, $product->user_id));
        });

        $group = $skuSync->syncVariant($variant->fresh());
        if ($group && $stockChanged) {
            $stockSync->setMasterStock($group, (int) $data['stock']);
        }
        if ($payableSourceChanged) {
            $this->queuePayableSync($product->user_id);
        }

        return response()->json($this->formatVariant(
            $variant->fresh($this->variantRelations())
        ));
    }

    public function destroyVariant(Request $request, int $productId, int $variantId)
    {
        $product = $this->ownedProduct($request, $productId);
        $variant = $product->variants()->findOrFail($variantId);
        $affectsPayable = $variant->supplier_id !== null || (float) $variant->hpp > 0;

        DB::transaction(function () use ($product, $variant) {
            $variant->syncGroup?->delete();
            $variant->delete();

            if (! $product->variants()->exists()) {
                $product->delete();
            }
        });

        if ($affectsPayable) {
            $this->queuePayableSync($product->user_id);
        }

        return response()->json(['message' => 'SKU master berhasil dihapus.']);
    }

    public function pushVariant(
        Request $request,
        int $productId,
        int $variantId,
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync
    ) {
        $product = $this->ownedProduct($request, $productId);
        $variant = $product->variants()->findOrFail($variantId);
        $group = $skuSync->syncVariant($variant);
        $queued = $group ? $stockSync->setMasterStock($group, (int) $variant->stock) : 0;

        return response()->json([
            'message' => $queued > 0
                ? 'Sinkronisasi stok dijadwalkan.'
                : 'Belum ada listing marketplace dengan SKU yang sama.',
            'queued_count' => $queued,
        ], 202);
    }

    public function bulkUpdateVariants(
        Request $request,
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync
    ) {
        $data = $request->validate([
            'variant_ids' => ['nullable', 'array', 'min:1', 'max:2000'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
            'product_cluster_key' => ['nullable', 'string', 'max:160'],
            'variant_cluster_key' => ['nullable', 'string', 'max:120'],
            'supplier_filter' => ['nullable', 'string', 'max:40'],
            'field' => ['required', 'in:stock,hpp'],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        if ($data['field'] === 'stock' && filter_var($data['value'], FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages(['value' => 'Stok harus berupa bilangan bulat.']);
        }

        $variants = ! empty($data['variant_ids'])
            ? $this->ownedVariants($request, $data['variant_ids'])
            : $this->ownedVariantsByCluster($request, $data);
        $value = $data['field'] === 'stock' ? (int) $data['value'] : (float) $data['value'];
        $queued = 0;

        if ($data['field'] === 'hpp') {
            MasterProductVariant::query()
                ->whereIn('id', $variants->pluck('id'))
                ->update(['hpp' => $value]);
            $this->queuePayableSync($request->user()->id);
        } else {
            if ($variants->count() > 100) {
                MasterProductVariant::query()
                    ->whereIn('id', $variants->pluck('id'))
                    ->update(['stock' => $value]);
                $variants->pluck('id')->chunk(100)->each(
                    fn (Collection $ids) => SyncMasterProductStocksJob::dispatch($ids->all())->onQueue('products')
                );

                return response()->json([
                    'message' => "Stok {$variants->count()} SKU diperbarui. Sinkronisasi marketplace diproses di latar belakang.",
                    'updated_count' => $variants->count(),
                    'queued_count' => $variants->pluck('id')->chunk(100)->count(),
                ], 202);
            }

            foreach ($variants as $variant) {
                $variant->update(['stock' => $value]);
                $group = $skuSync->syncVariant($variant->fresh());
                if ($group) {
                    $queued += $stockSync->setMasterStock($group, $value);
                }
            }
        }

        return response()->json([
            'message' => $data['field'] === 'stock'
                ? "Stok {$variants->count()} SKU diperbarui. {$queued} pembaruan marketplace dijadwalkan."
                : "HPP {$variants->count()} SKU berhasil diperbarui.",
            'updated_count' => $variants->count(),
            'queued_count' => $queued,
        ]);
    }

    public function updateSuppliers(Request $request)
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id),
            ],
        ]);
        $variants = $this->ownedVariants($request, $data['variant_ids']);
        $supplierId = $data['supplier_id'] ?? null;

        MasterProductVariant::query()
            ->whereIn('id', $variants->pluck('id'))
            ->update(['supplier_id' => $supplierId]);

        $this->queuePayableSync($request->user()->id);

        return response()->json([
            'message' => $supplierId
                ? "Supplier {$variants->count()} SKU master berhasil diperbarui."
                : "Supplier {$variants->count()} SKU master berhasil dilepas.",
            'updated_count' => $variants->count(),
            'supplier_id' => $supplierId,
        ]);
    }

    public function bulkUpdateData(Request $request)
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.name' => ['sometimes', 'required', 'string', 'max:255'],
            'changes.brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'changes.category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'changes.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'changes.image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'changes.status' => ['sometimes', 'required', 'in:active,draft,archived'],
            'changes.variant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.barcode' => ['sometimes', 'nullable', 'string', 'max:120'],
            'changes.is_active' => ['sometimes', 'boolean'],
        ]);
        $variants = $this->ownedVariants($request, $data['variant_ids']);
        $changes = $data['changes'];
        $productChanges = collect($changes)->only([
            'name', 'brand', 'category', 'description', 'image', 'status',
        ])->all();
        $variantChanges = collect($changes)->only([
            'variant_name', 'barcode', 'is_active',
        ])->all();

        if ($productChanges === [] && $variantChanges === []) {
            throw ValidationException::withMessages([
                'changes' => 'Pilih minimal satu data yang akan diperbarui.',
            ]);
        }

        DB::transaction(function () use ($variants, $productChanges, $variantChanges) {
            if ($productChanges !== []) {
                MasterProduct::query()
                    ->whereIn('id', $variants->pluck('master_product_id')->unique())
                    ->update($productChanges);
            }
            if ($variantChanges !== []) {
                MasterProductVariant::query()
                    ->whereIn('id', $variants->pluck('id'))
                    ->update($variantChanges);
            }
        });

        return response()->json([
            'message' => "Data {$variants->count()} SKU master berhasil diperbarui.",
            'updated_count' => $variants->count(),
        ]);
    }

    public function bulkDestroyVariants(Request $request)
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $variants = $this->ownedVariants($request, $data['variant_ids']);
        $productIds = $variants->pluck('master_product_id')->unique();
        $affectsPayable = $variants->contains(
            fn (MasterProductVariant $variant) => $variant->supplier_id !== null || (float) $variant->hpp > 0
        );

        DB::transaction(function () use ($variants, $productIds) {
            $variants->load('syncGroup')->each(function (MasterProductVariant $variant) {
                $variant->syncGroup?->delete();
                $variant->delete();
            });
            MasterProduct::query()
                ->whereIn('id', $productIds)
                ->whereDoesntHave('variants')
                ->delete();
        });

        if ($affectsPayable) {
            $this->queuePayableSync($request->user()->id);
        }

        return response()->json([
            'message' => "{$variants->count()} SKU master berhasil dihapus. Produk marketplace tidak dihapus.",
            'deleted_count' => $variants->count(),
        ]);
    }

    public function bulkPushVariants(
        Request $request,
        MasterSkuSyncService $skuSync,
        StockSyncService $stockSync
    ) {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $variants = $this->ownedVariants($request, $data['variant_ids']);
        if ($variants->count() > 100) {
            $chunks = $variants->pluck('id')->chunk(100);
            $chunks->each(
                fn (Collection $ids) => SyncMasterProductStocksJob::dispatch($ids->all())->onQueue('products')
            );

            return response()->json([
                'message' => "Sinkronisasi stok {$variants->count()} SKU diproses di latar belakang.",
                'processed_count' => $variants->count(),
                'queued_count' => $chunks->count(),
            ], 202);
        }
        $queued = 0;

        foreach ($variants as $variant) {
            $group = $skuSync->syncVariant($variant);
            if ($group) {
                $queued += $stockSync->setMasterStock($group, (int) $variant->stock);
            }
        }

        return response()->json([
            'message' => $queued > 0
                ? "Sinkronisasi stok dijadwalkan untuk {$queued} listing marketplace."
                : 'Belum ada listing marketplace dengan SKU yang sama.',
            'processed_count' => $variants->count(),
            'queued_count' => $queued,
        ], 202);
    }

    public function destroy(Request $request, int $id)
    {
        $product = $this->ownedProduct($request, $id);
        $affectsPayable = $product->variants()
            ->where(function (Builder $query) {
                $query->whereNotNull('supplier_id')->orWhere('hpp', '>', 0);
            })
            ->exists();

        DB::transaction(function () use ($product) {
            $product->variants()->with('syncGroup')->get()
                ->each(fn (MasterProductVariant $variant) => $variant->syncGroup?->delete());
            $product->delete();
        });

        if ($affectsPayable) {
            $this->queuePayableSync($product->user_id);
        }

        return response()->json(['message' => 'Produk master berhasil dihapus.']);
    }

    private function validateProductPayload(Request $request, ?MasterProduct $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', 'in:active,draft,archived'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer', 'distinct'],
            'variants.*.sku' => ['required', 'string', 'max:120', 'not_in:0', 'distinct:ignore_case'],
            'variants.*.variant_name' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:120'],
            'variants.*.supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id),
            ],
            'variants.*.hpp' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
        ]);

        $variantIds = collect($data['variants'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
        if (! $product && $variantIds->isNotEmpty()) {
            throw ValidationException::withMessages(['variants' => 'ID varian tidak valid untuk produk baru.']);
        }
        if ($product && $variantIds->isNotEmpty()) {
            $ownedCount = $product->variants()->whereIn('id', $variantIds)->count();
            if ($ownedCount !== $variantIds->unique()->count()) {
                throw ValidationException::withMessages(['variants' => 'Varian bukan milik produk master ini.']);
            }
        }

        $this->assertUniqueSkus(
            $request->user()->id,
            collect($data['variants'])->pluck('sku'),
            $variantIds
        );

        $data['variants'] = collect($data['variants'])->map(function (array $variant) {
            $variant['sku'] = trim($variant['sku']);

            return $variant;
        })->all();

        return $data;
    }

    private function validateSingleVariantPayload(Request $request, MasterProductVariant $variant): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'url', 'max:2048'],
            'status' => ['required', 'in:active,draft,archived'],
            'sku' => ['required', 'string', 'max:120', 'not_in:0'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id),
            ],
            'hpp' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $this->assertUniqueSkus(
            $request->user()->id,
            collect([$data['sku']]),
            collect([$variant->id])
        );
        $data['sku'] = trim($data['sku']);

        return $data;
    }

    private function assertUniqueSkus(int $userId, Collection $skus, Collection $excludedIds): void
    {
        $normalized = $skus->map(fn ($sku) => mb_strtolower(trim((string) $sku)))->values();
        $duplicate = MasterProductVariant::query()
            ->where('user_id', $userId)
            ->when($excludedIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->whereIn(DB::raw('LOWER(sku)'), $normalized)
            ->value('sku');

        if ($duplicate) {
            throw ValidationException::withMessages(['sku' => "SKU {$duplicate} sudah digunakan."]);
        }
    }

    private function productAttributes(array $data): array
    {
        return collect($data)->only([
            'name', 'brand', 'category', 'description', 'image', 'status',
        ])->all();
    }

    private function variantAttributes(array $data, int $userId): array
    {
        return [
            'user_id' => $userId,
            'supplier_id' => $data['supplier_id'] ?? null,
            'sku' => trim($data['sku']),
            'variant_name' => $data['variant_name'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'hpp' => $data['hpp'],
            'stock' => $data['stock'],
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    private function ownedProduct(Request $request, int $id): MasterProduct
    {
        return MasterProduct::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'manual')
            ->findOrFail($id);
    }

    private function ownedVariants(Request $request, array $variantIds): Collection
    {
        $variants = MasterProductVariant::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $variantIds)
            ->whereHas('masterProduct', fn (Builder $query) => $query->where('source', 'manual'))
            ->with('masterProduct')
            ->get();

        if ($variants->count() !== count($variantIds)) {
            throw ValidationException::withMessages([
                'variant_ids' => 'Sebagian SKU master tidak ditemukan atau tidak dapat diakses.',
            ]);
        }

        return $variants;
    }

    private function normalizedFilterValues(Request $request, string $pluralKey, string $singularKey): Collection
    {
        $values = $request->input($pluralKey, []);
        if (! is_array($values)) {
            $values = [$values];
        }
        $legacy = trim((string) $request->input($singularKey, ''));
        if ($legacy !== '') {
            $values[] = $legacy;
        }

        return collect($values)
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->take(100)
            ->values();
    }

    private function applyCatalogFilters(
        Builder $query,
        string $search,
        Collection $productClusters,
        Collection $variantClusters,
        string $supplierFilter
    ): Builder {
        return $query
            ->when($search !== '', function (Builder $builder) use ($search) {
                $builder->where(function (Builder $nested) use ($search) {
                    $nested->where('sku', 'ilike', "%{$search}%")
                        ->orWhere('variant_name', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%")
                        ->orWhereHas('masterProduct', function (Builder $productQuery) use ($search) {
                            $productQuery->where('name', 'ilike', "%{$search}%")
                                ->orWhere('brand', 'ilike', "%{$search}%")
                                ->orWhere('category', 'ilike', "%{$search}%");
                        });
                });
            })
            ->when($productClusters->isNotEmpty(), fn (Builder $builder) => $builder
                ->whereIn('product_cluster_key', $productClusters))
            ->when($variantClusters->isNotEmpty(), function (Builder $builder) use ($variantClusters) {
                $builder->where(function (Builder $nested) use ($variantClusters) {
                    foreach ($variantClusters as $cluster) {
                        $nested->orWhereJsonContains('variant_cluster_keys', $cluster);
                    }
                });
            })
            ->when($supplierFilter === 'unassigned', fn (Builder $builder) => $builder->whereNull('supplier_id'))
            ->when(ctype_digit($supplierFilter), fn (Builder $builder) => $builder
                ->where('supplier_id', (int) $supplierFilter));
    }

    private function variantRelations(bool $withProduct = true): array
    {
        $relations = ['supplier', 'syncGroup.members.store', 'syncGroup.members.product', 'syncGroup.members.variant'];
        if ($withProduct) {
            array_unshift($relations, 'masterProduct');
        }

        return $relations;
    }

    private function formatProduct(MasterProduct $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'description' => $product->description,
            'image' => $product->image,
            'status' => $product->status,
            'variants' => $product->variants
                ->map(fn (MasterProductVariant $variant) => $this->formatVariant($variant, $product))
                ->values(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    private function formatVariant(MasterProductVariant $variant, ?MasterProduct $product = null): array
    {
        $product ??= $variant->masterProduct;
        $group = $variant->syncGroup;
        $members = $group?->members ?? collect();
        $stores = $members->groupBy('store_id')->map(function (Collection $storeMembers) {
            $status = collect(['failed', 'pending', 'idle', 'synced'])
                ->first(fn ($candidate) => $storeMembers->contains('sync_status', $candidate)) ?? 'idle';
            $first = $storeMembers->first();
            $lastSyncedAt = $storeMembers->pluck('last_synced_at')
                ->filter()
                ->sortDesc()
                ->first();

            return [
                'id' => $first->store_id,
                'name' => $first->store?->store_name,
                'platform' => $first->store?->platform,
                'status' => $status,
                'listings_count' => $storeMembers->count(),
                'last_synced_at' => $lastSyncedAt?->toIso8601String(),
                'error' => $storeMembers->firstWhere('sync_status', 'failed')?->last_sync_error,
            ];
        })->values();

        return [
            'id' => $variant->id,
            'master_product_id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'description' => $product->description,
            'image' => $product->image,
            'product_status' => $product->status,
            'variant_name' => $variant->variant_name,
            'sku' => $variant->sku,
            'product_cluster_key' => $variant->product_cluster_key,
            'product_cluster_label' => $variant->product_cluster_key
                ? mb_strtoupper($variant->product_cluster_key)
                : null,
            'variant_cluster_keys' => $variant->variant_cluster_keys ?? [],
            'variant_cluster_labels' => collect($variant->variant_cluster_keys ?? [])
                ->map(fn (string $key) => mb_strtoupper($key))
                ->values(),
            'barcode' => $variant->barcode,
            'supplier_id' => $variant->supplier_id,
            'supplier_name' => $variant->supplier?->name,
            'hpp' => (float) $variant->hpp,
            'stock' => (int) ($group?->master_stock ?? $variant->stock),
            'is_active' => $variant->is_active,
            'sync_group_id' => $group?->id,
            'sync_enabled' => (bool) $group?->is_active,
            'listings_count' => $members->count(),
            'stores_count' => $stores->count(),
            'same_sku_across_stores' => $stores->count() > 1,
            'stores' => $stores,
            'last_synced_at' => $group?->last_synced_at?->toIso8601String(),
            'updated_at' => $variant->updated_at?->toIso8601String(),
        ];
    }

    private function ownedVariantsByCluster(Request $request, array $data): Collection
    {
        $productCluster = mb_strtolower(trim((string) ($data['product_cluster_key'] ?? '')));
        $variantCluster = mb_strtolower(trim((string) ($data['variant_cluster_key'] ?? '')));
        if ($productCluster === '' && $variantCluster === '') {
            throw ValidationException::withMessages([
                'variant_ids' => 'Pilih SKU atau cluster yang akan diperbarui.',
            ]);
        }

        $query = MasterProductVariant::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('masterProduct', fn (Builder $builder) => $builder->where('source', 'manual'))
            ->when($productCluster !== '', fn (Builder $builder) => $builder
                ->where('product_cluster_key', $productCluster))
            ->when($variantCluster !== '', fn (Builder $builder) => $builder
                ->whereJsonContains('variant_cluster_keys', $variantCluster));

        $supplierFilter = trim((string) ($data['supplier_filter'] ?? ''));
        $query
            ->when($supplierFilter === 'unassigned', fn (Builder $builder) => $builder->whereNull('supplier_id'))
            ->when(ctype_digit($supplierFilter), fn (Builder $builder) => $builder
                ->where('supplier_id', (int) $supplierFilter));

        if ((clone $query)->count() > 2000) {
            throw ValidationException::withMessages([
                'variant_ids' => 'Cluster terlalu besar. Persempit filter hingga maksimal 2.000 SKU.',
            ]);
        }

        $variants = $query->with('masterProduct')->get();
        if ($variants->isEmpty()) {
            throw ValidationException::withMessages([
                'variant_ids' => 'Tidak ada SKU yang cocok dengan cluster terpilih.',
            ]);
        }

        return $variants;
    }

    private function queuePayableSync(int $userId): void
    {
        $payableStart = Supplier::query()
            ->where('user_id', $userId)
            ->whereNotNull('first_period_start')
            ->min('first_period_start');

        if ($payableStart) {
            app(PayableSyncStatusService::class)->dispatch((string) $payableStart, $userId, 'master_product_update');
        }
    }
}
