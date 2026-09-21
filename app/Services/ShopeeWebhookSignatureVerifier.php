<?php

namespace App\Services;

class ShopeeWebhookSignatureVerifier
{
    private array $partnerKeys;

    private ?string $webhookUrl;

    public function __construct(
        ?string $partnerKey = null,
        ?string $webhookUrl = null,
        ?string $previousPartnerKey = null
    ) {
        $previousPartnerKey ??= app()->bound('config')
            ? (string) config('shopee.live_push_previous_partner_key', '')
            : '';

        $this->partnerKeys = array_values(array_unique(array_filter([
            $partnerKey ?? (string) config('shopee.live_push_partner_key', ''),
            $previousPartnerKey,
        ], fn (string $key) => $key !== '')));
        $this->webhookUrl = $webhookUrl ?? config('shopee.webhook_url');
    }

    public function verify(string $rawBody, ?string $signature, array $requestUrls = []): bool
    {
        $signature = $this->normalizeSignature($signature);

        if ($this->partnerKeys === [] || ! $signature) {
            return false;
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        foreach ($this->partnerKeys as $key) {
            foreach ($this->candidateUrls($requestUrls) as $url) {
                $calculated = hash_hmac('sha256', $url.'|'.$rawBody, $key);

                if (hash_equals(strtolower($calculated), strtolower($signature))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function diagnostics(string $rawBody, ?string $signature, array $requestUrls = []): array
    {
        $signature = $this->normalizeSignature($signature);
        $matches = [];

        if ($this->partnerKeys === [] || ! $signature || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return [
                'matches' => $matches,
                'body_length' => strlen($rawBody),
                'configured_key_slots' => count($this->partnerKeys),
            ];
        }

        $candidates = [];

        foreach ($this->partnerKeys as $keyIndex => $key) {
            $keySlot = $keyIndex === 0 ? 'current_key' : 'previous_key';
            $candidates["{$keySlot}:hmac_body"] = hash_hmac('sha256', $rawBody, $key);

            foreach ($this->candidateUrls($requestUrls) as $url) {
                $urlVariants = [$url];
                $urlVariants[] = str_ends_with($url, '/') ? rtrim($url, '/') : $url.'/';

                foreach (array_unique($urlVariants) as $urlVariant) {
                    $suffix = $urlVariant === $url ? 'exact_url' : 'alternate_trailing_slash';
                    $candidates["{$keySlot}:hmac_url_pipe_body:{$suffix}"] = hash_hmac(
                        'sha256',
                        $urlVariant.'|'.$rawBody,
                        $key
                    );
                    $candidates["{$keySlot}:hmac_url_body:{$suffix}"] = hash_hmac(
                        'sha256',
                        $urlVariant.$rawBody,
                        $key
                    );
                    $candidates["{$keySlot}:sha256_key_url_pipe_body:{$suffix}"] = hash(
                        'sha256',
                        $key.$urlVariant.'|'.$rawBody
                    );
                    $candidates["{$keySlot}:sha256_url_pipe_body_key:{$suffix}"] = hash(
                        'sha256',
                        $urlVariant.'|'.$rawBody.$key
                    );
                }
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
            'configured_key_slots' => count($this->partnerKeys),
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
