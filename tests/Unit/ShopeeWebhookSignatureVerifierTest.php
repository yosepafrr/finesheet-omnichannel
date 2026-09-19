<?php

namespace Tests\Unit;

use App\Services\ShopeeWebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

class ShopeeWebhookSignatureVerifierTest extends TestCase
{
    public function test_it_uses_the_configured_public_url_behind_a_reverse_proxy(): void
    {
        $key = 'test-partner-key';
        $publicUrl = 'https://finesheet.id/webhook/shopee';
        $body = '{"code":0,"shop_id":12345}';
        $signature = hash_hmac('sha256', $publicUrl.'|'.$body, $key);
        $verifier = new ShopeeWebhookSignatureVerifier($key, $publicUrl);

        $this->assertTrue($verifier->verify(
            $body,
            $signature,
            ['http://finesheet.id/webhook/shopee']
        ));
    }

    public function test_it_accepts_a_sha256_prefixed_signature(): void
    {
        $key = 'test-partner-key';
        $url = 'https://finesheet.id/webhook/shopee';
        $body = '{"code":0}';
        $signature = hash_hmac('sha256', $url.'|'.$body, $key);
        $verifier = new ShopeeWebhookSignatureVerifier($key, $url);

        $this->assertTrue($verifier->verify($body, 'sha256='.$signature));
        $this->assertTrue($verifier->verify($body, 'SHA256 '.$signature));
    }

    public function test_it_rejects_missing_or_invalid_signatures(): void
    {
        $verifier = new ShopeeWebhookSignatureVerifier(
            'test-partner-key',
            'https://finesheet.id/webhook/shopee'
        );

        $this->assertFalse($verifier->verify('{"code":0}', null));
        $this->assertFalse($verifier->verify('{"code":0}', 'invalid'));
    }

    public function test_it_reports_signature_strategy_without_exposing_the_signature(): void
    {
        $key = 'test-partner-key';
        $url = 'https://finesheet.id/webhook/shopee';
        $body = '{"code":0}';
        $signature = hash_hmac('sha256', $body, $key);
        $verifier = new ShopeeWebhookSignatureVerifier($key, $url);

        $diagnostics = $verifier->diagnostics($body, $signature);

        $this->assertSame(['hmac_body'], $diagnostics['matches']);
        $this->assertSame(strlen($body), $diagnostics['body_length']);
        $this->assertArrayNotHasKey('signature', $diagnostics);
    }
}
