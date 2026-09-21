<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterProduct;
use App\Models\MasterProductVariant;
use App\Models\SkuSyncGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterProductController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min(max((int) $request->input('per_page', 20), 10), 100);
        $search = trim((string) $request->input('search', ''));

        $products = MasterProduct::query()
            ->where('user_id', $user->id)
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('name', 'ilike', "%{$search}%")
                        ->orWhere('brand', 'ilike', "%{$search}%")
                        ->orWhere('category', 'ilike', "%{$search}%")
                        ->orWhereHas('variants', function (Builder $variantQuery) use ($search) {
                            $variantQuery->where('sku', 'ilike', "%{$search}%")
                                ->orWhere('variant_name', 'ilike', "%{$search}%")
                                ->orWhere('barcode', 'ilike', "%{$search}%");
                        });
                });
            })
            ->with($this->masterProductRelations())
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($products->items())
                ->map(fn (MasterProduct $product) => $this->formatProduct($product))
                ->values(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $user = $request->user();

        $product = DB::transaction(function () use ($data, $user) {
            $product = MasterProduct::create([
                ...$this->productAttributes($data),
                'user_id' => $user->id,
            ]);

            $this->syncVariants($product, $data['variants'], $user->id);

            return $product;
        });

        return response()->json(
            $this->formatProduct($product->load($this->masterProductRelations())),
            201
        );
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $product = MasterProduct::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);
        $data = $this->validatePayload($request, $product);

        DB::transaction(function () use ($data, $product, $user) {
            $product->update($this->productAttributes($data));
            $this->syncVariants($product, $data['variants'], $user->id);
        });

        return response()->json(
            $this->formatProduct($product->fresh($this->masterProductRelations()))
        );
    }

    public function destroy(Request $request, int $id)
    {
        $product = MasterProduct::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        $product->delete();

        return response()->json(['message' => 'Produk master berhasil dihapus.']);
    }

    private function validatePayload(Request $request, ?MasterProduct $product = null): array
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
            'variants.*.sku' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'variants.*.variant_name' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:120'],
            'variants.*.hpp' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.is_active' => ['boolean'],
        ]);

        $variantIds = collect($data['variants'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
        if (! $product && $variantIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'variants' => 'Produk master baru tidak dapat menggunakan ID varian yang sudah ada.',
            ]);
        }

        if ($product && $variantIds->isNotEmpty()) {
            $ownedVariantCount = $product->variants()->whereIn('id', $variantIds)->count();
            if ($ownedVariantCount !== $variantIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'variants' => 'Varian yang dipilih tidak termasuk dalam produk master ini.',
                ]);
            }
        }

        $normalizedSkus = collect($data['variants'])
            ->pluck('sku')
            ->map(fn ($sku) => mb_strtolower(trim((string) $sku)))
            ->values();

        $duplicateSku = MasterProductVariant::query()
            ->where('user_id', $request->user()->id)
            ->when($variantIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $variantIds))
            ->whereIn(DB::raw('LOWER(sku)'), $normalizedSkus)
            ->value('sku');

        if ($duplicateSku) {
            throw ValidationException::withMessages([
                'variants' => "SKU {$duplicateSku} sudah digunakan oleh produk master lain.",
            ]);
        }

        $data['variants'] = collect($data['variants'])->map(function (array $variant) {
            $variant['sku'] = trim($variant['sku']);

            return $variant;
        })->all();

        return $data;
    }

    private function productAttributes(array $data): array
    {
        return collect($data)->only([
            'name',
            'brand',
            'category',
            'description',
            'image',
            'status',
        ])->all();
    }

    private function syncVariants(MasterProduct $product, array $variants, int $userId): void
    {
        $keptIds = [];

        foreach ($variants as $variantData) {
            $variantId = $variantData['id'] ?? null;
            $variant = $variantId
                ? $product->variants()->findOrFail($variantId)
                : new MasterProductVariant([
                    'master_product_id' => $product->id,
                    'user_id' => $userId,
                ]);

            $variant->fill([
                'sku' => $variantData['sku'],
                'variant_name' => $variantData['variant_name'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'hpp' => $variantData['hpp'],
                'stock' => $variantData['stock'],
                'is_active' => $variantData['is_active'] ?? true,
            ]);
            $variant->save();
            $keptIds[] = $variant->id;

            SkuSyncGroup::query()
                ->where('master_product_variant_id', $variant->id)
                ->update(['master_product_variant_id' => null]);

            SkuSyncGroup::query()
                ->where('user_id', $userId)
                ->whereRaw('LOWER(sku) = ?', [mb_strtolower($variant->sku)])
                ->update(['master_product_variant_id' => $variant->id]);
        }

        $product->variants()->whereNotIn('id', $keptIds)->delete();
    }

    private function masterProductRelations(): array
    {
        return [
            'variants' => fn ($query) => $query
                ->orderByDesc('is_active')
                ->orderBy('variant_name')
                ->with(['syncGroup.members.store']),
        ];
    }

    private function formatProduct(MasterProduct $product): array
    {
        $variants = $product->variants->map(function (MasterProductVariant $variant) {
            $group = $variant->syncGroup;
            $channels = $group?->members
                ->map(fn ($member) => [
                    'store_id' => $member->store_id,
                    'store_name' => $member->store?->store_name,
                    'platform' => $member->store?->platform,
                ])
                ->unique('store_id')
                ->values() ?? collect();

            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'variant_name' => $variant->variant_name,
                'barcode' => $variant->barcode,
                'hpp' => (float) $variant->hpp,
                'stock' => (int) ($group?->master_stock ?? $variant->stock),
                'local_stock' => (int) $variant->stock,
                'is_active' => $variant->is_active,
                'sync_group_id' => $group?->id,
                'linked_listings_count' => $group?->members->count() ?? 0,
                'channels' => $channels,
            ];
        });

        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand,
            'category' => $product->category,
            'description' => $product->description,
            'image' => $product->image,
            'status' => $product->status,
            'variants_count' => $variants->count(),
            'total_stock' => $variants->sum('stock'),
            'linked_listings_count' => $variants->sum('linked_listings_count'),
            'variants' => $variants->values(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
