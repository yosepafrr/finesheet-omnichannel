<?php

namespace App\Services;

use App\Models\MasterProductVariant;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\PayableEvent;
use App\Models\SkuSyncGroup;
use App\Models\Store;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class ManualOrderService
{
    public static function revenueAmount(Order $order): float
    {
        if ($order->order_status === 'CANCELLED') {
            return 0;
        }

        return round((float) $order->order_selling_price + (float) ($order->raw_data['shipping_fee'] ?? 0), 2);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }

    private function createNumberedOrder(array $attributes): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'MAN'.now()->format('ymd').Str::upper(Str::random(5));
            try {
                // A savepoint lets PostgreSQL retry a random-number collision
                // without aborting the surrounding stock/payment transaction.
                return DB::transaction(fn () => Order::withoutEvents(fn () => Order::create([
                    ...$attributes, 'order_sn' => $number,
                ])));
            } catch (UniqueConstraintViolationException $exception) {
                if (! Order::where('order_sn', $number)->exists()) {
                    throw $exception;
                }
            }
        }

        $this->fail('Nomor pesanan belum berhasil dibuat. Silakan coba kembali.');
    }

    public function create(int $userId, array $input): Order
    {
        return DB::transaction(function () use ($userId, $input) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $store = Store::withoutGlobalScope('marketplace')->firstOrCreate(
                ['user_id' => $userId, 'platform' => 'Manual'],
                ['store_name' => 'Pesanan Manual #'.$userId, 'shopee_shop_id' => 'MANUAL-'.$userId],
            );
            $existing = Order::where('store_id', $store->id)->where('manual_request_key', $input['request_key'])->first();
            if ($existing) {
                return $existing;
            }
            $date = Carbon::parse($input['order_time']);
            $lines = [];
            $stock = [];
            $subtotal = 0;
            foreach ($input['items'] as $line) {
                $variant = MasterProductVariant::where('user_id', $userId)->where('is_active', true)
                    ->with(['masterProduct', 'bundleComponents.componentVariant'])->findOrFail($line['variant_id']);
                $parts = $variant->is_bundle
                    ? $variant->bundleComponents->map(fn ($part) => ['variant' => $part->componentVariant, 'quantity' => (int) $part->quantity])->all()
                    : [['variant' => $variant, 'quantity' => 1]];
                if ($parts === []) {
                    $this->fail('Bundle '.$variant->sku.' belum memiliki komponen.');
                }
                $snapshot = ['variant_id' => $variant->id, 'hpp' => 0, 'suppliers' => []];
                foreach ($parts as $part) {
                    $component = $part['variant'];
                    if (! $component || (int) $component->user_id !== $userId || ! $component->is_active || $component->is_bundle || $part['quantity'] < 1) {
                        $this->fail('Komponen produk tidak valid atau tidak aktif.');
                    }
                    $supplier = Supplier::where('user_id', $userId)->find($component->supplier_id);
                    if (! $supplier || (float) $component->hpp <= 0) {
                        $this->fail('Lengkapi supplier dan HPP untuk SKU '.$component->sku.'.');
                    }
                    if (! app(PayableService::class)->getPeriodForDate($date, $userId, $supplier)) {
                        $this->fail('Periode supplier '.$supplier->name.' belum mencakup tanggal pesanan.');
                    }
                    $cost = round((float) $component->hpp * $part['quantity'], 2);
                    $snapshot['hpp'] += $cost;
                    $snapshot['suppliers'][$supplier->id] = ($snapshot['suppliers'][$supplier->id] ?? 0) + $cost;
                    $group = app(MasterSkuSyncService::class)->syncVariant($component);
                    if (! $group || ! $group->is_active) {
                        $this->fail('Aktifkan pengelolaan stok untuk SKU '.$component->sku.'.');
                    }
                    $stock[$group->id] = ($stock[$group->id] ?? 0) + $part['quantity'] * $line['quantity'];
                }
                $subtotal += (int) round($line['price'] * 100) * $line['quantity'];
                // Marketplace product IDs are positive bigint values. Negative IDs
                // keep eager product relations valid on PostgreSQL without a fake listing.
                $lines[] = ['product_id' => (string) -$variant->id, 'product_name' => $variant->masterProduct->name,
                    'model_name' => $variant->variant_name, 'sku' => $variant->sku, 'quantity_purchased' => $line['quantity'],
                    'price' => $line['price'], 'image' => $variant->masterProduct->image, 'manual_snapshot' => $snapshot];
            }
            $discount = (int) round(($input['discount'] ?? 0) * 100);
            if ($discount > $subtotal) {
                $this->fail('Diskon melebihi subtotal produk.');
            }
            $input['discount'] = $discount / 100;
            $input['shipping_fee'] = $input['shipping_fee'] ?? 0;
            $order = $this->createNumberedOrder([
                'store_id' => $store->id, 'platform' => 'Manual',
                'order_status' => 'PROCESSED', 'order_time' => $date, 'ready_to_ship_at' => $date,
                'order_selling_price' => ($subtotal - $discount) / 100,
                'raw_data' => collect($input)->except(['items', 'request_key'])->all(),
            ]);
            $order->forceFill(['manual_request_key' => $input['request_key']])->saveQuietly();
            foreach ($lines as $line) {
                OrderProduct::withoutEvents(fn () => $order->orderProducts()->create($line));
            }
            ksort($stock);
            $deductions = [];
            foreach ($stock as $groupId => $quantity) {
                $group = SkuSyncGroup::lockForUpdate()->findOrFail($groupId);
                $v = MasterProductVariant::lockForUpdate()->findOrFail($group->master_product_variant_id);
                if ($v->availableStock() < $quantity) {
                    $this->fail('Stok tersedia tidak cukup untuk SKU '.$v->sku.'.');
                }
                app(StockSyncService::class)->allocateOrderStock($group, $quantity, $order);
                $deductions[] = ['group_id' => $groupId, 'sku' => $v->sku, 'quantity' => $quantity];
            }
            $order->forceFill(['stock_sync_processed_at' => now(), 'stock_sync_deductions' => $deductions])->saveQuietly();
            app(BundleInventoryService::class)->refreshAffectedByGroupIds(array_keys($stock));
            $this->reconcilePayables($order->fresh(['orderProducts', 'store']), app(PayableService::class));
            app(ManualOrderPaymentService::class)->sync($order);

            return $order;
        });
    }

    public function update(Order $order, array $input): Order
    {
        return DB::transaction(function () use ($order, $input) {
            DB::table('users')->where('id', $order->store->user_id)->lockForUpdate()->first();
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $status = $input['status'];
            $allowed = ['PROCESSED' => ['PROCESSED', 'SHIPPED', 'COMPLETED', 'CANCELLED'], 'SHIPPED' => ['SHIPPED', 'COMPLETED'], 'COMPLETED' => ['COMPLETED'], 'CANCELLED' => ['CANCELLED']];
            if (! in_array($status, $allowed[$order->order_status] ?? [], true)) {
                $this->fail('Perubahan status tidak diizinkan. Pesanan terkirim tidak dapat dibatalkan langsung.');
            }
            $sync = app(StockSyncService::class);
            foreach ($order->stock_sync_deductions ?? [] as $deduction) {
                $group = SkuSyncGroup::findOrFail($deduction['group_id']);
                if ($status === 'CANCELLED' && ! $order->stock_sync_reverted_at) {
                    $sync->releaseOrderStock($group, $deduction['quantity'], $order);
                }
                if (in_array($status, ['SHIPPED', 'COMPLETED'], true) && ! $order->stock_sync_shipped_at) {
                    $sync->shipOrderStock($group, $deduction['quantity'], $order);
                }
            }
            $raw = $order->raw_data;
            $raw['payment_status'] = $input['payment_status'];
            foreach (['financial_account_id', 'paid_amount', 'payment_received_at', 'tracking_number', 'shipping_provider'] as $key) {
                if (array_key_exists($key, $input)) $raw[$key] = $input[$key];
            }
            $order->forceFill(['order_status' => $status, 'raw_data' => $raw,
                'cancelled_at' => $status === 'CANCELLED' ? ($order->cancelled_at ?? now()) : null,
                'stock_sync_reverted_at' => $status === 'CANCELLED' ? ($order->stock_sync_reverted_at ?? now()) : null,
                'stock_sync_shipped_at' => in_array($status, ['SHIPPED', 'COMPLETED'], true) ? ($order->stock_sync_shipped_at ?? now()) : null,
            ])->saveQuietly();
            app(BundleInventoryService::class)->refreshAffectedByGroupIds(collect($order->stock_sync_deductions)->pluck('group_id'));
            $this->reconcilePayables($order->fresh(['orderProducts', 'store']), app(PayableService::class));
            app(ManualOrderPaymentService::class)->sync($order);

            return $order;
        });
    }

    public function reconcilePayables(Order $order, PayableService $periods): void
    {
        if ($order->platform !== 'Manual') {
            return;
        }
        DB::transaction(function () use ($order, $periods) {
            DB::table('users')->where('id', $order->store->user_id)->lockForUpdate()->first();
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->writePayables($order, $periods);
        });
    }

    private function writePayables(Order $order, PayableService $periods): void
    {
        $userId = $order->store->user_id;
        // The cancellation is written atomically. Keep any later manual moves
        // of its credits instead of generating replacements at the old supplier.
        if ($order->order_status === 'CANCELLED' && PayableEvent::where('user_id', $userId)
            ->where('source_id', $order->order_sn)->where('source_type', 'MANUAL_CANCEL')->exists()) {
            return;
        }
        $totals = [];
        foreach ($order->orderProducts as $item) {
            foreach ($item->manual_snapshot['suppliers'] ?? [] as $id => $amount) {
                $totals[$id] = ($totals[$id] ?? 0) + $amount * $item->quantity_purchased;
            }
        }
        $existingEvents = PayableEvent::where('user_id', $userId)->where('source_id', $order->order_sn)->where('source_type', 'CREATE_ORDER')->get();
        if ($existingEvents->contains('is_manual_moved', true)) {
            $totals = $existingEvents->groupBy('supplier_id')->map(fn ($events) => (float) $events->sum('amount'))->all();
        }
        foreach ($totals as $id => $amount) {
            $supplier = Supplier::where('user_id', $userId)->find($id);
            if (! $supplier) {
                continue;
            }
            $existing = PayableEvent::where('user_id', $userId)->where('source_id', $order->order_sn)->where('source_type', 'CREATE_ORDER')->where('supplier_id', $id)->first();
            $period = $existing?->period ?? $periods->getPeriodForDate($order->order_time, $userId, $supplier);
            if (! $period) {
                continue;
            }
            $key = ['user_id' => $userId, 'source_id' => $order->order_sn, 'supplier_id' => $id];
            if ($order->order_status === 'CANCELLED') {
                if (! $existing) {
                    continue;
                }
                if (! in_array($period->payment_status, ['PAID', 'SETTLED'], true) && ! $period->is_closed && ! $period->payments()->exists()) {
                    $existing->delete();

                    continue;
                }
                $cancelDate = $order->cancelled_at ?? now();
                $targetDate = $cancelDate->gt($period->end_date) ? $cancelDate : $period->end_date->copy()->addSecond();
                $target = $periods->getPeriodForDate($targetDate, $userId, $supplier);
                if (! $target) {
                    $this->fail('Periode koreksi supplier tidak tersedia. Lengkapi periode supplier terlebih dahulu.');
                }
                if ($target) {
                    PayableEvent::firstOrCreate([...$key, 'source_type' => 'MANUAL_CANCEL'], ['payable_period_id' => $target->id,
                        'store_id' => $order->store_id, 'platform' => 'Manual', 'event_date' => $cancelDate,
                        'amount' => -abs((float) $existing->amount), 'notes' => 'Pembatalan pesanan manual yang sudah dibayar ke supplier.']);
                }

                continue;
            }
            PayableEvent::firstOrCreate([...$key, 'source_type' => 'CREATE_ORDER'], ['payable_period_id' => $period->id,
                'store_id' => $order->store_id, 'platform' => 'Manual', 'event_date' => $order->order_time, 'amount' => $amount]);
        }
    }
}
