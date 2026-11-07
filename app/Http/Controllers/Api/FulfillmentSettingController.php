<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\AccessControlService;
use Illuminate\Http\Request;

class FulfillmentSettingController extends Controller
{
    public function index(Request $request, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'fulfillment.settings.manage'), 403);

        return response()->json([
            'stores' => $request->user()->stores()
                ->select([
                    'id',
                    'platform',
                    'store_name',
                    'fulfillment_handover_method',
                    'shipping_label_template',
                ])
                ->orderBy('store_name')
                ->get(),
        ]);
    }

    public function update(Request $request, int $storeId, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'fulfillment.settings.manage'), 403);

        $validated = $request->validate([
            'fulfillment_handover_method' => ['required', 'string', 'in:pickup,dropoff'],
            'shipping_label_template' => ['required', 'string', 'in:platform,finesheet'],
        ]);

        /** @var Store $store */
        $store = $request->user()->stores()->findOrFail($storeId);
        $store->update($validated);

        return response()->json([
            'message' => "Pengaturan {$store->store_name} berhasil disimpan.",
            'store' => $store->only([
                'id',
                'platform',
                'store_name',
                'fulfillment_handover_method',
                'shipping_label_template',
            ]),
        ]);
    }
}
