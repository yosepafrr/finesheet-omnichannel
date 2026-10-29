<?php

namespace App\Services;

use App\Models\TiktokAdsConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TiktokAdsApiService
{
    /** @return array<string, mixed> */
    public function advertiserInfo(TiktokAdsConnection $connection, string $advertiserId): array
    {
        $payload = $this->get($connection, '/advertiser/info/', [
            'advertiser_ids' => [$advertiserId],
            'fields' => ['advertiser_id', 'name', 'currency', 'timezone'],
        ]);

        $record = collect(data_get($payload, 'data.list', []))
            ->first(fn ($item) => (string) ($item['advertiser_id'] ?? '') === $advertiserId);

        return is_array($record) ? $record : [];
    }

    /**
     * @param  array<int, string>  $dimensions
     * @param  array<int, string>  $metrics
     * @return array<int, array<string, mixed>>
     */
    public function report(
        TiktokAdsConnection $connection,
        string $advertiserId,
        string $dataLevel,
        array $dimensions,
        array $metrics,
        string $startDate,
        string $endDate,
    ): array {
        $records = [];
        $page = 1;

        do {
            $payload = $this->get($connection, '/report/integrated/get/', [
                'advertiser_id' => $advertiserId,
                'service_type' => 'AUCTION',
                'report_type' => 'BASIC',
                'data_level' => $dataLevel,
                'dimensions' => $dimensions,
                'metrics' => $metrics,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'page' => $page,
                'page_size' => 1000,
            ]);

            $pageRecords = data_get($payload, 'data.list', []);
            if (is_array($pageRecords)) {
                array_push($records, ...array_values(array_filter($pageRecords, 'is_array')));
            }

            $totalPages = max(1, (int) data_get($payload, 'data.page_info.total_page', 1));
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    /** @param array<string, mixed> $query */
    private function get(
        TiktokAdsConnection $connection,
        string $path,
        array $query,
    ): array {
        $encodedQuery = collect($query)->mapWithKeys(function ($value, $key) {
            return [$key => is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value];
        })->all();

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Access-Token' => $connection->access_token])
                ->timeout(45)
                ->retry(2, 750, throw: false)
                ->get($this->endpoint($path), $encodedQuery);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tidak dapat terhubung ke TikTok Ads API.', previous: $exception);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Respons TikTok Ads tidak valid.');
        }

        if (! $response->successful() || (int) ($payload['code'] ?? -1) !== 0) {
            $message = trim((string) ($payload['message'] ?? $payload['msg'] ?? 'unknown error'));
            $requestId = trim((string) ($payload['request_id'] ?? ''));

            throw new RuntimeException(
                'TikTok Ads API error: '.$message.($requestId !== '' ? " (request {$requestId})" : ''),
            );
        }

        return $payload;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.tiktok_ads.api_url'), '/')
            .'/'.trim((string) config('services.tiktok_ads.api_version'), '/')
            .'/'.ltrim($path, '/');
    }
}
