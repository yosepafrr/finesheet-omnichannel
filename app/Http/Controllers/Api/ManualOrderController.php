<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MasterProductVariant;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Services\ManualOrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManualOrderController extends Controller
{
    public function accounts(Request $request)
    {
        return response()->json(['accounts' => FinancialAccount::where('user_id', $request->user()->id)
            ->where('category', 'cash')->where('is_active', true)->whereNull('source_type')
            ->orderBy('name')->get(['id', 'name'])]);
    }

    public function options(Request $request)
    {
        $input = $request->validate(['search' => 'nullable|string|max:150']);
        $search = $input['search'] ?? '';
        $variants = MasterProductVariant::where('user_id', $request->user()->id)->where('is_active', true)
            ->with('masterProduct')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(sku) LIKE ?', ['%'.strtolower($search).'%'])
                ->orWhereHas('masterProduct', fn ($product) => $product->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']))))
            ->orderBy('sku')->limit(30)->get();

        return response()->json(['variants' => $variants->map(fn ($v) => [
            'id' => $v->id, 'sku' => $v->sku, 'name' => $v->masterProduct->name,
            'variant_name' => $v->variant_name, 'is_bundle' => $v->is_bundle,
            'available' => $v->availableStock(),
        ])]);
    }

    public function store(Request $request, ManualOrderService $service)
    {
        $input = $request->validate([
            'request_key' => 'required|uuid', 'order_time' => 'required|date|before_or_equal:now',
            'buyer_name' => 'required|string|max:150', 'buyer_phone' => 'nullable|string|max:40',
            'source' => 'required|string|max:80', 'notes' => 'nullable|string|max:2000',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_provider' => 'nullable|string|max:100',
            'payment_status' => ['required', Rule::in(['UNPAID', 'PARTIAL', 'PAID'])],
            'discount' => 'nullable|numeric|min:0|max:999999999',
            'shipping_fee' => 'nullable|numeric|min:0|max:999999999',
            'financial_account_id' => 'nullable|integer',
            'paid_amount' => 'required_if:payment_status,PARTIAL|nullable|numeric|min:0|max:999999999',
            'payment_received_at' => 'nullable|date|before_or_equal:now',
            'items' => 'required|array|min:1|max:100',
            'items.*.variant_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.price' => 'required|numeric|min:0|max:999999999',
        ]);
        $order = $service->create($request->user()->id, $input);

        return response()->json(['id' => $order->id, 'order_sn' => $order->order_sn], 201);
    }

    public function update(Request $request, int $id, ManualOrderService $service)
    {
        $input = $request->validate([
            'tracking_number' => 'nullable|string|max:100',
            'shipping_provider' => 'nullable|string|max:100',
            'financial_account_id' => 'nullable|integer',
            'paid_amount' => 'required_if:payment_status,PARTIAL|nullable|numeric|min:0|max:999999999',
            'payment_received_at' => 'nullable|date|before_or_equal:now',
            'status' => ['required', Rule::in(['PROCESSED', 'SHIPPED', 'COMPLETED', 'CANCELLED'])],
            'payment_status' => ['required', Rule::in(['UNPAID', 'PARTIAL', 'PAID'])],
        ]);
        $order = Order::where('platform', 'Manual')->whereHas('store', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($id);
        $service->update($order, $input);

        return response()->json(['message' => 'Pesanan manual diperbarui.']);
    }
}
