<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\TiktokService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokShippingDocumentTest extends TestCase
{
    public function test_combined_shipping_document_parameters_are_sent_to_tiktok(): void
    {
        config()->set('services.tiktok.api_url', 'https://open-api.example.test');
        config()->set('services.tiktok.app_key', 'app-key');
        config()->set('services.tiktok.app_secret', 'app-secret');
        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'data' => ['doc_url' => 'https://example.test/document.pdf'],
            ]),
        ]);

        $service = new class extends TiktokService
        {
            public function ensureValidToken(Store $store)
            {
                return 'access-token';
            }
        };

        $service->getPackageShippingDocument(
            new Store(['shopee_shop_id' => 'SHOP-CIPHER']),
            'PACKAGE-1',
            'A5',
            'SHIPPING_LABEL_AND_PACKING_SLIP',
        );

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/fulfillment/202309/packages/PACKAGE-1/shipping_documents')
                && ($query['document_type'] ?? null) === 'SHIPPING_LABEL_AND_PACKING_SLIP'
                && ($query['document_size'] ?? null) === 'A5'
                && ($query['document_format'] ?? null) === 'PDF';
        });
    }
}
