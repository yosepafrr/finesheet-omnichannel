<?php

namespace App\Http\Controllers;

use App\Models\TiktokAdAccount;
use App\Models\TiktokAdsConnection;
use App\Services\TiktokAdsOAuthService;
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
                $connection = TiktokAdsConnection::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'access_token' => $token['access_token'],
                        'advertiser_ids' => $token['advertiser_ids'],
                        'scopes' => $token['scopes'],
                        'status' => 'active',
                        'authorized_at' => now(),
                        'last_error' => null,
                    ],
                );

                TiktokAdAccount::query()
                    ->where('user_id', $user->id)
                    ->whereNotIn('advertiser_id', $token['advertiser_ids'])
                    ->update(['is_active' => false]);

                $automaticStoreId = null;
                if (count($token['advertiser_ids']) === 1) {
                    $tiktokStores = $user->stores()
                        ->whereRaw('LOWER(platform) = ?', ['tiktokshop'])
                        ->pluck('id');

                    if ($tiktokStores->count() === 1) {
                        $automaticStoreId = (int) $tiktokStores->first();
                    }
                }

                foreach ($token['advertiser_ids'] as $advertiserId) {
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

                return count($token['advertiser_ids']);
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
        $connection = TiktokAdsConnection::query()
            ->where('user_id', $request->user()->id)
            ->with(['accounts' => fn ($query) => $query
                ->where('is_active', true)
                ->with('store:id,store_name,platform')
                ->orderBy('advertiser_id')])
            ->first();

        return response()->json([
            'configured' => $oauth->isConfigured(),
            'connected' => $connection?->status === 'active',
            'connection' => $connection ? [
                'status' => $connection->status,
                'authorized_at' => $connection->authorized_at?->toIso8601String(),
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'last_error' => $connection->last_error,
            ] : null,
            'accounts' => $connection?->accounts->map(fn (TiktokAdAccount $account) => [
                'id' => $account->id,
                'advertiser_id' => $account->advertiser_id,
                'advertiser_name' => $account->advertiser_name,
                'currency' => $account->currency,
                'timezone' => $account->timezone,
                'store' => $account->store ? [
                    'id' => $account->store->id,
                    'name' => $account->store->store_name,
                    'platform' => $account->store->platform,
                ] : null,
            ])->values()->all() ?? [],
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
        }

        $account->update(['store_id' => $storeId]);

        return response()->json([
            'message' => $storeId
                ? 'Advertiser account berhasil dipetakan ke toko.'
                : 'Pemetaan toko advertiser account dilepas.',
            'account' => $account->fresh('store:id,store_name,platform'),
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
