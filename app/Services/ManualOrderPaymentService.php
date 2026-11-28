<?php

namespace App\Services;

use App\Models\CashflowTransaction;
use App\Models\FinancialAccount;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ManualOrderPaymentService
{
    // Called within the order transaction, after locking its owner and order.
    public function sync(Order $order): void
    {
        $userId = $order->store->user_id;
        $raw = $order->raw_data;
        $total = round((float) $order->order_selling_price + (float) ($raw['shipping_fee'] ?? 0), 2);
        $status = $raw['payment_status'] ?? 'UNPAID';
        $received = match ($status) {
            'PAID' => $total,
            'PARTIAL' => round((float) ($raw['paid_amount'] ?? 0), 2),
            default => 0.0,
        };
        if ($status === 'PARTIAL' && ($received <= 0 || $received >= $total)) {
            throw ValidationException::withMessages(['paid_amount' => 'Pembayaran sebagian harus lebih dari nol dan kurang dari total pesanan.']);
        }

        $receipt = CashflowTransaction::where('user_id', $userId)->where('manual_order_id', $order->id)->lockForUpdate()->first();
        $targetId = ($raw['financial_account_id'] ?? null) ? (int) $raw['financial_account_id'] : null;
        $date = $received > 0 ? Carbon::parse($raw['payment_received_at'] ?? $receipt?->occurred_at ?? now()) : null;
        $raw['paid_amount'] = $received;
        $raw['financial_account_id'] = $targetId;
        $raw['payment_received_at'] = $date?->toIso8601String();
        unset($raw['revenue_amount']);
        $order->forceFill(['raw_data' => $raw])->saveQuietly();

        if ($receipt && $targetId === $receipt->financial_account_id && $received === (float) $receipt->amount
            && $date?->equalTo($receipt->occurred_at)) {
            return;
        }

        $accounts = FinancialAccount::where('user_id', $userId)
            ->whereIn('id', array_filter([$targetId, $receipt?->financial_account_id]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $target = $targetId ? $accounts->get($targetId) : null;
        if ($targetId && (! $target || $target->category !== 'cash' || $target->source_type || ! $target->is_active)) {
            throw ValidationException::withMessages(['financial_account_id' => 'Pilih akun Posisi Kas aktif milik Anda yang bukan portofolio terintegrasi.']);
        }

        $oldAccount = $accounts->get($receipt?->financial_account_id);
        // Reconcile the single linked receipt instead of adding another income
        // every time shipping/payment status is saved.
        if ($oldAccount) {
            $oldAccount->balance = round((float) $oldAccount->balance - (float) $receipt->amount, 2);
        }
        if ($target && $received > 0) {
            $target->balance = round((float) $target->balance + $received, 2);
        }
        foreach ($accounts as $account) {
            if ($account->isDirty('balance')) {
                $account->save();
            }
        }

        if (! $target || $received <= 0) {
            $receipt?->delete();

            return;
        }
        $receipt ??= new CashflowTransaction(['user_id' => $userId, 'manual_order_id' => $order->id]);
        $receipt->fill([
            'financial_account_id' => $target->id, 'direction' => 'income', 'category' => 'Penjualan manual',
            'description' => 'Pembayaran '.$order->order_sn,
            'amount' => $received, 'occurred_at' => $date,
            'notes' => 'Pembeli: '.($raw['buyer_name'] ?? '-'),
        ])->save();
    }
}
