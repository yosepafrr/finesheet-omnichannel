<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ArrangeOrderShipmentJob;
use App\Jobs\PrepareBulkShippingLabelsJob;
use App\Models\Order;
use App\Services\AccessControlService;
use App\Services\OrderFulfillmentService;
use App\Services\PickingListPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

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
        Order::whereIn('id', $orders->pluck('id'))->update([
            'fulfillment_status' => 'queued',
            'fulfillment_error' => null,
            'fulfillment_requested_at' => now(),
            'fulfillment_completed_at' => null,
        ]);

        foreach ($orders as $order) {
            ArrangeOrderShipmentJob::dispatch($order->id, $selection);
        }

        return response()->json([
            'message' => $orders->count().' pengaturan pengiriman dimasukkan ke antrean.',
            'queued' => $orders->count(),
        ], 202);
    }

    public function status(Request $request, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'orders.view'), 403);
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer'],
        ]);

        return response()->json([
            'orders' => $this->ownedOrders($validated['order_ids'])->map(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->fulfillment_status,
                'error' => $order->fulfillment_error,
                'requested_at' => $order->fulfillment_requested_at?->toIso8601String(),
                'completed_at' => $order->fulfillment_completed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function label(Request $request, int $id, AccessControlService $access, OrderFulfillmentService $fulfillment)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $order = $this->ownedOrder($id);

        try {
            $result = $fulfillment->label($order);
        } catch (RuntimeException $exception) {
            Log::warning('Shipping label could not be prepared', [
                'order_id' => $order->id,
                'order_sn' => $order->order_sn,
                'platform' => $order->platform,
                'message' => $exception->getMessage(),
            ]);

            return response()->view('shipping-labels.error', [
                'order' => $order,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->view('shipping-labels.error', [
                'order' => $order,
                'message' => 'Dokumen resi belum dapat disiapkan. Coba kembali beberapa saat lagi.',
            ], 503);
        }

        if ($result['type'] === 'finesheet') {
            return response()
                ->view('shipping-labels.finesheet', [
                    'order' => $result['order'],
                    'shipping' => $result['shipping'],
                    'printing' => $result['printing'],
                ])
                ->header('Cache-Control', 'private, no-store');
        }

        if ($result['type'] === 'redirect') {
            return redirect()->away($result['url']);
        }

        $headers = [
            'Content-Type' => $result['content_type'],
            'Content-Disposition' => 'inline; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ];
        if (! empty($result['document_type'])) {
            $headers['X-Finesheet-Shipping-Document-Type'] = $result['document_type'];
        }

        return response($result['body'], 200, $headers);
    }

    public function labels(Request $request, AccessControlService $access, OrderFulfillmentService $fulfillment)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:2', 'max:50'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);
        $orders = $this->ownedOrders($validated['order_ids']);

        try {
            $result = $fulfillment->labels($orders);
        } catch (RuntimeException $exception) {
            Log::warning('Bulk shipping labels could not be prepared', [
                'order_ids' => $orders->pluck('id')->all(),
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Dokumen resi massal belum dapat disiapkan. Coba kembali beberapa saat lagi.',
            ], 503);
        }

        if (($result['type'] ?? null) !== 'binary') {
            return response()->json([
                'message' => 'Dokumen resi massal tidak tersedia untuk pilihan pesanan ini.',
            ], 422);
        }

        return response($result['body'], 200, [
            'Content-Type' => $result['content_type'],
            'Content-Disposition' => 'inline; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function createLabelJob(Request $request, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:2', 'max:50'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);
        $orders = $this->ownedOrders($validated['order_ids']);
        $token = (string) Str::uuid();
        $status = [
            'user_id' => (int) Auth::id(),
            'status' => 'queued',
            'progress' => 0,
            'message' => 'Menunggu giliran untuk menyiapkan resi.',
            'updated_at' => now()->toIso8601String(),
        ];
        Cache::put(PrepareBulkShippingLabelsJob::statusKey($token), $status, now()->addHour());

        PrepareBulkShippingLabelsJob::dispatch(
            (int) Auth::id(),
            $orders->pluck('id')->all(),
            $token,
        );

        return response()->json([
            'token' => $token,
            'status' => $status['status'],
            'progress' => $status['progress'],
            'status_url' => url("/api/orders/fulfillment/shipping-label-jobs/{$token}"),
            'download_url' => url("/api/orders/fulfillment/shipping-label-jobs/{$token}/document"),
        ], 202);
    }

    public function labelJobStatus(Request $request, string $token, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $status = $this->ownedLabelJobStatus($token);

        return response()->json(collect($status)->except(['user_id', 'path'])->all());
    }

    public function labelJobDocument(Request $request, string $token, AccessControlService $access)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $status = $this->ownedLabelJobStatus($token);
        abort_unless(($status['status'] ?? null) === 'ready', 409, 'Dokumen resi belum selesai disiapkan.');
        $path = (string) ($status['path'] ?? '');
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename((string) ($status['filename'] ?? 'resi-massal.pdf')).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function pickingList(Request $request, AccessControlService $access, PickingListPdfService $pickingList)
    {
        abort_unless($access->allows($request, 'orders.print'), 403);
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer', 'distinct'],
        ]);
        $orders = $this->ownedOrders($validated['order_ids'])->loadMissing(['store', 'orderProducts']);
        $invalidOrders = $orders->reject(fn (Order $order) => $this->isProcessedOrder($order));
        if ($invalidOrders->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order_ids' => 'Picking list hanya dapat dibuat untuk pesanan yang telah diproses.',
            ]);
        }
        $result = $pickingList->generate($orders);

        return response($result['body'], 200, [
            'Content-Type' => 'application/pdf',
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

    private function ownedLabelJobStatus(string $token): array
    {
        abort_unless(Str::isUuid($token), 404);
        $status = Cache::get(PrepareBulkShippingLabelsJob::statusKey($token));
        abort_unless(is_array($status) && (int) ($status['user_id'] ?? 0) === (int) Auth::id(), 404);

        return $status;
    }

    private function isProcessedOrder(Order $order): bool
    {
        $platform = strtolower((string) $order->platform);
        $status = strtoupper(trim((string) $order->order_status));

        return ($platform === 'shopee' && $status === 'PROCESSED')
            || (in_array($platform, ['tiktokshop', 'tiktok'], true) && $status === 'AWAITING_COLLECTION');
    }
}
