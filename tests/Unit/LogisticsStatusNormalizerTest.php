<?php

namespace Tests\Unit;

use App\Services\LogisticsStatusNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogisticsStatusNormalizerTest extends TestCase
{
    #[DataProvider('failedDeliveryPayloads')]
    public function test_it_recognizes_failed_delivery_payloads(array $payload): void
    {
        $normalizer = new LogisticsStatusNormalizer;

        $this->assertSame('DELIVERY_FAILED', $normalizer->normalize($payload));
    }

    public static function failedDeliveryPayloads(): array
    {
        return [
            'official TikTok tracking shape' => [[
                'tracking' => [[
                    'description' => 'Package is returning to sender',
                    'update_time_millis' => 1_725_000_000_000,
                    'action_code' => 30901,
                ]],
            ]],
            'machine status' => [['logistics_status' => 'DELIVERY_FAILED']],
            'Indonesian cancellation reason' => [['cancel_reason' => 'Pengiriman paket gagal']],
            'Indonesian tracking warning' => [['description' => 'Dikembalikan kepada penjual']],
        ];
    }

    public function test_failed_delivery_wins_over_an_older_delivered_event(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [
                ['description' => 'Your package was delivered', 'update_time_millis' => 100],
                ['description' => 'Package return in progress', 'update_time_millis' => 200],
            ],
        ];

        $this->assertSame('DELIVERY_FAILED', $normalizer->normalize($payload));
        $this->assertSame('Package return in progress', $normalizer->latestDescription($payload));
    }

    public function test_regular_buyer_cancellation_is_not_failed_delivery(): void
    {
        $normalizer = new LogisticsStatusNormalizer;

        $this->assertFalse($normalizer->isFailedDelivery('Pesanan dibatalkan karena pembeli terlambat membayar'));
    }
}
