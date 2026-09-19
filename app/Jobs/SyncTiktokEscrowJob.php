<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Store;
use App\Services\TiktokEscrowAmountResolver;
use App\Services\TiktokService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTiktokEscrowJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public $uniqueFor = 3600;

    protected $storeId;

    protected $orderId;

    protected $status;

    protected $fallbackSalePrice;

    protected $statementOnly = false;

    // Retained so jobs serialized before this release can still be decoded.
    protected $originalTotalProductPrice;

    /**
     * Create a new job instance.
     */
    public function __construct($storeId, $orderId, $status, $fallbackSalePrice = 0, $statementOnly = false)
    {
        $this->storeId = $storeId;
        $this->orderId = $orderId;
        $this->status = $status;
        $this->fallbackSalePrice = $fallbackSalePrice;
        $this->statementOnly = (bool) $statementOnly;
        $this->originalTotalProductPrice = null;
    }

    public function uniqueId(): string
    {
        return implode(':', [
            $this->storeId,
            $this->orderId,
            strtoupper((string) $this->status),
        ]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Execute the job.
     */
    public function handle(
        TiktokService $tiktok,
        TiktokEscrowAmountResolver $resolver
    ): void {
        $store = Store::find($this->storeId);
        if (! $store || $store->platform !== 'Tiktokshop') {
            Log::warning('TikTok Escrow Sync Failed: Store not found or invalid', ['store_id' => $this->storeId]);

            return;
        }

        $orderModel = Order::query()
            ->where('store_id', $store->id)
            ->where('platform', 'Tiktokshop')
            ->where('order_sn', $this->orderId)
            ->first();
        if (! $orderModel) {
            Log::warning('TikTok Escrow Sync Failed: Order not found in DB', ['order_id' => $this->orderId]);

            return;
        }

        $tiktok->ensureValidToken($store);

        $existingFeeDetails = $orderModel->fee_details;
        $hasValidExistingAmount = $resolver->hasValidStoredAmount(
            $existingFeeDetails,
            $orderModel->escrow_amount
        );
        $escrowAmount = $hasValidExistingAmount
            ? (float) $orderModel->escrow_amount
            : $resolver->fallbackForOrder($orderModel);

        if (! $hasValidExistingAmount && $escrowAmount <= 0 && is_numeric($this->fallbackSalePrice)) {
            $escrowAmount = (float) $this->fallbackSalePrice;
        }

        $financeResult = null;
        $financeResponse = null;
        $currentStatus = strtoupper((string) ($orderModel->order_status ?: $this->status));

        try {
            if ($resolver->shouldTryStatement($currentStatus)) {
                $financeResponse = $tiktok->getStatementTransaction($store, $this->orderId);
                $financeResult = $resolver->settled($financeResponse);
            }

            // Direct/manual syncs can still fall back to the shop-wide unsettled
            // list. Batch reconciliation has already fetched that list once.
            if ($financeResult === null && ! $this->statementOnly) {
                $financeResponse = $tiktok->getUnsettledTransaction($store, $this->orderId);
                $financeResult = $resolver->unsettled($financeResponse, (string) $this->orderId);
            }
        } catch (\Throwable $e) {
            Log::error('TikTok Escrow Sync Error', [
                'order_id' => $this->orderId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($financeResult !== null) {
            $escrowAmount = $financeResult['amount'];
        } else {
            Log::warning('TikTok finance data is not available; keeping the best fallback', [
                'order_id' => $this->orderId,
                'response_code' => $financeResponse['code'] ?? null,
                'response_message' => $financeResponse['message'] ?? null,
            ]);
        }

        $orderModel->update([
            'escrow_amount' => $escrowAmount,
            'fee_details' => $financeResult['details']
                ?? ($hasValidExistingAmount ? $existingFeeDetails : null),
        ]);

        Log::info('TikTok Escrow Synced', [
            'order_id' => $this->orderId,
            'status' => $currentStatus,
            'source' => $financeResult['details']['source']
                ?? $existingFeeDetails['source']
                ?? 'sale_price_fallback',
            'final_escrow' => $escrowAmount,
        ]);
    }
}
