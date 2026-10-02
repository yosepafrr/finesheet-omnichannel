<?php

namespace Tests\Unit;

use App\Services\OrderCancellationMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderCancellationMapperTest extends TestCase
{
    #[DataProvider('sellerLateReasons')]
    public function test_it_recognizes_seller_late_cancellation_reasons(
        string $platform,
        string $source,
        string $reason,
    ): void {
        $this->assertSame(
            'SELLER_LATE_SHIPMENT',
            OrderCancellationMapper::normalize($platform, $source, $reason),
        );
    }

    public static function sellerLateReasons(): array
    {
        return [
            'TikTok Indonesian pickup timeout' => [
                'Tiktokshop',
                'SYSTEM',
                'Otomatis dibatalkan karena melewati batas waktu pick up',
            ],
            'TikTok Indonesian shipping timeout' => [
                'Tiktokshop',
                'SYSTEM',
                'Pesanan melewati batas waktu pengiriman',
            ],
            'Shopee Indonesian seller delay' => [
                'Shopee',
                'SHOPEE',
                'Penjual terlambat mengirim pesanan',
            ],
        ];
    }
}
