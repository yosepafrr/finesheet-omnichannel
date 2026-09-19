<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Store;
use App\Services\ShopeeService;
use App\Services\TiktokService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RefreshStoreTokensCommand extends Command
{
    protected $signature = 'tokens:refresh {--force : Force refresh all store tokens immediately regardless of expiry}';
    protected $description = 'Proactively check and refresh access tokens for connected marketplace stores before expiry';

    public function handle()
    {
        $this->info('Starting store tokens refresh check...');
        Log::info('RefreshStoreTokensCommand started');

        $stores = Store::all();
        if ($stores->isEmpty()) {
            $this->warn('No stores found in database.');
            return 0;
        }

        $shopeeService = new ShopeeService();
        $tiktokService = new TiktokService();
        $now = Carbon::now('Asia/Jakarta');
        $force = $this->option('force');

        $refreshedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($stores as $store) {
            $platform = strtolower($store->platform);
            $tokenExpiredAt = $store->token_expired_at ? Carbon::parse($store->token_expired_at) : null;
            $needsRefresh = false;
            $reason = '';

            if ($force) {
                $needsRefresh = true;
                $reason = 'Force option enabled';
            } elseif (!$tokenExpiredAt) {
                $needsRefresh = true;
                $reason = 'token_expired_at is null or empty';
            } elseif ($now->gte($tokenExpiredAt)) {
                $needsRefresh = true;
                $reason = 'Token has already expired at ' . $tokenExpiredAt->toDateTimeString();
            } else {
                // Proactive thresholds
                if ($platform === 'shopee') {
                    // Shopee token lasts 4 hours -> refresh if <= 60 minutes remaining
                    if ($now->copy()->addMinutes(60)->gte($tokenExpiredAt)) {
                        $needsRefresh = true;
                        $remaining = $now->diffInMinutes($tokenExpiredAt, false);
                        $reason = "Expires in {$remaining} minutes (<= 60m threshold)";
                    }
                } elseif ($platform === 'tiktokshop' || $platform === 'tiktok') {
                    // TikTok token lasts 7 days -> refresh if <= 24 hours remaining
                    if ($now->copy()->addHours(24)->gte($tokenExpiredAt)) {
                        $needsRefresh = true;
                        $remaining = $now->diffInHours($tokenExpiredAt, false);
                        $reason = "Expires in {$remaining} hours (<= 24h threshold)";
                    }
                }
            }

            if (!$needsRefresh) {
                $diffText = $tokenExpiredAt ? $now->diffForHumans($tokenExpiredAt, true) : 'unknown';
                $this->line(" [SKIPPED] [{$store->platform}] Store #{$store->id} '{$store->store_name}' token is valid for another {$diffText} (expires {$tokenExpiredAt})");
                $skippedCount++;
                continue;
            }

            $this->info(" [REFRESHING] [{$store->platform}] Store #{$store->id} '{$store->store_name}' ({$reason})...");

            try {
                $success = false;
                if ($platform === 'shopee') {
                    $success = $shopeeService->refreshAccessToken($store, (bool) $force);
                } elseif ($platform === 'tiktokshop' || $platform === 'tiktok') {
                    $res = $tiktokService->refreshAccessToken($store);
                    $success = !empty($res);
                }

                if ($success) {
                    $store->refresh();
                    $this->info("   -> SUCCESS: New token expires at {$store->token_expired_at}");
                    Log::info("RefreshStoreTokensCommand: successfully refreshed token for Store #{$store->id} ({$store->platform})", [
                        'store_id' => $store->id,
                        'new_token_expired_at' => $store->token_expired_at,
                    ]);
                    $refreshedCount++;
                } else {
                    $this->error("   -> FAILED to refresh token for Store #{$store->id}");
                    Log::error("RefreshStoreTokensCommand: failed to refresh token for Store #{$store->id} ({$store->platform})");
                    $failedCount++;
                }
            } catch (\Throwable $e) {
                $this->error("   -> EXCEPTION: {$e->getMessage()}");
                Log::error("RefreshStoreTokensCommand exception for Store #{$store->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("Finished refresh check. Refreshed: {$refreshedCount}, Skipped: {$skippedCount}, Failed: {$failedCount}");
        Log::info("RefreshStoreTokensCommand completed: {$refreshedCount} refreshed, {$skippedCount} skipped, {$failedCount} failed.");

        return $failedCount > 0 ? 1 : 0;
    }
}
