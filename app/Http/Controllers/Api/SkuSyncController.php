<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SkuSyncGroup;
use App\Models\SkuSyncMember;
use App\Models\Product;
use App\Models\VariantProduct;
use App\Services\StockSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SkuSyncController extends Controller
{
    /**
     * List all SKU sync groups for the authenticated user.
     */
    public function index()
    {
        $user = Auth::user();
        $groups = SkuSyncGroup::with(['members.store', 'members.product', 'members.variant'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();
            
        return response()->json($groups);
    }

    /**
     * Detect matching SKUs across user's stores.
     */
    public function detect()
    {
        $user = Auth::user();
        $storeIds = $user->stores()->pluck('id');

        // Find variants with the same SKU in different stores
        $variants = VariantProduct::whereNotNull('model_sku')
            ->where('model_sku', '!=', '')
            ->whereHas('product', function ($query) use ($storeIds) {
                $query->whereIn('store_id', $storeIds);
            })
            ->with(['product.store'])
            ->get();

        // Group by SKU
        $grouped = $variants->groupBy('model_sku');
        
        $detected = [];
        foreach ($grouped as $sku => $items) {
            // Only suggest if SKU exists in more than 1 distinct store
            $uniqueStoreIds = $items->pluck('product.store_id')->unique();
            if ($uniqueStoreIds->count() > 1) {
                
                // Check if this SKU is already in a group
                $existingGroup = SkuSyncGroup::where('user_id', $user->id)
                    ->where('sku', $sku)
                    ->first();
                    
                if (!$existingGroup) {
                    $detected[] = [
                        'sku' => $sku,
                        'stores_count' => $uniqueStoreIds->count(),
                        'items' => $items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'product_id' => $item->product_id,
                                'store_name' => $item->product->store->store_name,
                                'platform' => $item->product->store->platform,
                                'product_name' => $item->product->product_name,
                                'variant_name' => $item->variant_name ?? $item->model_name,
                                'stock' => $item->stock,
                            ];
                        })
                    ];
                }
            }
        }

        return response()->json(array_values($detected));
    }

    /**
     * Create a new SKU sync group.
     */
    public function store(Request $request)
    {
        $request->validate([
            'sku' => 'required|string',
            'master_stock' => 'required|integer|min:0',
            'members' => 'required|array|min:1',
            'members.*.product_id' => 'required|integer',
            'members.*.variant_product_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        
        // Prevent duplicate SKU group for same user
        if (SkuSyncGroup::where('user_id', $user->id)->where('sku', $request->sku)->exists()) {
            return response()->json(['error' => 'Sync group for this SKU already exists.'], 400);
        }

        try {
            $group = $this->createGroup($user, $request->sku, $request->master_stock, $request->members);
            return response()->json($group->load('members.store', 'members.product', 'members.variant'), 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to create SKU sync group', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to create group: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create multiple SKU sync groups in bulk.
     */
    public function storeBulk(Request $request)
    {
        $request->validate([
            'groups' => 'required|array|min:1',
            'groups.*.sku' => 'required|string',
            'groups.*.master_stock' => 'required|integer|min:0',
            'groups.*.members' => 'required|array|min:1',
            'groups.*.members.*.product_id' => 'required|integer',
            'groups.*.members.*.variant_product_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $createdGroups = [];

        try {
            foreach ($request->groups as $groupData) {
                // Skip if group already exists
                if (SkuSyncGroup::where('user_id', $user->id)->where('sku', $groupData['sku'])->exists()) {
                    continue;
                }
                
                $group = $this->createGroup($user, $groupData['sku'], $groupData['master_stock'], $groupData['members']);
                $createdGroups[] = $group;
            }

            return response()->json(['message' => 'Successfully created ' . count($createdGroups) . ' groups', 'created_count' => count($createdGroups)], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to bulk create SKU sync groups', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to bulk create groups: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Private helper to create a single group and sync its stock.
     */
    private function createGroup($user, $sku, $masterStock, $membersData)
    {
        DB::beginTransaction();
        try {
            $group = SkuSyncGroup::create([
                'user_id' => $user->id,
                'sku' => $sku,
                'master_stock' => $masterStock,
                'is_active' => true,
            ]);

            foreach ($membersData as $memberData) {
                $product = Product::with('store')->find($memberData['product_id']);
                
                if (!$product || $product->store->user_id != $user->id) {
                    continue; // Skip invalid or unauthorized products
                }

                $platformVariantId = null;
                if (!empty($memberData['variant_product_id'])) {
                    $variant = VariantProduct::find($memberData['variant_product_id']);
                    if ($variant) {
                        $platformVariantId = $variant->model_id;
                    }
                }

                SkuSyncMember::create([
                    'sku_sync_group_id' => $group->id,
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'variant_product_id' => $memberData['variant_product_id'] ?? null,
                    'platform_product_id' => $product->product_id,
                    'platform_variant_id' => $platformVariantId,
                ]);
            }
            
            DB::commit();

            // Push initial stock
            $group->refresh();
            $syncService = new StockSyncService();
            $syncService->setMasterStock($group, $masterStock);

            return $group;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update master stock of a group.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'master_stock' => 'required|integer|min:0',
        ]);

        $user = Auth::user();
        $group = SkuSyncGroup::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $syncService = new StockSyncService();
        $syncService->setMasterStock($group, $request->master_stock);

        return response()->json($group->fresh(['members.store', 'members.product', 'members.variant']));
    }

    /**
     * Delete a sync group.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $group = SkuSyncGroup::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $group->delete();

        return response()->json(['message' => 'Sync group deleted successfully.']);
    }

    /**
     * Manually trigger push for a group.
     */
    public function push($id)
    {
        $user = Auth::user();
        $group = SkuSyncGroup::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        
        $syncService = new StockSyncService();
        $syncService->setMasterStock($group, $group->master_stock); // This will trigger the push logic

        return response()->json(['message' => 'Stock push triggered.']);
    }
    
    /**
     * Toggle group active status
     */
     public function toggleActive(Request $request, $id)
     {
         $user = Auth::user();
         $group = SkuSyncGroup::where('id', $id)->where('user_id', $user->id)->firstOrFail();
         
         $group->is_active = !$group->is_active;
         $group->save();
         
         return response()->json($group->fresh(['members.store', 'members.product', 'members.variant']));
     }
}
