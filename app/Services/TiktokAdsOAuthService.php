<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TiktokAdsOAuthService
{
    public function isConfigured(): bool
    {
        return collect([
            config('services.tiktok_ads.app_id'),
            config('services.tiktok_ads.app_secret'),
            config('services.tiktok_ads.authorization_url'),
            config('services.tiktok_ads.redirect_uri'),
            config('services.tiktok_ads.api_url'),
            config('services.tiktok_ads.api_version'),
        ])->every(fn ($value) => filled($value));
    }

    public function authorizationUrl(string $state): string
    {
        $url = trim((string) config('services.tiktok_ads.authorization_url'));

        if ($url === '') {
            throw new RuntimeException('TikTok Ads authorization URL belum dikonfigurasi.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('TikTok Ads authorization URL tidak valid.');
        }

        parse_str($parts['query'] ?? '', $query);
        $query['state'] = $state;

        $authority = $parts['scheme'].'://';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':'.$parts['pass'];
            }
            $authority .= '@';
        }

        $authority .= $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $authority
            .($parts['path'] ?? '')
            .'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @return array{access_token: string, advertiser_ids: array<int, string>, scopes: array<int, string>, raw: array<string, mixed>}
     */
    public function exchangeAuthorizationCode(string $authorizationCode): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Konfigurasi TikTok Ads belum lengkap.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(2, 500, throw: false)
                ->post($this->endpoint('/oauth2/access_token/'), [
                    'app_id' => (string) config('services.tiktok_ads.app_id'),
                    'secret' => (string) config('services.tiktok_ads.app_secret'),
                    'auth_code' => $authorizationCode,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tidak dapat terhubung ke TikTok Ads API.', previous: $exception);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Respons TikTok Ads tidak valid.');
        }

        $code = (int) ($payload['code'] ?? -1);
        $accessToken = trim((string) data_get($payload, 'data.access_token', ''));
        if (! $response->successful() || $code !== 0 || $accessToken === '') {
            $message = trim((string) ($payload['message'] ?? $payload['msg'] ?? 'unknown error'));

            throw new RuntimeException('TikTok Ads API menolak otorisasi: '.$message);
        }

        $advertiserIds = $this->normalizeStringList(data_get($payload, 'data.advertiser_ids', []));
        if ($advertiserIds === []) {
            throw new RuntimeException('TikTok Ads tidak mengembalikan advertiser account yang diotorisasi.');
        }

        return [
            'access_token' => $accessToken,
            'advertiser_ids' => $advertiserIds,
            'scopes' => $this->normalizeStringList(data_get($payload, 'data.scope', [])),
            'raw' => $payload,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.tiktok_ads.api_url'), '/')
            .'/'.trim((string) config('services.tiktok_ads.api_version'), '/')
            .'/'.ltrim($path, '/');
    }

    /** @return array<int, string> */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
