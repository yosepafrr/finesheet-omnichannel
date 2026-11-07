<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ArrangeOrderShipmentJob;
use App\Models\Order;
use App\Services\AccessControlService;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentController extends Controller
{
    public function options(Request $request, int $id, AccessControlService $access, OrderFulfillmentService $fulfillment)
    {
        abort_unless($access->allows($request, 'orders.ship'), 403);
        $order = $this->ownedOrder($id);

        return response()->json($fulfillment->options($order));
    }

    public function arrange(Request $request, AccessControlService $access, OrderFulfillmentService $fulfillment)
    {
        abort_unless($access->allows($request, 'orders.ship'), 403);
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer'],
            'method' => ['nullable', 'string', 'in:PICKUP,DROP_OFF,pickup,dropoff'],
            'address_id' => ['nullable'],
            'pickup_time_id' => ['nullable'],
            'branch_id' => ['nullable'],
            'sender_real_name' => ['nullable', 'string', 'max:100'],
        ]);
        $orders = $this->ownedOrders($validated['order_ids']);
        $ineligible = $orders->filter(fn (Order $order) => ! $fulfillment->capabilities($order)['can_arrange']);
        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order_ids' => 'Sebagian pesanan sudah diatur atau belum siap diproses.',
            ]);
        }

        $selection = collect($validated)->except('order_ids')->filter(fn ($value) => $value !== null)->all();
        foreach ($orders as $order) {
            ArrangeOrderShipmentJob::dispatch($order->id, $selection)->onQueue('orders');
        }

        return response()->json([
            'message' => $orders->count().' pengaturan pengiriman dimasukkan ke antrean.',
            'queued' => $orders->count(),
        ], 202);
    }

    public function label(Request $request, int $id, AccessControlService $access, OrderFulfillmentService $fulfillment)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $result = $fulfillment->label($this->ownedOrder($id));

        if ($result['type'] === 'finesheet') {
            return response()
                ->view('shipping-labels.finesheet', [
                    'order' => $result['order'],
                    'shipping' => $result['shipping'],
                ])
                ->header('Cache-Control', 'private, no-store');
        }

        if ($result['type'] === 'redirect') {
            return redirect()->away($result['url']);
        }

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'Content-Disposition' => 'inline; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function ownedOrder(int $id): Order
    {
        return Order::with(['store', 'packages'])
            ->whereIn('store_id', Auth::user()->stores()->pluck('id'))
            ->findOrFail($id);
    }

    private function ownedOrders(array $ids)
    {
        $orders = Order::with(['store', 'packages'])
            ->whereIn('store_id', Auth::user()->stores()->pluck('id'))
            ->whereIn('id', $ids)
            ->get();
        abort_unless($orders->count() === count(array_unique($ids)), 404);

        return $orders;
    }
}
