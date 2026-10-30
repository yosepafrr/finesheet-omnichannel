<?php

namespace App\Http\Controllers;

use App\Jobs\SyncTiktokAdsSpendJob;
use App\Models\TiktokAdAccount;
use App\Models\TiktokAdCampaignSpend;
use App\Models\TiktokAdsConnection;
use App\Services\TiktokAdsOAuthService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TiktokAdsController extends Controller
{
    private const OAUTH_SESSION_KEY = 'tiktok_ads.oauth';

    public function redirectToTiktokAds(
        Request $request,
        TiktokAdsOAuthService $oauth,
    ): RedirectResponse {
        if (! $oauth->isConfigured()) {
            return redirect('/#/profit-tracker')->with(
                'error',
                'Konfigurasi TikTok Ads belum lengkap atau aplikasi masih menunggu approval.',
            );
        }

        $state = Str::random(64);
        $request->session()->put(self::OAUTH_SESSION_KEY, [
            'state_hash' => hash('sha256', $state),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        try {
            return redirect()->away($oauth->authorizationUrl($state));
        } catch (\Throwable $exception) {
            Log::error('TikTok Ads authorization redirect failed', [
                'user_id' => $request->user()->id,
                'message' => $exception->getMessage(),
            ]);

            return redirect('/#/profit-tracker')->with(
                'error',
                'Otorisasi TikTok Ads belum dapat dimulai. Periksa konfigurasi aplikasi.',
            );
        }
    }

    public function handleTiktokAdsCallback(
        Request $request,
        TiktokAdsOAuthService $oauth,
    ): RedirectResponse {
        $oauthSession = $request->session()->pull(self::OAUTH_SESSION_KEY);
        $state = (string) $request->query('state', '');

        if (! $this->hasValidState($oauthSession, $state)) {
            Log::warning('TikTok Ads callback rejected because OAuth state is invalid', [
                'user_id' => $request->user()->id,
                'has_state' => $state !== '',
            ]);

            return redirect('/#/profit-tracker')->with(
                'error',
                'Sesi otorisasi TikTok Ads tidak valid atau sudah kedaluwarsa. Silakan coba lagi.',
            );
        }

        $authorizationCode = trim((string) (
            $request->query('auth_code') ?: $request->query('code', '')
        ));

        if ($authorizationCode === '') {
            Log::warning('TikTok Ads callback did not include an authorization code', [
                'user_id' => $request->user()->id,
                'error' => $request->query('error'),
                'description' => $request->query('error_description'),
            ]);

            return redirect('/#/profit-tracker')->with(
                'error',
                'Otorisasi TikTok Ads dibatalkan atau kode otorisasi tidak diterima.',
            );
        }

        try {
            $token = $oauth->exchangeAuthorizationCode($authorizationCode);
            $accountCount = DB::transaction(function () use ($request, $token): int {
                $user = $request->user();
                $advertiserIds = collect($token['advertiser_ids'])
                    ->map(fn ($advertiserId) => trim((string) $advertiserId))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $connection = TiktokAdsConnection::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (TiktokAdsConnection $candidate) => count(array_intersect(
                        $advertiserIds,
                        array_map('strval', $candidate->advertiser_ids ?? []),
                    )) > 0);

                if (! $connection) {
                    $connection = new TiktokAdsConnection(['user_id' => $user->id]);
                }

                $connection->fill([
                    'access_token' => $token['access_token'],
                    'advertiser_ids' => $advertiserIds,
                    'scopes' => $token['scopes'],
                    'status' => 'active',
                    'authorized_at' => now(),
                    'last_error' => null,
                ])->save();

                TiktokAdAccount::query()
                    ->where('tiktok_ads_connection_id', $connection->id)
                    ->whereNotIn('advertiser_id', $advertiserIds)
                    ->update(['is_active' => false]);

                $automaticStoreId = null;
                if (count($advertiserIds) === 1) {
                    $tiktokStores = $user->stores()
                        ->whereRaw('LOWER(platform) = ?', ['tiktokshop'])
                        ->pluck('id');

                    if ($tiktokStores->count() === 1) {
                        $automaticStoreId = (int) $tiktokStores->first();
                    }
                }

                foreach ($advertiserIds as $advertiserId) {
                    $account = TiktokAdAccount::query()->firstOrNew([
                        'user_id' => $user->id,
                        'advertiser_id' => $advertiserId,
                    ]);

                    $account->fill([
                        'tiktok_ads_connection_id' => $connection->id,
                        'is_active' => true,
                        'last_error' => null,
                    ]);

                    if (! $account->store_id && $automaticStoreId) {
                        $account->store_id = $automaticStoreId;
                    }

                    $account->save();
                }

                return count($advertiserIds);
            });

            Log::info('TikTok Ads authorization saved', [
                'user_id' => $request->user()->id,
                'advertiser_count' => $accountCount,
            ]);

            return redirect('/#/profit-tracker')->with(
                'success',
                "TikTok Ads berhasil terhubung ({$accountCount} akun iklan).",
            );
        } catch (\Throwable $exception) {
            Log::error('TikTok Ads callback failed', [
                'user_id' => $request->user()->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            TiktokAdsConnection::query()
                ->where('user_id', $request->user()->id)
                ->update([
                    'last_error' => Str::limit($exception->getMessage(), 1000),
                ]);

            return redirect('/#/profit-tracker')->with(
                'error',
                'Otorisasi TikTok Ads gagal diproses: '.$exception->getMessage(),
            );
        }
    }

    public function status(Request $request, TiktokAdsOAuthService $oauth): JsonResponse
    {
        $connections = TiktokAdsConnection::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderByDesc('authorized_at')
            ->get();
        $connection = $connections->first();
        $accounts = TiktokAdAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereHas('connection', fn ($query) => $query->where('status', 'active'))
            ->with('store:id,store_name,platform')
            ->orderBy('advertiser_id')
            ->get();

        return response()->json([
            'configured' => $oauth->isConfigured(),
            'connected' => $connections->isNotEmpty(),
            'connection' => $connection ? [
                'status' => $connection->status,
                'authorized_at' => $connection->authorized_at?->toIso8601String(),
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'last_error' => $connection->last_error,
            ] : null,
            'connection_count' => $connections->count(),
            'accounts' => $accounts->map(fn (TiktokAdAccount $account) => [
                'id' => $account->id,
                'advertiser_id' => $account->advertiser_id,
                'advertiser_name' => $account->advertiser_name,
                'currency' => $account->currency,
                'timezone' => $account->timezone,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                'last_error' => $account->last_error,
                'store' => $account->store ? [
                    'id' => $account->store->id,
                    'name' => $account->store->store_name,
                    'platform' => $account->store->platform,
                ] : null,
            ])->values()->all(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ]);
        $days = (int) ($validated['days'] ?? 366);
        $accounts = TiktokAdAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereNotNull('store_id')
            ->when(
                $validated['account_id'] ?? null,
                fn ($query, $accountId) => $query->whereKey((int) $accountId),
            )
            ->whereHas('connection', fn ($query) => $query->where('status', 'active'))
            ->get(['id']);

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages([
                'account_id' => 'Petakan minimal satu advertiser account ke toko TikTok Shop terlebih dahulu.',
            ]);
        }

        $end = Carbon::today('Asia/Jakarta');
        $start = $end->copy()->subDays($days - 1);

        foreach ($accounts as $account) {
            SyncTiktokAdsSpendJob::dispatch(
                (int) $account->id,
                $start->toDateString(),
                $end->toDateString(),
            )->onQueue('orders-low');
        }

        return response()->json([
            'message' => sprintf(
                '%d sinkronisasi spend dan campaign TikTok Ads dimasukkan ke antrean.',
                $accounts->count(),
            ),
            'queued_accounts' => $accounts->count(),
            'account_ids' => $accounts->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ], 202);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer'],
        ]);
        $end = isset($validated['end'])
            ? Carbon::createFromFormat('!Y-m-d', $validated['end'], 'Asia/Jakarta')
            : Carbon::today('Asia/Jakarta');
        $start = isset($validated['start'])
            ? Carbon::createFromFormat('!Y-m-d', $validated['start'], 'Asia/Jakarta')
            : $end->copy()->subDays(29);

        $campaigns = TiktokAdCampaignSpend::query()
            ->whereHas('adAccount', fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->when(
                    $validated['store_id'] ?? null,
                    fn ($accountQuery, $storeId) => $accountQuery->where('store_id', (int) $storeId),
                ))
            ->whereHas('adSpend', fn ($query) => $query
                ->whereDate('spend_date', '>=', $start->toDateString())
                ->whereDate('spend_date', '<=', $end->toDateString()))
            ->selectRaw('campaign_id, MAX(campaign_name) as campaign_name, SUM(amount) as amount, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('campaign_id')
            ->orderByDesc('amount')
            ->limit(100)
            ->get();

        return response()->json([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'campaigns' => $campaigns,
        ]);
    }

    public function updateStore(Request $request, int $accountId): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer'],
        ]);

        $account = TiktokAdAccount::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($accountId)
            ->firstOrFail();

        $storeId = $validated['store_id'] ?? null;
        if ($storeId !== null) {
            $store = $request->user()->stores()
                ->whereKey($storeId)
                ->whereRaw('LOWER(platform) = ?', ['tiktokshop'])
                ->first();

            if (! $store) {
                throw ValidationException::withMessages([
                    'store_id' => 'Pilih toko TikTok Shop milik akun ini.',
                ]);
            }

            $alreadyMapped = TiktokAdAccount::query()
                ->where('user_id', $request->user()->id)
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->whereKeyNot($account->id)
                ->exists();

            if ($alreadyMapped) {
                throw ValidationException::withMessages([
                    'store_id' => 'Toko ini sudah dipetakan ke advertiser account lain.',
                ]);
            }
        }

        $account->update(['store_id' => $storeId]);

        return response()->json([
            'message' => $storeId
                ? 'Advertiser account berhasil dipetakan ke toko.'
                : 'Pemetaan toko advertiser account dilepas.',
            'account' => $account->fresh('store:id,store_name,platform'),
        ]);
    }

    public function destroyAccount(Request $request, int $accountId): JsonResponse
    {
        $account = TiktokAdAccount::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($accountId)
            ->firstOrFail();

        $account->update([
            'store_id' => null,
            'is_active' => false,
            'last_error' => null,
        ]);

        return response()->json([
            'message' => 'Advertiser account dihapus dari Finesheet. Otorisasi di TikTok tidak dicabut.',
        ]);
    }

    private function hasValidState(mixed $oauthSession, string $state): bool
    {
        if (! is_array($oauthSession) || $state === '') {
            return false;
        }

        $stateHash = (string) ($oauthSession['state_hash'] ?? '');
        $expiresAt = (int) ($oauthSession['expires_at'] ?? 0);

        return $stateHash !== ''
            && $expiresAt >= now()->timestamp
            && hash_equals($stateHash, hash('sha256', $state));
    }
}
