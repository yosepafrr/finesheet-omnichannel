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
            'official TikTok 202604 tracking shape' => [[
                'order_id' => '585780442699957833',
                'logistics_details' => [[
                    'newest_tracking_no' => 'JY1587607104',
                    'track_list' => [[
                        'description' => 'Package is returning to sender',
                        'update_time_millis' => 1_725_000_000_000,
                        'action_code_name' => 'return_to_sender',
                    ]],
                ]],
            ]],
            'machine status' => [['logistics_status' => 'DELIVERY_FAILED']],
            'Indonesian cancellation reason' => [['cancel_reason' => 'Pengiriman paket gagal']],
            'Indonesian tracking warning' => [['description' => 'Dikembalikan kepada penjual']],
            'TikTok failed delivery action code' => [[
                'tracking' => [[
                    'action_code' => 40601,
                    'description' => 'Localized description not recognized by text rules',
                ]],
            ]],
            'TikTok return journey action code' => [[
                'tracking' => [[
                    'action_code' => 70204,
                    'description' => 'Localized return update',
                ]],
            ]],
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

    public function test_it_reads_the_latest_tracking_number_from_tiktok_202604_payload(): void
    {
        $normalizer = new LogisticsStatusNormalizer();
        $payload = [
            'logistics_details' => [[
                'newest_tracking_no' => 'JY1587607104',
                'track_list' => [['tracking_no' => 'OLD123']],
            ]],
        ];

        $this->assertSame('JY1587607104', $normalizer->trackingNumber($payload));
    }
}
