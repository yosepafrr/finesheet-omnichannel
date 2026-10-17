<?php

namespace Tests\Unit;

use App\Jobs\SyncTiktokReturnJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TiktokReturnStatusTest extends TestCase
{
    #[DataProvider('cancelledStatuses')]
    public function test_it_normalizes_cancelled_return_requests(string $status): void
    {
        $this->assertSame('CANCELLED', SyncTiktokReturnJob::normalizeStatus($status));
    }

    public static function cancelledStatuses(): array
    {
        return [
            'platform request cancel status' => ['RETURN_OR_REFUND_REQUEST_CANCEL'],
            'legacy cancel status' => ['RETURN_OR_REFUND_CANCEL'],
            'american spelling' => ['RETURN_OR_REFUND_REQUEST_CANCELED'],
            'british spelling' => ['RETURN_OR_REFUND_REQUEST_CANCELLED'],
            'generic cancelled status' => ['CANCELLED'],
        ];
    }
}
