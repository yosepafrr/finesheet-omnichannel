<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\VariantItems;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');

        $products = Item::whereIn('store_id', $storeIds)
            ->when($request->search, function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('item_name', 'ilike', '%' . $request->search . '%')
                      ->orWhere('item_sku', 'ilike', '%' . $request->search . '%');
                });
            })
            ->with('variantItems')
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
                            'item_name' => $product->item_name,
                            'item_sku' => $product->item_sku,
                            'stock' => $product->stock,
                            'price' => $product->price,
                            'hpp' => $product->hpp,
                            'image' => $product->image,
                            'variants' => $product->variantItems->map(function ($v) {
                                return [
                                    'id' => $v->id,
                                    'model_name' => $v->model_name,
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

        $item = Item::findOrFail($id);

        // Verify ownership
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        if (!$storeIds->contains($item->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $item->hpp = $request->hpp;
        $item->save();

        return response()->json(['message' => 'HPP updated', 'hpp' => $item->hpp]);
    }

    public function updateVariantHpp(Request $request, $id)
    {
        $request->validate(['hpp' => 'required|numeric|min:0']);

        $variant = VariantItems::findOrFail($id);

        // Verify ownership through item -> store
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');
        $item = Item::find($variant->item_id);

        if (!$item || !$storeIds->contains($item->store_id)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $variant->hpp = $request->hpp;
        $variant->save();

        return response()->json(['message' => 'HPP updated', 'hpp' => $variant->hpp]);
    }
}
