<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Order;
use App\Models\Store;
use App\Services\TiktokEscrowAmountResolver;
use App\Services\TiktokService;

class SyncTiktokEscrowJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $uniqueFor = 3600;

    protected $storeId;
    protected $orderId;
    protected $status;
    protected $fallbackSalePrice;
    // Retained so jobs serialized before this release can still be decoded.
    protected $originalTotalProductPrice;

    /**
     * Create a new job instance.
     */
    public function __construct($storeId, $orderId, $status, $fallbackSalePrice = 0)
    {
        $this->storeId = $storeId;
        $this->orderId = $orderId;
        $this->status = $status;
        $this->fallbackSalePrice = $fallbackSalePrice;
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

    /**
     * Execute the job.
     */
    public function handle(
        TiktokService $tiktok,
        TiktokEscrowAmountResolver $resolver
    ): void
    {
        $store = Store::find($this->storeId);
        if (!$store || $store->platform !== 'Tiktokshop') {
            Log::warning("TikTok Escrow Sync Failed: Store not found or invalid", ['store_id' => $this->storeId]);
            return;
        }

        $orderModel = Order::where('order_sn', $this->orderId)->first();
        if (!$orderModel) {
            Log::warning("TikTok Escrow Sync Failed: Order not found in DB", ['order_id' => $this->orderId]);
            return;
        }

        $tiktok->ensureValidToken($store);

        $existingFeeDetails = $orderModel->fee_details;
        $escrowAmount = empty($existingFeeDetails)
            ? $resolver->fallbackForOrder($orderModel)
            : (float) $orderModel->escrow_amount;

        if (empty($existingFeeDetails) && $escrowAmount <= 0 && is_numeric($this->fallbackSalePrice)) {
            $escrowAmount = (float) $this->fallbackSalePrice;
        }

        $financeResult = null;
        $financeResponse = null;

        try {
            if (strtoupper($this->status) === 'COMPLETED') {
                $financeResponse = $tiktok->getStatementTransaction($store, $this->orderId);
                $financeResult = $resolver->settled($financeResponse);
            }

            // A completed order can briefly remain in TikTok's unsettled list,
            // so use it when a statement is not available yet.
            if ($financeResult === null) {
                $financeResponse = $tiktok->getUnsettledTransaction($store, $this->orderId);
                $financeResult = $resolver->unsettled($financeResponse, (string) $this->orderId);
            }
        } catch (\Throwable $e) {
            Log::error("TikTok Escrow Sync Error", [
                'order_id' => $this->orderId,
                'message' => $e->getMessage()
            ]);
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
            'fee_details' => $financeResult['details'] ?? $existingFeeDetails,
        ]);

        Log::info("TikTok Escrow Synced", [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'source' => $financeResult['details']['source']
                ?? $existingFeeDetails['source']
                ?? 'sale_price_fallback',
            'final_escrow' => $escrowAmount,
        ]);
    }
}
