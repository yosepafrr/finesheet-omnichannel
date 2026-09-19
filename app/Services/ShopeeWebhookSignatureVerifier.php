<?php

namespace App\Services;

class ShopeeWebhookSignatureVerifier
{
    private string $partnerKey;

    private ?string $webhookUrl;

    public function __construct(?string $partnerKey = null, ?string $webhookUrl = null)
    {
        $this->partnerKey = $partnerKey ?? (string) config('shopee.live_push_partner_key', '');
        $this->webhookUrl = $webhookUrl ?? config('shopee.webhook_url');
    }

    public function verify(string $rawBody, ?string $signature, array $requestUrls = []): bool
    {
        $signature = $this->normalizeSignature($signature);

        if ($this->partnerKey === '' || ! $signature) {
            return false;
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        foreach ($this->candidateUrls($requestUrls) as $url) {
            $calculated = hash_hmac('sha256', $url.'|'.$rawBody, $this->partnerKey);

            if (hash_equals(strtolower($calculated), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    public function diagnostics(string $rawBody, ?string $signature, array $requestUrls = []): array
    {
        $signature = $this->normalizeSignature($signature);
        $matches = [];

        if ($this->partnerKey === '' || ! $signature || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return ['matches' => $matches, 'body_length' => strlen($rawBody)];
        }

        $candidates = [
            'hmac_body' => hash_hmac('sha256', $rawBody, $this->partnerKey),
        ];

        foreach ($this->candidateUrls($requestUrls) as $url) {
            $urlVariants = [$url];
            $urlVariants[] = str_ends_with($url, '/') ? rtrim($url, '/') : $url.'/';

            foreach (array_unique($urlVariants) as $urlVariant) {
                $suffix = $urlVariant === $url ? 'exact_url' : 'alternate_trailing_slash';
                $candidates["hmac_url_pipe_body:{$suffix}"] = hash_hmac(
                    'sha256',
                    $urlVariant.'|'.$rawBody,
                    $this->partnerKey
                );
                $candidates["hmac_url_body:{$suffix}"] = hash_hmac(
                    'sha256',
                    $urlVariant.$rawBody,
                    $this->partnerKey
                );
                $candidates["sha256_key_url_pipe_body:{$suffix}"] = hash(
                    'sha256',
                    $this->partnerKey.$urlVariant.'|'.$rawBody
                );
                $candidates["sha256_url_pipe_body_key:{$suffix}"] = hash(
                    'sha256',
                    $urlVariant.'|'.$rawBody.$this->partnerKey
                );
            }
        }

        foreach ($candidates as $strategy => $candidate) {
            if (hash_equals(strtolower($candidate), strtolower($signature))) {
                $matches[] = $strategy;
            }
        }

        return [
            'matches' => array_values(array_unique($matches)),
            'body_length' => strlen($rawBody),
        ];
    }

    private function normalizeSignature(?string $signature): ?string
    {
        if (! $signature) {
            return null;
        }

        $signature = trim($signature);

        return preg_replace('/^sha256(?:=|\s+)\s*/i', '', $signature) ?? $signature;
    }

    private function candidateUrls(array $requestUrls): array
    {
        return array_values(array_unique(array_filter([
            $this->webhookUrl,
            ...$requestUrls,
        ])));
    }
}
