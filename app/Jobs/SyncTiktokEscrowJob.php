<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Order;
use App\Models\Store;
use App\Services\TiktokService;

class SyncTiktokEscrowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $storeId;
    protected $orderId;
    protected $status;
    protected $originalTotalProductPrice;

    /**
     * Create a new job instance.
     */
    public function __construct($storeId, $orderId, $status, $originalTotalProductPrice = 0)
    {
        $this->storeId = $storeId;
        $this->orderId = $orderId;
        $this->status = $status;
        $this->originalTotalProductPrice = $originalTotalProductPrice;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
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

        $tiktok = new TiktokService();
        $tiktok->ensureValidToken($store);

        $escrowAmount = $this->originalTotalProductPrice;
        $feeDetails = null;

        try {
            // Check if order is completed / settled
            // TikTok status can be COMPLETED, SHIPPED, AWAITING_SHIPMENT, CANCELLED
            if (strtoupper($this->status) === 'COMPLETED') {
                $statementRes = $tiktok->getStatementTransaction($store, $this->orderId);
                
                $transactions = $statementRes['data']['statement_transactions'] ?? [];
                if (!empty($transactions)) {
                    $feeDetails = $transactions; // Save the raw transaction details
                    // Usually there's one transaction per order, or multiple if split. We sum them up.
                    $sumSettlement = 0;
                    foreach ($transactions as $txn) {
                        $sumSettlement += floatval($txn['settlement_amount'] ?? 0);
                    }
                    $escrowAmount = $sumSettlement;
                }
            } else {
                $unsettledRes = $tiktok->getUnsettledTransaction($store, $this->orderId);
                
                // Get the total est_settlement_amount for this order
                $transactions = $unsettledRes['data']['transactions'] ?? [];
                if (!empty($transactions)) {
                    // Extract only the relevant transactions for this order
                    $orderTxns = [];
                    // Sum up the est_settlement_amount for this specific order
                    $sumEstSettlement = 0;
                    foreach ($transactions as $txn) {
                        if (isset($txn['order_id']) && $txn['order_id'] == $this->orderId) {
                            $orderTxns[] = $txn;
                            $sumEstSettlement += floatval($txn['est_settlement_amount'] ?? 0);
                        }
                    }
                    if (!empty($orderTxns)) {
                        $feeDetails = $orderTxns;
                    }
                    if ($sumEstSettlement > 0) {
                        $escrowAmount = $sumEstSettlement;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("TikTok Escrow Sync Error", [
                'order_id' => $this->orderId,
                'message' => $e->getMessage()
            ]);
            // Escrow will fallback to $this->originalTotalProductPrice
        }

        // Update the order in DB
        $orderModel->update([
            'escrow_amount' => $escrowAmount,
            'fee_details' => $feeDetails,
        ]);

        Log::info("TikTok Escrow Synced", [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'final_escrow' => $escrowAmount
        ]);
    }
}
