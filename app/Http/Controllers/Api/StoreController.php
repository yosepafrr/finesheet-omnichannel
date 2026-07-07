<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use Carbon\Carbon;

class StoreController extends Controller
{
        public function index()
    {
        $stores = Store::all()->map(function ($store) {

            $logos = [
                'Shopee' => asset('Marketplace-logo/shopee.png'),
                'Tokopedia' => asset('Marketplace-logo/tokopedia.png'),
                'Tiktokshop' => asset('Marketplace-logo/tts.png'),
            ];

            return [
                'id' => $store->id,
                'platform' => $store->platform,
                'store_name' => $store->store_name,
                'shop_id' => $store->shopee_shop_id,
                'logo' => $logos[$store->platform] ?? null,

                'is_active' =>
                    $store->shop_expired_at &&
                    Carbon::parse($store->shop_expired_at)->isFuture(),
            ];
        });

        return response()->json($stores);
    }
}
