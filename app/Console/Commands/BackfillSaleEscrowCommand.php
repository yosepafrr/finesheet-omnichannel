<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\HistoricalSaleEscrowEstimator;
use App\Services\OrderEscrowService;
use Illuminate\Console\Command;

class BackfillSaleEscrowCommand extends Command
{
    protected $signature = 'profit:backfill-sale-escrow
        {--user_id= : Batasi ke satu pengguna}
        {--store_id= : Batasi ke satu toko}
        {--order_sn= : Perbaiki satu nomor pesanan}
        {--dry-run : Tampilkan hasil tanpa menyimpan}
        {--force : Hitung ulang snapshot yang sebelumnya masih berupa estimasi}';

    protected $description = 'Reconstruct missing pre-return sale escrow snapshots for historical orders';

    public function handle(
        HistoricalSaleEscrowEstimator $estimator,
        OrderEscrowService $escrowService,
    ): int {
        $query = Order::query()
            ->with(['store', 'orderProducts', 'returns.items', 'packages'])
            ->when($this->option('user_id'), fn ($query, $userId) => $query
                ->whereHas('store', fn ($storeQuery) => $storeQuery->where('user_id', (int) $userId)))
            ->when($this->option('store_id'), fn ($query, $storeId) => $query
                ->where('store_id', (int) $storeId))
            ->when($this->option('order_sn'), fn ($query, $orderSn) => $query
                ->where('order_sn', trim((string) $orderSn)))
            ->where(function ($query) {
                $query->whereHas('returns')
                    ->orWhereHas('packages', fn ($packageQuery) => $packageQuery
                        ->where('normalized_logistics_status', 'DELIVERY_FAILED'));
            });

        if ($this->option('force')) {
            $query->where(function ($query) {
                $query->whereNull('sale_escrow_amount')
                    ->orWhere('sale_escrow_amount', '<=', 0)
                    ->orWhere('sale_escrow_is_estimated', true);
            });
        } else {
            $query->where(function ($query) {
                $query->whereNull('sale_escrow_amount')
                    ->orWhere('sale_escrow_amount', '<=', 0);
            });
        }

        $orders = $query->orderBy('id')->get()
            ->filter(fn (Order $order) => $escrowService->hasReturnOrFailedDelivery($order)
                && ! $escrowService->isPreShipmentCancellation($order))
            ->values();

        if ($orders->isEmpty()) {
            $this->info('Tidak ada escrow penjualan historis yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $estimates = $estimator->estimates($orders, (bool) $this->option('force'));
        $updated = 0;
        $unresolved = 0;
        $sources = [];

        foreach ($orders as $order) {
            $estimate = $estimates->get($order->id);
            if (! $estimate || (float) $estimate['amount'] <= 0) {
                $unresolved++;
                $this->warn("{$order->order_sn}: escrow historis belum dapat direkonstruksi.");

                continue;
            }

            $source = (string) $estimate['source'];
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $label = $estimate['is_estimated'] ? 'estimasi' : 'platform';
            $sampleInfo = $estimate['sample_size'] > 0
                ? ", {$estimate['sample_size']} sampel"
                : '';
            $this->line(sprintf(
                '%s: Rp %s (%s, %s%s)',
                $order->order_sn,
                number_format((float) $estimate['amount'], 0, ',', '.'),
                $source,
                $label,
                $sampleInfo,
            ));

            if (! $this->option('dry-run')) {
                $order->forceFill([
                    'sale_escrow_amount' => $estimate['amount'],
                    'sale_escrow_source' => $source,
                    'sale_escrow_is_estimated' => $estimate['is_estimated'],
                    'sale_escrow_sample_size' => $estimate['sample_size'],
                    'sale_escrow_ratio' => $estimate['ratio'],
                ])->saveQuietly();
            }

            $updated++;
        }

        $verb = $this->option('dry-run') ? 'Ditemukan' : 'Diperbarui';
        $this->newLine();
        $this->info("{$verb} {$updated} pesanan; {$unresolved} belum dapat direkonstruksi.");
        foreach ($sources as $source => $count) {
            $this->line("- {$source}: {$count}");
        }

        return self::SUCCESS;
    }
}
