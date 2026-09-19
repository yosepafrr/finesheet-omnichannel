<?php

namespace App\Services;

class ShopeeWebhookSignatureVerifier
{
    private string $partnerKey;

    private ?string $webhookUrl;

    public function __construct(?string $partnerKey = null, ?string $webhookUrl = null)
    {
        $this->partnerKey = $partnerKey ?? (string) config('shopee.partner_key', '');
        $this->webhookUrl = $webhookUrl ?? config('shopee.webhook_url');
    }

    public function verify(string $rawBody, ?string $signature, array $requestUrls = []): bool
    {
        if ($this->partnerKey === '' || ! $signature) {
            return false;
        }

        $signature = trim($signature);
        $signature = preg_replace('/^sha256(?:=|\s+)\s*/i', '', $signature) ?? $signature;

        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $urls = array_values(array_unique(array_filter([
            $this->webhookUrl,
            ...$requestUrls,
        ])));

        foreach ($urls as $url) {
            $calculated = hash_hmac('sha256', $url.'|'.$rawBody, $this->partnerKey);

            if (hash_equals(strtolower($calculated), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
