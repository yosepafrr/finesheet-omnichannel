<?php

namespace Tests\Unit;

use App\Services\MasterProductClusterService;
use PHPUnit\Framework\TestCase;

class MasterProductClusterServiceTest extends TestCase
{
    public function test_it_groups_skus_by_the_first_two_segments(): void
    {
        $service = new MasterProductClusterService;

        $navy = $service->fromSku('jas-w-navy-xxl');
        $black = $service->fromSku('JAS-W-HTM-L');

        $this->assertSame('jas-w', $navy['product_key']);
        $this->assertSame('jas-w', $black['product_key']);
        $this->assertSame(['navy', 'xxl'], $navy['variant_keys']);
        $this->assertSame(['HTM', 'L'], $black['variant_labels']);
    }

    public function test_it_handles_short_and_irregular_skus_without_empty_clusters(): void
    {
        $service = new MasterProductClusterService;

        $this->assertSame([
            'product_key' => 'kaos',
            'product_label' => 'KAOS',
            'variant_keys' => [],
            'variant_labels' => [],
        ], $service->fromSku('  KAOS  '));

        $this->assertSame('jas-w', $service->fromSku('jas---w--navy')['product_key']);
        $this->assertNull($service->fromSku('')['product_key']);
    }
}
