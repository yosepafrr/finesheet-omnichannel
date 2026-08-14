<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\VariantProduct;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $products = Product::whereIn('store_id', $storeIds)
            ->when($request->search, function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('product_name', 'ilike', '%' . $request->search . '%')
                      ->orWhere('product_sku', 'ilike', '%' . $request->search . '%');
                });
            })
            ->with('variantProducts')
            ->latest()
            ->get();

        return response()->json([
            'stores' => $stores->map(function ($store) use ($products) {
                $storeProducts = $products->where('store_id', $store->id)->values();
                return [
                    'id' => $store->id,
                    'store_name' => $store->store_name,
                    'platform' => $store->platform,
                    'product_count' => $storeProducts->count(),
                    'products' => $storeProducts->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'platform_product_id' => $product->product_id,
                            'product_name' => $product->product_name,
                            'product_sku' => $product->product_sku,
                            'stock' => $product->stock,
                            'price' => $product->price,
                            'hpp' => $product->hpp,
                            'image' => $product->image,
                            'variants' => $product->variantProducts->map(function ($v) {
                                return [
                                    'id' => $v->id,
                                    'platform_variant_id' => $v->model_id,
                                    'model_name' => $v->model_name,
                                    'variant_name' => $v->variant_name ?? $v->model_name,
                                    'variant_image' => $v->variant_image,
                                    'tier_index' => $v->tier_index,
                                    'variant_options' => $v->variant_options,
                                    'model_sku' => $v->model_sku,
                                    'stock' => $v->stock,
                                    'price' => $v->price,
                                    'hpp' => $v->hpp,
                                ];
                            }),
                        ];
                    }),
                ];
            }),
        ]);
    }

    public function updateItemHpp(Request $request, $id)
    {
        $request->validate(['hpp' => 'required|numeric|min:0']);

        $product = Product::findOrFail($id);

        // Verify ownership
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        if (!$storeIds->contains($product->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $product->hpp = $request->hpp;
        $product->save();

        return response()->json(['message' => 'HPP updated', 'hpp' => $product->hpp]);
    }

    public function updateVariantHpp(Request $request, $id)
    {
        $request->validate(['hpp' => 'required|numeric|min:0']);

        $variant = VariantProduct::findOrFail($id);

        // Verify ownership through product -> store
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        $product = Product::find($variant->product_id);

        if (!$product || !$storeIds->contains($product->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $variant->hpp = $request->hpp;
        $variant->save();

        return response()->json(['message' => 'HPP updated', 'hpp' => $variant->hpp]);
    }

    public function updateBulkVariantHpp(Request $request)
    {
        $request->validate([
            'variant_ids' => 'required|array',
            'variant_ids.*' => 'integer',
            'hpp' => 'required|numeric|min:0'
        ]);

        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');

        $variants = VariantProduct::whereIn('id', $request->variant_ids)->get();

        $updatedCount = 0;
        foreach ($variants as $variant) {
            $product = Product::find($variant->product_id);
            if ($product && $storeIds->contains($product->store_id)) {
                $variant->hpp = $request->hpp;
                $variant->save();
                $updatedCount++;
            }
        }

        return response()->json(['message' => "$updatedCount variants updated", 'hpp' => $request->hpp]);
    }
}
