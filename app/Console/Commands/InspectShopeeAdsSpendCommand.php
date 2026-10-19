<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ShopeeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class InspectShopeeAdsSpendCommand extends Command
{
    protected $signature = 'shopee:ads-spend
        {--store_id= : Internal store ID}
        {--date= : Performance date in YYYY-MM-DD, defaults to yesterday}
        {--raw : Print the complete Shopee response}';

    protected $description = 'Read Shopee CPC ads spend for a shop and date without changing ads data';

    public function handle(ShopeeService $shopee): int
    {
        try {
            $date = $this->parseDate();
        } catch (Throwable) {
            $this->error('Tanggal harus menggunakan format YYYY-MM-DD.');

            return self::FAILURE;
        }

        $stores = Store::query()
            ->whereRaw('LOWER(platform) = ?', ['shopee'])
            ->when($this->option('store_id'), fn ($query, $storeId) => $query->whereKey((int) $storeId))
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->warn('Tidak ada toko Shopee yang ditemukan.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($stores as $store) {
            try {
                $result = $shopee->getAdsHourlyPerformance($store, $date);
                $error = trim((string) ($result['error'] ?? ''));
                $records = $result['response'] ?? [];
                $records = is_array($records) ? $records : [];
                $expense = collect($records)->sum(
                    fn ($record) => is_array($record) ? (float) ($record['expense'] ?? 0) : 0,
                );

                if ($error !== '') {
                    $failed = true;
                }

                $rows[] = [
                    $store->id,
                    $store->store_name,
                    $store->shopee_shop_id,
                    $date->toDateString(),
                    number_format($expense, 2, ',', '.'),
                    count($records),
                    $error !== '' ? $error.': '.($result['message'] ?? '-') : 'OK',
                ];

                if ($this->option('raw')) {
                    $this->newLine();
                    $this->line("Respons {$store->store_name}:");
                    $this->line(json_encode(
                        $result,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ));
                }
            } catch (Throwable $exception) {
                $failed = true;
                $rows[] = [
                    $store->id,
                    $store->store_name,
                    $store->shopee_shop_id,
                    $date->toDateString(),
                    '-',
                    0,
                    $exception->getMessage(),
                ];
            }
        }

        $this->table(
            ['Store ID', 'Toko', 'Shop ID', 'Tanggal', 'Ads Spend', 'Data per Jam', 'Status'],
            $rows,
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function parseDate(): Carbon
    {
        $value = trim((string) $this->option('date'));
        if ($value === '') {
            return Carbon::yesterday('Asia/Jakarta');
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return $date;
    }
}
