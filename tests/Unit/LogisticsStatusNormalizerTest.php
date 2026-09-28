<?php

namespace Tests\Unit;

use App\Services\LogisticsStatusNormalizer;
use Carbon\Carbon;
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

    public function test_retryable_delivery_attempt_is_not_a_final_failure(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [[
                'action_code' => 40601,
                'description' => 'An attempt to deliver your package failed for the following reason: Customer could not be contacted. Our carrier will try to deliver it again.',
                'update_time_millis' => 1_790_160_600_000,
            ]],
        ];

        $this->assertSame('IN_TRANSIT', $normalizer->normalize($payload));
        $this->assertFalse($normalizer->isFailedDelivery($payload));
        $this->assertNull($normalizer->failedDeliveryOccurredAt($payload));
        $this->assertFalse($normalizer->history($payload)[0]['is_failed_delivery']);
    }

    public function test_newer_delivered_event_corrects_a_failed_attempt_and_stale_status(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [
                [
                    'action_code' => 40601,
                    'description' => 'Delivery attempt failed. Our carrier will try to deliver it again.',
                    'update_time_millis' => 100,
                ],
                [
                    'action_code' => 50101,
                    'description' => 'Your package was delivered.',
                    'update_time_millis' => 200,
                ],
            ],
        ];

        $this->assertSame('DELIVERED', $normalizer->normalize($payload, 'DELIVERY_FAILED'));
        $this->assertFalse($normalizer->isFailedDelivery($payload));
        $this->assertNull($normalizer->failedDeliveryOccurredAt($payload));
    }

    public function test_retry_timestamp_is_replaced_by_first_final_failure_and_then_stays_fixed(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [
                [
                    'action_code' => 70202,
                    'description' => 'Return package left the delivery station.',
                    'update_time_millis' => 1_801_154_160_000,
                ],
                [
                    'action_code' => 70201,
                    'description' => 'Your package is being returned to the seller.',
                    'update_time_millis' => 1_801_094_280_000,
                ],
                [
                    'action_code' => 40601,
                    'description' => 'An attempt to deliver your package failed. Our carrier will try to deliver it again.',
                    'update_time_millis' => 1_801_000_920_000,
                ],
            ],
        ];

        $retryDate = Carbon::createFromTimestampMs(1_801_000_920_000);
        $firstFinalDate = Carbon::createFromTimestampMs(1_801_094_280_000);

        $this->assertTrue($normalizer->isRetryableDeliveryTimestamp($payload, $retryDate));
        $this->assertSame(
            $firstFinalDate->getTimestamp(),
            $normalizer->resolveFailedDeliveryOccurredAt($payload, $retryDate)?->getTimestamp()
        );
        $this->assertSame(
            $firstFinalDate->getTimestamp(),
            $normalizer->resolveFailedDeliveryOccurredAt($payload, $firstFinalDate)?->getTimestamp()
        );
    }

    public function test_regular_buyer_cancellation_is_not_failed_delivery(): void
    {
        $normalizer = new LogisticsStatusNormalizer;

        $this->assertFalse($normalizer->isFailedDelivery('Pesanan dibatalkan karena pembeli terlambat membayar'));
    }

    public function test_it_reads_the_latest_tracking_number_from_tiktok_202604_payload(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'logistics_details' => [[
                'newest_tracking_no' => 'JY1587607104',
                'track_list' => [['tracking_no' => 'OLD123']],
            ]],
        ];

        $this->assertSame('JY1587607104', $normalizer->trackingNumber($payload));
    }

    public function test_it_uses_the_first_failed_delivery_tracking_timestamp(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [
                [
                    'action_code' => 70204,
                    'description' => 'Return package left the sorting center.',
                    'update_time_millis' => 1_789_303_517_000,
                ],
                [
                    'action_code' => 40601,
                    'description' => 'Package can no longer be delivered.',
                    'update_time_millis' => 1_789_018_876_000,
                ],
            ],
        ];

        $date = $normalizer->failedDeliveryOccurredAt($payload);

        $this->assertNotNull($date);
        $this->assertSame(1_789_018_876, $date->getTimestamp());
    }

    public function test_it_builds_a_unique_newest_first_tracking_history(): void
    {
        $normalizer = new LogisticsStatusNormalizer;
        $payload = [
            'tracking' => [
                [
                    'action_code' => 40101,
                    'description' => 'Package arrived at delivery hub.',
                    'update_time' => '2026-09-10T08:00:00+07:00',
                ],
                [
                    'action_code' => 40601,
                    'description' => 'Package can no longer be delivered.',
                    'update_time_millis' => 1_789_018_876_000,
                ],
                [
                    'action_code' => 40601,
                    'description' => 'Package can no longer be delivered.',
                    'update_time_millis' => 1_789_018_876_000,
                ],
            ],
        ];

        $history = $normalizer->history($payload);

        $this->assertCount(2, $history);
        $this->assertSame('Package can no longer be delivered.', $history[0]['description']);
        $this->assertTrue($history[0]['is_failed_delivery']);
        $this->assertNotNull($history[0]['occurred_at']);
        $this->assertFalse($history[1]['is_failed_delivery']);
        $this->assertStringContainsString('2026-09-10', $history[1]['occurred_at']);
    }
}
