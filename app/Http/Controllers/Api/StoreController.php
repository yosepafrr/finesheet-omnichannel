<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class StoreController extends Controller
{
    public function index()
    {
        $stores = Auth::user()->stores()->get()->map(function ($store) {

            $logos = [
                'Shopee' => asset('Marketplace-logo/shopee.png'),
                'Tokopedia' => asset('Marketplace-logo/tokopedia.png'),
                'Tiktokshop' => asset('Marketplace-logo/tts.png'),
            ];

            $authorizationValid = $store->shop_expired_at
                && Carbon::parse($store->shop_expired_at)->isFuture()
                && ! empty($store->refresh_token);
            $tokenNeedsRefresh = ! $store->token_expired_at
                || Carbon::parse($store->token_expired_at)->isPast();

            return [
                'id' => $store->id,
                'platform' => $store->platform,
                'store_name' => $store->store_name,
                'shop_id' => $store->shopee_shop_id,
                'logo' => $logos[$store->platform] ?? null,

                'is_active' => $authorizationValid,
                'token_needs_refresh' => $tokenNeedsRefresh,
            ];
        });

        return response()->json($stores);
    }

    public function destroy($id)
    {
        $store = Auth::user()->stores()->find($id);

        if (! $store) {
            return response()->json(['message' => 'Store not found or unauthorized'], 404);
        }

        $store->delete();

        return response()->json([
            'message' => 'Toko dan data marketplace berhasil dihapus. Produk master tetap dipertahankan.',
        ]);
    }
}
