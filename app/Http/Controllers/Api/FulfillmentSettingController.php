<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FulfillmentSettingController extends Controller
{
    private const SETTING_FIELDS = [
        'fulfillment_handover_method',
        'shipping_label_template',
        'shopee_thermal_printing',
        'print_shipping_label',
        'print_packing_list',
        'shipping_label_size',
        'packing_list_size',
        'printing_layout',
        'printing_file_format',
    ];

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
                    'shopee_thermal_printing',
                    'print_shipping_label',
                    'print_packing_list',
                    'shipping_label_size',
                    'packing_list_size',
                    'printing_layout',
                    'printing_file_format',
                ])
                ->orderBy('store_name')
                ->get(),
        ]);
    }

    public function update(Request $request, int $storeId, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'fulfillment.settings.manage'), 403);

        $validated = $this->validatedSettings($request);

        /** @var Store $store */
        $store = $request->user()->stores()->findOrFail($storeId);
        $store->update($validated);

        return response()->json([
            'message' => "Pengaturan {$store->store_name} berhasil disimpan.",
            'store' => $this->storePayload($store),
        ]);
    }

    public function bulkUpdate(Request $request, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'fulfillment.settings.manage'), 403);

        $request->validate([
            'store_ids' => ['required', 'array', 'min:1', 'max:200'],
            'store_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $validated = $this->validatedSettings($request);
        $storeIds = collect($request->input('store_ids'))->map(fn ($id) => (int) $id)->unique()->values();
        $stores = $request->user()->stores()->whereIn('id', $storeIds)->get();
        abort_unless($stores->count() === $storeIds->count(), 404);

        DB::transaction(function () use ($stores, $validated) {
            $stores->each->update($validated);
        });

        return response()->json([
            'message' => $stores->count().' toko berhasil diperbarui.',
            'stores' => $stores->map(fn (Store $store) => $this->storePayload($store->fresh()))->values(),
        ]);
    }

    private function validatedSettings(Request $request): array
    {
        $validated = $request->validate([
            'fulfillment_handover_method' => ['required', 'string', Rule::in(['pickup', 'dropoff'])],
            'shipping_label_template' => ['required', 'string', Rule::in(['platform', 'finesheet'])],
            'shopee_thermal_printing' => ['required', 'boolean'],
            'print_shipping_label' => ['required', 'boolean'],
            'print_packing_list' => ['required', 'boolean'],
            'shipping_label_size' => ['required', 'string', Rule::in(['a6', 'a5'])],
            'packing_list_size' => ['required', 'string', Rule::in(['a6', 'a5'])],
            'printing_layout' => ['required', 'string', Rule::in(['combined', 'separate'])],
            'printing_file_format' => ['required', 'string', Rule::in(['pdf'])],
        ]);

        if (! $validated['print_shipping_label'] && ! $validated['print_packing_list']) {
            throw ValidationException::withMessages([
                'print_shipping_label' => 'Pilih minimal satu dokumen yang akan dicetak.',
            ]);
        }

        if ($validated['shipping_label_template'] === 'finesheet') {
            throw ValidationException::withMessages([
                'shipping_label_template' => 'Template Finesheet belum tersedia.',
            ]);
        }

        return collect($validated)->only(self::SETTING_FIELDS)->all();
    }

    private function storePayload(Store $store): array
    {
        return $store->only(array_merge([
            'id',
            'platform',
            'store_name',
        ], self::SETTING_FIELDS));
    }
}
