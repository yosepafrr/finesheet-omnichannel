<?php

namespace App\Console\Commands;

use App\Jobs\SyncTiktokAdsSpendJob;
use App\Models\TiktokAdAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncTiktokAdsSpendCommand extends Command
{
    protected $signature = 'sync:tiktok-ads
        {--days=366 : Number of calendar days to sync, including today}
        {--account_id= : Only sync one internal TikTok ad account ID}
        {--queue=orders-low : Queue used for ads spend jobs}';

    protected $description = 'Queue TikTok Ads daily spend and campaign synchronization';

    public function handle(): int
    {
        $days = max(1, min(366, (int) $this->option('days')));
        $queue = (string) $this->option('queue');

        if (! in_array($queue, ['orders', 'orders-low'], true)) {
            $this->error('Queue harus orders atau orders-low.');

            return self::FAILURE;
        }

        $accounts = TiktokAdAccount::query()
            ->where('is_active', true)
            ->whereNotNull('store_id')
            ->when($this->option('account_id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->whereHas('connection', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->get(['id', 'advertiser_id']);

        if ($accounts->isEmpty()) {
            $this->warn('Tidak ada akun TikTok Ads aktif yang sudah dipetakan ke toko.');

            return self::SUCCESS;
        }

        $end = Carbon::today('Asia/Jakarta');
        $start = $end->copy()->subDays($days - 1);

        foreach ($accounts as $account) {
            SyncTiktokAdsSpendJob::dispatch(
                (int) $account->id,
                $start->toDateString(),
                $end->toDateString(),
            )->onQueue($queue);
        }

        $this->info(sprintf(
            '%d sinkronisasi spend dan campaign TikTok Ads (%s s.d. %s) dimasukkan ke antrean %s.',
            $accounts->count(),
            $start->toDateString(),
            $end->toDateString(),
            $queue,
        ));

        return self::SUCCESS;
    }
}
