<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PayableEvent;
use App\Models\PayablePayment;
use App\Models\PayablePeriod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Services\PayableService;
use App\Services\PayableSyncStatusService;
use App\Services\ProductHppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayableController extends Controller
{
    /**
     * Parse supplier_ids from request query
     */
    private function parseSupplierIds(Request $request): array
    {
        $raw = $request->get('supplier_ids') ?? $request->get('supplier_id');
        if (! $raw || $raw === 'ALL') {
            return [];
        }
        if (is_array($raw)) {
            return array_map('intval', array_filter($raw, fn ($v) => is_numeric($v)));
        }

        return array_map('intval', array_filter(explode(',', (string) $raw), fn ($v) => is_numeric($v)));
    }

    /**
     * Get suppliers for current user
     */
    public function getSuppliers()
    {
        $userId = Auth::id();
        $suppliers = Supplier::where('user_id', $userId)
            ->withCount(['products', 'masterProductVariants'])
            ->get()
            ->map(function ($supplier) use ($userId) {
                $totalDebt = PayableEvent::where('user_id', $userId)->where('supplier_id', $supplier->id)->where('amount', '>', 0)->sum('amount');
                $totalRed = PayableEvent::where('user_id', $userId)->where('supplier_id', $supplier->id)->where('amount', '<', 0)->sum('amount');
                $totalPaid = PayablePayment::where('user_id', $userId)->where('supplier_id', $supplier->id)->sum('amount');
                $supplier->total_debt = (float) $totalDebt;
                $supplier->total_reduction = (float) $totalRed;
                $supplier->net_payable = (float) ($totalDebt + $totalRed);
                $supplier->total_paid = (float) $totalPaid;
                $supplier->products_count = max(
                    $supplier->master_product_variants_count,
                    $supplier->products_count
                );

                return $supplier;
            });

        $storeIds = Store::where('user_id', $userId)->pluck('id');
        $hasMasterVariants = MasterProductVariant::where('user_id', $userId)->exists();
        $unassignedProductsCount = $hasMasterVariants
            ? MasterProductVariant::where('user_id', $userId)
                ->whereNull('supplier_id')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('supplier_product_mappings')
                        ->whereColumn('supplier_product_mappings.user_id', 'master_product_variants.user_id')
                        ->whereRaw('LOWER(supplier_product_mappings.sku) = LOWER(master_product_variants.sku)')
                        ->whereNotNull('supplier_product_mappings.supplier_id');
                })
                ->count()
            : Product::whereIn('store_id', $storeIds)->whereNull('supplier_id')->count();

        return response()->json([
            'status' => 'success',
            'data' => $suppliers,
            'unassigned_products_count' => $unassignedProductsCount,
        ]);
    }

    /**
     * Onboarding Single Supplier:
     * Creates 1 supplier and maps ALL products and historical events of user to this supplier.
     */
    public function onboardingSingle(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $supplier = Supplier::firstOrCreate(
                ['user_id' => $userId, 'name' => trim($validated['name'])],
                ['contact_person' => $request->get('contact_person'), 'phone' => $request->get('phone')]
            );

            // Get all store IDs for this user
            $storeIds = Store::where('user_id', $userId)->pluck('id');

            // 1. Assign all products of this user's stores to this supplier
            Product::whereIn('store_id', $storeIds)->update(['supplier_id' => $supplier->id]);
            MasterProductVariant::where('user_id', $userId)->update(['supplier_id' => $supplier->id]);

            // 2. Create supplier_product_mappings for each product/SKU
            $products = Product::whereIn('store_id', $storeIds)->with('variantProducts')->get();
            foreach ($products as $p) {
                if (! empty($p->product_sku)) {
                    SupplierProductMapping::updateOrCreate(
                        ['user_id' => $userId, 'product_id' => $p->id, 'sku' => $p->product_sku],
                        ['supplier_id' => $supplier->id, 'product_id' => $p->id, 'platform_product_id' => (string) $p->product_id]
                    );
                } else {
                    SupplierProductMapping::updateOrCreate(
                        ['user_id' => $userId, 'product_id' => $p->id, 'platform_product_id' => (string) $p->product_id],
                        ['supplier_id' => $supplier->id, 'product_id' => $p->id]
                    );
                }

                foreach ($p->variantProducts as $v) {
                    if (! empty($v->model_sku)) {
                        SupplierProductMapping::updateOrCreate(
                            ['user_id' => $userId, 'product_id' => $p->id, 'sku' => $v->model_sku],
                            ['supplier_id' => $supplier->id, 'product_id' => $p->id, 'platform_product_id' => (string) $p->product_id]
                        );
                    }
                }
            }

            // 3. Backfill all existing payable_events and payable_payments of this user
            PayableEvent::where('user_id', $userId)->whereNull('supplier_id')->update(['supplier_id' => $supplier->id]);
            PayablePayment::where('user_id', $userId)->whereNull('supplier_id')->update(['supplier_id' => $supplier->id]);

            DB::commit();

            $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();
            if ($config && ! empty($config->value['first_period_start'])) {
                app(PayableSyncStatusService::class)->dispatch($config->value['first_period_start'], $userId, 'supplier_setup');
            }

            return response()->json([
                'status' => 'success',
                'message' => "Supplier '{$supplier->name}' berhasil didaftarkan dan seluruh produk dikaitkan.",
                'data' => $supplier,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PayableController::onboardingSingle failed: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Gagal mendaftarkan supplier: '.$e->getMessage()], 500);
        }
    }

    /**
     * Onboarding Multiple Suppliers with Product Mapping
     */
    public function onboardingMultiple(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'suppliers' => 'required|array|min:2',
            'suppliers.*' => 'required|string|max:255',
            'mappings' => 'required|array',
            'mappings.*.product_id' => 'required',
            'mappings.*.supplier_name' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            // 1. Create/get suppliers
            $supplierMap = []; // name => id
            foreach ($validated['suppliers'] as $sName) {
                $sNameTrim = trim($sName);
                if (empty($sNameTrim)) {
                    continue;
                }
                $supplier = Supplier::firstOrCreate(
                    ['user_id' => $userId, 'name' => $sNameTrim]
                );
                $supplierMap[$sNameTrim] = $supplier->id;
            }

            // 2. Process product mappings
            foreach ($validated['mappings'] as $item) {
                $sName = trim($item['supplier_name']);
                $supplierId = $supplierMap[$sName] ?? null;
                if (! $supplierId) {
                    continue;
                }
                $this->assignSupplierToCatalogItem($item['product_id'], $supplierId, $userId);
            }

            DB::commit();

            // 3. Re-evaluate existing payable events synchronously
            app(PayableService::class)->syncPayableForUser($userId);

            $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();
            if ($config && ! empty($config->value['first_period_start'])) {
                app(PayableSyncStatusService::class)->dispatch($config->value['first_period_start'], $userId, 'supplier_setup');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier dan pemetaan produk berhasil disimpan.',
                'data' => Supplier::where('user_id', $userId)->get(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PayableController::onboardingMultiple failed: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Gagal menyimpan supplier: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get products of user's stores for mapping interface
     */
    public function getProductsForMapping(ProductHppService $hppService)
    {
        $userId = Auth::id();
        $storeIds = Store::where('user_id', $userId)->pluck('id');

        $masterVariants = MasterProductVariant::query()
            ->where('user_id', $userId)
            ->whereHas('masterProduct', fn ($query) => $query->where('source', 'manual'))
            ->with(['masterProduct', 'supplier', 'syncGroup.members.store'])
            ->orderBy('product_cluster_key')
            ->orderBy('sku')
            ->get();
        $fallbackMappings = SupplierProductMapping::query()
            ->where('user_id', $userId)
            ->whereNotNull('sku')
            ->whereNotNull('supplier_id')
            ->with('supplier')
            ->get()
            ->keyBy(fn (SupplierProductMapping $mapping) => mb_strtolower(trim((string) $mapping->sku)));
        $masterSkus = $masterVariants
            ->pluck('sku')
            ->filter()
            ->map(fn ($sku) => mb_strtolower(trim((string) $sku)))
            ->flip();

        $masterItems = $masterVariants->map(function (MasterProductVariant $variant) use ($fallbackMappings) {
            $stores = $variant->syncGroup?->members
                ?->pluck('store')
                ->filter()
                ->unique('id')
                ->values() ?? collect();
            $fallbackMapping = $fallbackMappings->get(mb_strtolower(trim((string) $variant->sku)));
            $supplierId = $variant->supplier_id ?? $fallbackMapping?->supplier_id;
            $supplierName = $variant->supplier?->name ?? $fallbackMapping?->supplier?->name;

            return [
                'id' => 'master:'.$variant->id,
                'entity_type' => 'master',
                'platform_product_id' => null,
                'product_name' => $variant->masterProduct?->name ?: $variant->sku,
                'product_sku' => $variant->sku,
                'image' => $variant->masterProduct?->image,
                'price' => null,
                'hpp' => (float) $variant->hpp,
                'hpp_source' => 'master',
                'platform' => 'Master Produk',
                'store_name' => 'Master Produk',
                'source_label' => $stores->isEmpty()
                    ? 'Belum terhubung ke toko'
                    : $stores->count().' toko terhubung',
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'supplier_source' => $variant->supplier_id ? 'master' : ($fallbackMapping ? 'marketplace' : null),
                'variants_count' => 1,
                'product_cluster_key' => $variant->product_cluster_key,
                'variant_cluster_keys' => $variant->variant_cluster_keys ?? [],
            ];
        });

        $marketplaceItems = Product::whereIn('store_id', $storeIds)
            ->with(['store', 'supplier', 'variantProducts', 'skuSyncMember.group.masterVariant'])
            ->orderBy('product_name', 'asc')
            ->get()
            ->filter(function (Product $product) use ($masterSkus) {
                $skus = collect([$product->product_sku])
                    ->merge($product->variantProducts->pluck('model_sku'))
                    ->filter()
                    ->map(fn ($sku) => mb_strtolower(trim((string) $sku)));

                return $skus->isEmpty() || $skus->every(fn (string $sku) => ! $masterSkus->has($sku));
            })
            ->map(function ($p) use ($hppService) {
                $hpp = $hppService->productDetails($p);

                return [
                    'id' => $p->id,
                    'entity_type' => 'marketplace',
                    'platform_product_id' => $p->product_id,
                    'product_name' => $p->product_name,
                    'product_sku' => $p->product_sku,
                    'image' => $p->image,
                    'price' => $p->price,
                    'hpp' => $hpp['hpp'],
                    'hpp_source' => $hpp['source'],
                    'platform' => $p->platform,
                    'store_name' => $p->store?->store_name,
                    'source_label' => $p->store?->store_name ?: $p->platform,
                    'supplier_id' => $p->supplier_id,
                    'supplier_name' => $p->supplier?->name,
                    'variants_count' => $p->variantProducts->count(),
                ];
            });

        $products = $masterItems->concat($marketplaceItems)->values();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    /**
     * Bulk assign products to a supplier
     */
    public function assignProducts(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required',
            'supplier_id' => 'required|integer|exists:suppliers,id',
        ]);

        $supplier = Supplier::where('user_id', $userId)->findOrFail($validated['supplier_id']);

        try {
            $assignedCount = DB::transaction(function () use ($validated, $supplier, $userId) {
                $catalogItemIds = collect($validated['product_ids'])->unique()->values();
                $masterVariantIds = $catalogItemIds
                    ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'master:'))
                    ->map(fn (string $id) => (int) substr($id, strlen('master:')))
                    ->filter()
                    ->unique()
                    ->values();

                $validMasterVariantIds = MasterProductVariant::query()
                    ->where('user_id', $userId)
                    ->whereIn('id', $masterVariantIds)
                    ->pluck('id');
                if ($validMasterVariantIds->isNotEmpty()) {
                    MasterProductVariant::query()
                        ->where('user_id', $userId)
                        ->whereIn('id', $validMasterVariantIds)
                        ->update(['supplier_id' => $supplier->id]);
                }

                $count = $validMasterVariantIds->count();
                foreach ($catalogItemIds->reject(fn ($id) => is_string($id) && str_starts_with($id, 'master:')) as $catalogItemId) {
                    if ($this->assignSupplierToCatalogItem($catalogItemId, $supplier->id, $userId)) {
                        $count++;
                    }
                }

                return $count;
            });

            $payableStart = Supplier::query()
                ->where('user_id', $userId)
                ->whereNotNull('first_period_start')
                ->min('first_period_start');
            $syncQueued = ! empty($payableStart);
            if ($syncQueued) {
                app(PayableSyncStatusService::class)->dispatch((string) $payableStart, $userId, 'supplier_assignment');
            }

            return response()->json([
                'status' => 'success',
                'message' => $assignedCount." produk berhasil dialihkan ke supplier '{$supplier->name}'."
                    .($syncQueued ? ' Perhitungan payable diperbarui di latar belakang.' : ''),
                'sync_queued' => $syncQueued,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Gagal mengubah supplier produk: '.$e->getMessage()], 500);
        }
    }

    /**
     * Create single supplier
     */
    public function createSupplier(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'period_length_days' => 'nullable|integer|min:1',
            'first_period_start' => 'nullable|date',
        ]);

        $supplier = Supplier::create([
            'user_id' => $userId,
            'name' => trim($validated['name']),
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'period_length_days' => $validated['period_length_days'] ?? 14,
            'first_period_start' => isset($validated['first_period_start']) ? Carbon::parse($validated['first_period_start']) : null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil ditambahkan',
            'data' => $supplier,
        ]);
    }

    /**
     * Update supplier
     */
    public function updateSupplier(Request $request, $id)
    {
        $userId = Auth::id();
        $supplier = Supplier::where('user_id', $userId)->findOrFail($id);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'period_length_days' => 'nullable|integer|min:1',
            'first_period_start' => 'nullable|date',
        ]);

        $updateData = $validated;
        if (! empty($updateData['first_period_start'])) {
            $updateData['first_period_start'] = Carbon::parse($updateData['first_period_start']);
        }

        $supplier->fill($updateData);
        $periodConfigChanged = $supplier->isDirty([
            'first_period_start',
            'period_length_days',
        ]);
        $supplier->save();

        // If period config changed, regenerate periods
        if ($periodConfigChanged) {
            PayablePeriod::where('supplier_id', $supplier->id)->where('is_manual', false)->delete();
            app(PayableService::class)->syncPayableForUser($userId);

            $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();
            if ($config && ! empty($config->value['first_period_start'])) {
                app(PayableSyncStatusService::class)->dispatch($config->value['first_period_start'], $userId, 'supplier_update');
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil diperbarui',
            'data' => $supplier,
        ]);
    }

    /**
     * Delete supplier
     */
    public function deleteSupplier($id)
    {
        $userId = Auth::id();
        $supplier = Supplier::where('user_id', $userId)->findOrFail($id);

        DB::beginTransaction();
        try {
            Product::where('supplier_id', $id)->update(['supplier_id' => null]);
            MasterProductVariant::where('supplier_id', $id)->update(['supplier_id' => null]);
            SupplierProductMapping::where('supplier_id', $id)->delete();
            PayablePayment::where('supplier_id', $id)->update(['supplier_id' => null]);
            PayableEvent::where('supplier_id', $id)->update(['supplier_id' => null]);
            $supplier->delete();
            DB::commit();

            $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();
            if ($config && ! empty($config->value['first_period_start'])) {
                app(PayableSyncStatusService::class)->dispatch($config->value['first_period_start'], $userId, 'supplier_delete');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to delete supplier $id: ".$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghapus supplier: '.$e->getMessage(),
            ], 500);
        }

        // Re-sync payable synchronously
        app(PayableService::class)->syncPayableForUser($userId);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier berhasil dihapus',
        ]);
    }

    private function assignSupplierToCatalogItem(mixed $catalogItemId, int $supplierId, int $userId): bool
    {
        if (is_string($catalogItemId) && str_starts_with($catalogItemId, 'master:')) {
            $variantId = (int) substr($catalogItemId, strlen('master:'));
            $variant = MasterProductVariant::query()
                ->where('user_id', $userId)
                ->find($variantId);
            if (! $variant) {
                return false;
            }

            $variant->update(['supplier_id' => $supplierId]);

            return true;
        }

        if (! is_numeric($catalogItemId)) {
            return false;
        }

        $storeIds = Store::where('user_id', $userId)->pluck('id');
        $product = Product::query()
            ->whereIn('store_id', $storeIds)
            ->with('variantProducts')
            ->find((int) $catalogItemId);
        if (! $product) {
            return false;
        }

        $product->update(['supplier_id' => $supplierId]);
        if (! empty($product->product_sku)) {
            SupplierProductMapping::updateOrCreate(
                ['user_id' => $userId, 'product_id' => $product->id, 'sku' => $product->product_sku],
                [
                    'supplier_id' => $supplierId,
                    'product_id' => $product->id,
                    'platform_product_id' => (string) $product->product_id,
                ]
            );
        } else {
            SupplierProductMapping::updateOrCreate(
                ['user_id' => $userId, 'product_id' => $product->id, 'platform_product_id' => (string) $product->product_id],
                ['supplier_id' => $supplierId, 'product_id' => $product->id]
            );
        }

        foreach ($product->variantProducts as $variant) {
            if (! empty($variant->model_sku)) {
                SupplierProductMapping::updateOrCreate(
                    ['user_id' => $userId, 'product_id' => $product->id, 'sku' => $variant->model_sku],
                    [
                        'supplier_id' => $supplierId,
                        'product_id' => $product->id,
                        'platform_product_id' => (string) $product->product_id,
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Get Payable Configuration for current user
     */
    public function getConfig(Request $request)
    {
        $userId = Auth::id();

        if ($request->filled('supplier_id')) {
            $supplier = Supplier::where('user_id', $userId)
                ->findOrFail((int) $request->get('supplier_id'));

            return response()->json([
                'status' => 'success',
                'data' => $supplier->first_period_start ? [
                    'supplier_id' => $supplier->id,
                    'first_period_start' => $supplier->first_period_start->format('Y-m-d H:i:s'),
                    'length_days' => $supplier->period_length_days ?? 14,
                ] : null,
            ]);
        }

        $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();

        return response()->json([
            'status' => 'success',
            'data' => $config ? $config->value : null,
        ]);
    }

    /**
     * Update Payable Configuration (first setup – sets start date + duration)
     */
    public function updateConfig(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'first_period_start' => 'required|date',
            'length_days' => 'required|integer|min:1',
        ]);

        $supplier = Supplier::where('user_id', $userId)
            ->findOrFail($validated['supplier_id']);
        $startDate = Carbon::parse($validated['first_period_start']);

        $supplier->update([
            'period_length_days' => $validated['length_days'],
            'first_period_start' => $startDate,
        ]);

        $earliestSupplier = Supplier::where('user_id', $userId)
            ->whereNotNull('first_period_start')
            ->orderBy('first_period_start')
            ->first();

        Setting::updateOrCreate(
            ['key' => 'recap_period_config', 'user_id' => $userId],
            ['value' => [
                'first_period_start' => $earliestSupplier->first_period_start->format('Y-m-d H:i:s'),
                'length_days' => $earliestSupplier->period_length_days ?? 14,
            ]]
        );

        // Sync historical orders for this user in background
        app(PayableSyncStatusService::class)->dispatch(
            $earliestSupplier->first_period_start->format('Y-m-d H:i:s'),
            $userId,
            'period_update'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Konfigurasi periode berhasil disimpan, histori sedang disinkronisasi',
            'data' => [
                'supplier_id' => $supplier->id,
                'first_period_start' => $supplier->first_period_start->format('Y-m-d H:i:s'),
                'length_days' => $supplier->period_length_days,
            ],
        ]);
    }

    /**
     * Update period duration only (for settings modal duration change).
     */
    public function updateDuration(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'length_days' => 'required|integer|min:1',
            'apply_mode' => 'required|in:future,all',
        ]);

        $supplier = Supplier::where('user_id', $userId)
            ->findOrFail($validated['supplier_id']);
        if (! $supplier->first_period_start) {
            return response()->json(['status' => 'error', 'message' => 'Belum ada konfigurasi periode'], 422);
        }

        $supplier->update(['period_length_days' => $validated['length_days']]);

        $earliestSupplier = Supplier::where('user_id', $userId)
            ->whereNotNull('first_period_start')
            ->orderBy('first_period_start')
            ->first();

        Setting::updateOrCreate(
            ['key' => 'recap_period_config', 'user_id' => $userId],
            ['value' => [
                'first_period_start' => $earliestSupplier->first_period_start->format('Y-m-d H:i:s'),
                'length_days' => $earliestSupplier->period_length_days ?? 14,
            ]]
        );

        if ($validated['apply_mode'] === 'all') {
            $firstStart = $supplier->first_period_start;

            // Only rebuild automatic periods belonging to the active supplier.
            PayablePeriod::where('user_id', $userId)
                ->where('supplier_id', $supplier->id)
                ->where('is_manual', false)
                ->delete();

            // Re-sync from the beginning so events get reassigned to the new periods
            app(PayableSyncStatusService::class)->dispatch($firstStart->format('Y-m-d H:i:s'), $userId, 'period_update');

            return response()->json([
                'status' => 'success',
                'message' => "Durasi periode {$supplier->name} diubah menjadi {$validated['length_days']} hari dan diterapkan ke semua periodenya. Sinkronisasi ulang dimulai.",
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Durasi periode {$supplier->name} diubah menjadi {$validated['length_days']} hari. Perubahan akan diterapkan setelah periode saat ini selesai.",
        ]);
    }

    /**
     * Get All Payable Periods with Summary for current user (supports supplier filtering)
     */
    public function getPeriods(Request $request)
    {
        $userId = Auth::id();
        $supplierIds = $this->parseSupplierIds($request);

        $periodsQuery = PayablePeriod::where('user_id', $userId)->with('supplier');
        if (! empty($supplierIds)) {
            $periodsQuery->whereIn('supplier_id', $supplierIds);
        }

        $periods = $periodsQuery->orderBy('start_date', 'desc')->get()->map(function ($period) use ($userId, $supplierIds) {
            $eventsQuery = PayableEvent::where('payable_period_id', $period->id)
                ->where('payable_events.user_id', $userId)
                ->leftJoin('orders', function ($join) {
                    $join->on('payable_events.source_id', '=', 'orders.order_sn')
                        ->where('payable_events.source_type', '=', 'CREATE_ORDER');
                })
                ->where(function ($q) {
                    $q->where('payable_events.source_type', '!=', 'CREATE_ORDER')
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('orders.order_status')
                                ->whereNotIn(DB::raw('UPPER(orders.order_status)'), ['UNPAID', 'UNKNOWN', 'ON_HOLD', '']);
                        });
                });

            if (! empty($supplierIds)) {
                $eventsQuery->whereIn('payable_events.supplier_id', $supplierIds);
            }

            $events = $eventsQuery->select('payable_events.*')->get();

            $totalDebt = $events->filter(fn ($e) => (float) $e->amount > 0)->sum(fn ($e) => (float) $e->amount);
            $totalReduction = $events->filter(fn ($e) => (float) $e->amount < 0)->sum(fn ($e) => (float) $e->amount);
            $netPayable = $totalDebt + $totalReduction;

            $paymentsQuery = PayablePayment::where('payable_period_id', $period->id)->where('user_id', $userId);
            if (! empty($supplierIds)) {
                $paymentsQuery->whereIn('supplier_id', $supplierIds);
            }
            $totalPaid = $paymentsQuery->sum('amount');

            $period->total_debt = $totalDebt;
            $period->total_reduction = $totalReduction;
            $period->net_payable = $netPayable;
            $period->total_paid = $totalPaid;
            $period->event_count = $events->count();

            return $period;
        });

        return response()->json([
            'status' => 'success',
            'data' => $periods,
        ]);
    }

    /**
     * Manually create a period for current user
     */
    public function createManualPeriod(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'supplier_id' => 'required|integer|exists:suppliers,id',
        ]);

        // Check if supplier belongs to the user
        $supplier = Supplier::where('user_id', $userId)->findOrFail($validated['supplier_id']);

        $period = PayablePeriod::create([
            'user_id' => $userId,
            'supplier_id' => $supplier->id,
            'name' => $validated['name'],
            'start_date' => Carbon::parse($validated['start_date']),
            'end_date' => Carbon::parse($validated['end_date']),
            'payment_status' => 'UNPAID',
            'is_closed' => false,
            'is_manual' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Periode berhasil dibuat secara manual',
            'data' => $period,
        ]);
    }

    /**
     * Get specific period details (events and payments) for current user (supports supplier filtering)
     */
    public function getPeriodDetails(Request $request, $id)
    {
        $userId = Auth::id();
        $supplierIds = $this->parseSupplierIds($request);

        $periodIdsToQuery = [];
        $period = null;

        if ($id === 'latest') {
            $sIdsToUse = $supplierIds;
            if (empty($sIdsToUse)) {
                $sIdsToUse = Supplier::where('user_id', $userId)->pluck('id')->toArray();
            }

            foreach ($sIdsToUse as $sId) {
                $latest = PayablePeriod::where('user_id', $userId)
                    ->where('supplier_id', $sId)
                    ->orderBy('start_date', 'desc')
                    ->first();
                if ($latest) {
                    $periodIdsToQuery[] = $latest->id;
                }
            }

            $period = (object) [
                'id' => 'latest',
                'name' => 'Periode Berjalan (Semua Supplier)',
                'start_date' => null,
                'end_date' => null,
                'payment_status' => 'MIXED',
                'supplier_id' => null,
                'is_virtual' => true,
            ];
        } else {
            $period = PayablePeriod::where('user_id', $userId)->findOrFail($id);
            $periodIdsToQuery = [$id];
        }

        if (empty($periodIdsToQuery)) {
            if (is_object($period)) {
                $period->total_debt = 0;
                $period->total_reduction = 0;
                $period->net_payable = 0;
                $period->total_paid = 0;
                $period->event_count = 0;
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'period' => $period,
                    'events' => collect(),
                    'payments' => collect(),
                ],
            ]);
        }

        $eventsQuery = PayableEvent::whereIn('payable_period_id', $periodIdsToQuery)
            ->where('user_id', $userId)
            ->with(['store', 'supplier'])
            ->orderBy('event_date', 'desc');

        if (! empty($supplierIds)) {
            $eventsQuery->whereIn('supplier_id', $supplierIds);
        }

        $events = $eventsQuery->get();

        $sourceIds = $events->pluck('source_id')->filter()->unique();

        $orders = Order::whereIn('order_sn', $sourceIds)
            ->with(['orderProducts.product.variantProducts'])
            ->get()
            ->keyBy('order_sn');

        $missingIds = $sourceIds->diff($orders->keys());
        $returnOrders = collect();
        if ($missingIds->isNotEmpty()) {
            $returns = OrderReturn::whereIn('external_return_id', $missingIds)
                ->with(['order.orderProducts.product.variantProducts'])
                ->get();
            foreach ($returns as $ret) {
                if ($ret->order) {
                    $returnOrders->put($ret->external_return_id, $ret->order);
                }
            }
        }

        $postShipmentAdjustmentIds = PayableEvent::where('user_id', $userId)
            ->whereIn('source_id', $sourceIds)
            ->whereIn('source_type', ['RETURN_ORDER', 'FAILED_DELIVERY', 'SELLER_LATE_CANCEL'])
            ->pluck('source_id')
            ->flip();

        $events->transform(function ($event) use ($orders, $returnOrders, $postShipmentAdjustmentIds) {
            $order = $orders->get($event->source_id) ?? $returnOrders->get($event->source_id);
            $event->has_post_shipment_adjustment = $postShipmentAdjustmentIds->has($event->source_id);
            $event->supplier_info = $event->supplier ? [
                'id' => $event->supplier->id,
                'name' => $event->supplier->name,
            ] : null;

            if ($order) {
                $event->order_id = $order->id;
                $event->order_sn = $order->order_sn;
                $event->order_status = $order->order_status;
                $event->product_count = $order->orderProducts->count();

                // If event has supplier_id, prefer showing item from that supplier
                $firstProduct = null;
                if ($event->supplier_id) {
                    $firstProduct = $order->orderProducts->first(function ($op) use ($event) {
                        return $op->product && $op->product->supplier_id == $event->supplier_id;
                    });
                }
                if (! $firstProduct) {
                    $firstProduct = $order->orderProducts->first();
                }

                if ($firstProduct) {
                    $normalizedModelName = str_replace([', ', ','], [' - ', ' - '], $firstProduct->model_name ?? '');
                    $variant = $firstProduct->product?->variantProducts?->first(function ($v) use ($firstProduct, $normalizedModelName) {
                        return $v->model_name === $normalizedModelName || $v->model_name === $firstProduct->model_name;
                    });
                    $event->first_product = [
                        'product_name' => $firstProduct->product_name,
                        'model_name' => $firstProduct->model_name,
                        'image' => $firstProduct->product->image ?? null,
                        'variant_image' => $variant ? $variant->variant_image : null,
                        'quantity' => $firstProduct->quantity_purchased,
                    ];
                } else {
                    $event->first_product = null;
                }
            } else {
                $event->order_id = null;
                $event->order_status = null;
                $event->first_product = null;
                $event->product_count = 0;
            }

            return $event;
        });

        // Filter out CREATE_ORDER events for orders with UNPAID, UNKNOWN, or ON_HOLD status
        $excludedStatuses = ['UNPAID', 'UNKNOWN', 'ON_HOLD'];
        $events = $events->reject(function ($event) use ($excludedStatuses) {
            if ($event->source_type === 'CREATE_ORDER') {
                $statusUpper = strtoupper(trim($event->order_status ?? ''));
                if (empty($statusUpper) || in_array($statusUpper, $excludedStatuses)) {
                    return true;
                }
            }

            return false;
        })->values();

        $paymentsQuery = PayablePayment::whereIn('payable_period_id', $periodIdsToQuery)
            ->where('user_id', $userId)
            ->with('supplier')
            ->orderBy('payment_date', 'desc');

        if (! empty($supplierIds)) {
            $paymentsQuery->whereIn('supplier_id', $supplierIds);
        }

        $payments = $paymentsQuery->get();

        $totalDebt = $events->filter(fn ($e) => (float) $e->amount > 0)->sum(fn ($e) => (float) $e->amount);
        $totalReduction = $events->filter(fn ($e) => (float) $e->amount < 0)->sum(fn ($e) => (float) $e->amount);
        $netPayable = $totalDebt + $totalReduction;
        $totalPaid = $payments->sum('amount');

        $period->total_debt = $totalDebt;
        $period->total_reduction = $totalReduction;
        $period->net_payable = $netPayable;
        $period->total_paid = $totalPaid;
        $period->event_count = $events->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $period,
                'events' => $events,
                'payments' => $payments,
            ],
        ]);
    }

    /**
     * Update payment status of a period
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'status' => 'required|in:UNPAID,PAID,PARTIAL',
        ]);

        $period = PayablePeriod::where('user_id', $userId)->findOrFail($id);
        $period->payment_status = $validated['status'];
        $period->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Status pelunasan berhasil diperbarui',
            'data' => $period,
        ]);
    }

    /**
     * Add payment to a period for current user
     */
    public function addPayment(Request $request, $id)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
        ]);

        $period = PayablePeriod::where('user_id', $userId)->findOrFail($id);

        $supplierId = $validated['supplier_id'] ?? null;
        if (! $supplierId) {
            $userSuppliers = Supplier::where('user_id', $userId)->pluck('id');
            if ($userSuppliers->count() === 1) {
                $supplierId = $userSuppliers->first();
            }
        }

        $payment = PayablePayment::create([
            'user_id' => $userId,
            'supplier_id' => $supplierId,
            'payable_period_id' => $period->id,
            'payment_date' => Carbon::parse($validated['payment_date']),
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? 'Transfer',
            'notes' => $validated['notes'],
        ]);

        $events = PayableEvent::where('payable_period_id', $period->id)->where('user_id', $userId)->get();
        $netPayable = $events->sum('amount');
        $totalPaid = PayablePayment::where('payable_period_id', $period->id)->where('user_id', $userId)->sum('amount');

        if ($totalPaid >= $netPayable && $netPayable > 0) {
            $period->payment_status = 'PAID';
        } elseif ($totalPaid > 0) {
            $period->payment_status = 'PARTIAL';
        } else {
            $period->payment_status = 'UNPAID';
        }
        $period->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Hutang supplier berhasil dicatat',
            'data' => $payment,
        ]);
    }

    /**
     * Update payment (Hutang Supplier)
     */
    public function updatePayment(Request $request, $id)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $payment = PayablePayment::where('user_id', $userId)->findOrFail($id);
        $period = PayablePeriod::where('user_id', $userId)->findOrFail($payment->payable_period_id);

        $payment->update([
            'payment_date' => Carbon::parse($validated['payment_date']),
            'amount' => $validated['amount'],
            'notes' => $validated['notes'],
        ]);

        $this->updatePeriodPaymentStatus($period, $userId);

        return response()->json([
            'status' => 'success',
            'message' => 'Data hutang supplier berhasil diperbarui',
            'data' => $payment,
        ]);
    }

    /**
     * Delete payment (Hutang Supplier)
     */
    public function deletePayment($id)
    {
        $userId = Auth::id();
        $payment = PayablePayment::where('user_id', $userId)->findOrFail($id);
        $period = PayablePeriod::where('user_id', $userId)->findOrFail($payment->payable_period_id);

        $payment->delete();

        $this->updatePeriodPaymentStatus($period, $userId);

        return response()->json([
            'status' => 'success',
            'message' => 'Data hutang supplier berhasil dihapus',
        ]);
    }

    /**
     * Helper to update period payment status
     */
    private function updatePeriodPaymentStatus($period, $userId)
    {
        $events = PayableEvent::where('payable_period_id', $period->id)->where('user_id', $userId)->get();
        $netPayable = $events->sum('amount');
        $totalPaid = PayablePayment::where('payable_period_id', $period->id)->where('user_id', $userId)->sum('amount');

        if ($totalPaid >= $netPayable && $netPayable > 0) {
            $period->payment_status = 'PAID';
        } elseif ($totalPaid > 0) {
            $period->payment_status = 'PARTIAL';
        } else {
            $period->payment_status = 'UNPAID';
        }
        $period->save();
    }

    /**
     * Manually trigger re-sync of payable history for current user
     */
    public function sync()
    {
        $userId = Auth::id();
        $config = Setting::where('key', 'recap_period_config')->where('user_id', $userId)->first();
        if (! $config || empty($config->value['first_period_start'])) {
            return response()->json(['status' => 'error', 'message' => 'Belum ada konfigurasi periode'], 422);
        }

        $syncStatus = app(PayableSyncStatusService::class);
        $syncStatus->dispatch($config->value['first_period_start'], $userId, 'manual');

        return response()->json([
            'status' => 'success',
            'message' => 'Sinkronisasi ulang dimulai',
            'data' => $syncStatus->get($userId),
        ]);
    }

    public function syncStatus()
    {
        $status = app(PayableSyncStatusService::class)->get(Auth::id());

        return response()->json([
            'status' => 'success',
            'data' => [
                ...$status,
                'is_syncing' => in_array($status['status'], ['queued', 'running'], true),
            ],
        ]);
    }

    /**
     * Delete a period and its cascade events/payments for current user
     */
    public function destroy($id)
    {
        $userId = Auth::id();
        $period = PayablePeriod::where('user_id', $userId)->findOrFail($id);

        PayablePayment::where('payable_period_id', $id)->where('user_id', $userId)->delete();
        PayableEvent::where('payable_period_id', $id)->where('user_id', $userId)->delete();

        $period->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Periode berhasil dihapus',
        ]);
    }

    /**
     * Search events across all periods for the reassignment modal for current user
     */
    public function searchEvents(Request $request)
    {
        $userId = Auth::id();
        $query = $request->get('q', '');
        $limit = min((int) $request->get('limit', 30), 100);

        $eventsQuery = PayableEvent::where('user_id', $userId)
            ->with('period')
            ->orderBy('event_date', 'desc');

        if ($query) {
            $eventsQuery->where(function ($q) use ($query) {
                $q->where('source_id', 'like', "%{$query}%")
                    ->orWhere('source_type', 'like', "%{$query}%")
                    ->orWhere('platform', 'like', "%{$query}%")
                    ->orWhere('notes', 'like', "%{$query}%");
            });
        }

        $events = $eventsQuery->limit($limit)->get()->map(function ($ev) {
            return [
                'id' => $ev->id,
                'source_id' => $ev->source_id,
                'source_type' => $ev->source_type,
                'platform' => $ev->platform,
                'event_date' => $ev->event_date,
                'amount' => $ev->amount,
                'is_manual_moved' => $ev->is_manual_moved,
                'period' => $ev->period ? [
                    'id' => $ev->period->id,
                    'name' => $ev->period->name,
                    'start_date' => $ev->period->start_date,
                    'end_date' => $ev->period->end_date,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $events,
        ]);
    }

    /**
     * Reassign one or multiple events to a target period for current user
     */
    public function reassignEvents(Request $request)
    {
        $userId = Auth::id();
        $validated = $request->validate([
            'event_ids' => 'required|array|min:1',
            'event_ids.*' => 'integer|exists:payable_events,id',
            'target_period_id' => 'required|integer|exists:payable_periods,id',
        ]);

        $targetPeriodId = $validated['target_period_id'];
        $targetPeriod = PayablePeriod::where('user_id', $userId)->findOrFail($targetPeriodId);

        $movedIds = [];
        $skippedIds = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated['event_ids'] as $eventId) {
                $event = PayableEvent::where('user_id', $userId)->find($eventId);
                if (! $event) {
                    continue;
                }

                // Skip if already in the target period
                if ($event->payable_period_id == $targetPeriodId) {
                    $skippedIds[] = $eventId;

                    continue;
                }

                // Check for duplicate: same source_id + source_type in the target period for this user
                $duplicate = PayableEvent::where('payable_period_id', $targetPeriodId)
                    ->where('user_id', $userId)
                    ->where('source_id', $event->source_id)
                    ->where('source_type', $event->source_type)
                    ->exists();

                if ($duplicate) {
                    Log::warning("PayableController::reassignEvents – duplicate prevented: source_id={$event->source_id} source_type={$event->source_type} already exists in period {$targetPeriodId}");
                    $skippedIds[] = $eventId;
                    $errors[] = "Event {$event->source_id} ({$event->source_type}) sudah ada di periode tujuan.";

                    continue;
                }

                $event->original_period_id = $event->original_period_id ?? $event->payable_period_id;
                $event->payable_period_id = $targetPeriodId;
                $event->is_manual_moved = true;
                $event->save();

                $movedIds[] = $eventId;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PayableController::reassignEvents failed: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Gagal memindahkan event: '.$e->getMessage()], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => count($movedIds).' event berhasil dipindahkan'.(count($skippedIds) > 0 ? ', '.count($skippedIds).' dilewati' : '').'.',
            'data' => [
                'moved' => $movedIds,
                'skipped' => $skippedIds,
                'errors' => $errors,
            ],
        ]);
    }
}
