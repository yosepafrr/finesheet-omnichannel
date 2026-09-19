<?php

namespace Tests\Unit;

use App\Services\TiktokStoreResolver;
use PHPUnit\Framework\TestCase;

class TiktokStoreResolverTest extends TestCase
{
    public function test_it_matches_the_numeric_shop_id_from_a_webhook(): void
    {
        $shop = [
            'id' => '7494049642642441621',
            'cipher' => 'ROW_EXAMPLE',
        ];

        $this->assertTrue(TiktokStoreResolver::authorizedShopMatches('7494049642642441621', $shop));
        $this->assertFalse(TiktokStoreResolver::authorizedShopMatches('7494049642642441622', $shop));
    }

    public function test_it_also_matches_a_shop_cipher(): void
    {
        $shop = [
            'id' => '7494049642642441621',
            'cipher' => 'ROW_EXAMPLE',
        ];

        $this->assertTrue(TiktokStoreResolver::authorizedShopMatches('ROW_EXAMPLE', $shop));
        $this->assertFalse(TiktokStoreResolver::authorizedShopMatches('', $shop));
    }
}
