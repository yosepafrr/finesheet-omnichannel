<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class OrderFulfillmentService
{
    public function __construct(
        private readonly ShopeeService $shopee,
        private readonly TiktokService $tiktok,
    ) {}

    public function capabilities(Order $order): array
    {
        $platform = $this->platform($order);
        $status = strtoupper((string) $order->order_status);
        $processStatus = strtolower((string) $order->fulfillment_status);
        $isProcessing = in_array($processStatus, ['queued', 'processing', 'retrying'], true);
        $canArrange = ! $isProcessing && (
            ($platform === 'Shopee' && $status === 'READY_TO_SHIP')
            || ($platform === 'Tiktokshop' && $status === 'AWAITING_SHIPMENT')
        );
        $canPrint = ($platform === 'Shopee' && $status === 'PROCESSED')
            || ($platform === 'Tiktokshop' && $status === 'AWAITING_COLLECTION');

        return [
            'can_arrange' => $canArrange,
            'can_print' => $canPrint,
            'arrange_reason' => $canArrange
                ? null
                : ($isProcessing ? 'Pengaturan pengiriman sedang diproses.' : 'Pesanan sudah diatur atau belum siap diproses.'),
            'print_reason' => $canPrint ? null : 'Resi tersedia setelah pengiriman berhasil diatur.',
            'status' => $processStatus ?: null,
            'error' => $order->fulfillment_error,
            'requested_at' => $order->fulfillment_requested_at?->toIso8601String(),
            'completed_at' => $order->fulfillment_completed_at?->toIso8601String(),
        ];
    }

    public function options(Order $order): array
    {
        $order->loadMissing(['store', 'packages']);
        $this->assertArrangeStatus($order);
        if ($this->platform($order) === 'Tiktokshop') {
            $defaultMethod = $this->configuredMethod($order) === 'dropoff' ? 'DROP_OFF' : 'PICKUP';

            return [
                'platform' => 'Tiktokshop',
                'methods' => [
                    ['id' => 'PICKUP', 'label' => 'Pickup kurir'],
                    ['id' => 'DROP_OFF', 'label' => 'Antar ke gerai'],
                ],
                'default_method' => $defaultMethod,
                'configured_method' => strtolower($defaultMethod),
            ];
        }

        $packageNumber = $this->shopeePackageNumber($order);
        $response = $this->shopee->getShippingParameter($order->store, $order->order_sn, $packageNumber);
        $this->assertPlatformSuccess($response, 'Shopee');
        $info = data_get($response, 'response.info_needed') ?? [];
        $methods = [];

        // An empty requirements list still means the method is supported.
        if (array_key_exists('pickup', $info)) {
            $methods[] = ['id' => 'pickup', 'label' => 'Pickup kurir'];
        }
        if (array_key_exists('dropoff', $info)) {
            $methods[] = ['id' => 'dropoff', 'label' => 'Antar ke gerai'];
        }

        $configuredMethod = $this->configuredMethod($order);
        $availableMethodIds = collect($methods)->pluck('id');
        $defaultMethod = $availableMethodIds->contains($configuredMethod)
            ? $configuredMethod
            : ($methods[0]['id'] ?? null);

        return [
            'platform' => 'Shopee',
            'methods' => $methods,
            'default_method' => $defaultMethod,
            'configured_method' => $configuredMethod,
            'info_needed' => $info,
            'pickup' => data_get($response, 'response.pickup', []),
            'dropoff' => data_get($response, 'response.dropoff', []),
        ];
    }

    public function arrange(Order $order, array $selection = []): void
    {
        $order->loadMissing(['store', 'packages']);
        $this->assertArrangeStatus($order);

        if ($this->platform($order) === 'Tiktokshop') {
            $packageIds = $this->resolveTiktokPackageIds($order);
            $method = strtoupper((string) ($selection['method'] ?? $this->configuredMethod($order)));
            $payload = ['handover_method' => $method === 'DROPOFF' ? 'DROP_OFF' : $method];

            foreach ($packageIds as $packageId) {
                $response = $this->tiktok->shipPackage($order->store, $packageId, $payload);
                $this->assertPlatformSuccess($response, 'TikTok Shop');
            }

            return;
        }

        $options = $this->options($order);
        $method = strtolower((string) ($selection['method'] ?? $this->configuredMethod($order)));
        if (! collect($options['methods'])->pluck('id')->contains($method)) {
            $method = strtolower((string) ($options['default_method'] ?? ''));
        }
        $shipment = $this->buildShopeeShipment($options, $method, $selection);
        $response = $this->shopee->shipOrder(
            $order->store,
            $order->order_sn,
            $shipment,
            $this->shopeePackageNumber($order),
        );
        $this->assertPlatformSuccess($response, 'Shopee');
    }

    public function label(Order $order): array
    {
        $order->loadMissing(['store', 'packages', 'orderProducts']);
        $this->assertCapable($order, 'can_print');

        if (($order->store?->shipping_label_template ?? 'platform') === 'finesheet') {
            return [
                'type' => 'finesheet',
                'order' => $order,
                'shipping' => $this->shippingLabelData($order),
                'printing' => $this->printingSettings($order->store),
            ];
        }

        if ($this->platform($order) === 'Tiktokshop') {
            $packageId = $this->resolveTiktokPackageIds($order)[0] ?? null;
            if (! $packageId) {
                throw new RuntimeException('Package ID TikTok Shop belum tersedia. Sinkronkan pesanan terlebih dahulu.');
            }
            $response = $this->tiktok->getPackageShippingDocument(
                $order->store,
                $packageId,
                $this->tiktokDocumentSize($order->store),
                $this->tiktokDocumentType($order->store),
            );
            $this->assertPlatformSuccess($response, 'TikTok Shop');
            $url = data_get($response, 'data.doc_url')
                ?? data_get($response, 'data.document_url')
                ?? data_get($response, 'data.shipping_document_url');
            if (! $url) {
                throw new RuntimeException('TikTok Shop belum menyediakan dokumen resi untuk paket ini.');
            }

            return ['type' => 'redirect', 'url' => $url];
        }

        $documentOrder = ['order_sn' => $order->order_sn];
        $packageNumber = $this->shopeePackageNumber($order);
        if ($packageNumber !== '') {
            $documentOrder['package_number'] = $packageNumber;
        }

        $cached = $this->cachedPlatformLabel($order);
        if ($cached) {
            return $cached;
        }

        $trackingNumber = $this->localTrackingNumber($order);
        if ($trackingNumber === '') {
            $tracking = $this->shopee->getTrackingNumber($order->store, $order->order_sn, $packageNumber);
            $this->assertPlatformSuccess($tracking, 'Shopee');
            $trackingNumber = $this->shopeeTrackingNumber($tracking);
        }

        $parameters = $this->shopee->getShippingDocumentParameter($order->store, [$documentOrder]);
        $this->assertPlatformSuccess($parameters, 'Shopee');
        $suggestedDocumentType = $this->shopeeSuggestedShippingDocumentType($parameters);
        $documentOrder['shipping_document_type'] = $suggestedDocumentType;
        Log::info('Shopee shipping document type selected', [
            'order_sn' => $order->order_sn,
            'selected_type' => $documentOrder['shipping_document_type'],
            'suggested_type' => $suggestedDocumentType,
        ]);

        $createOrder = $documentOrder;
        if ($trackingNumber !== '') {
            $createOrder['tracking_number'] = $trackingNumber;
        }

        $created = $this->shopee->createShippingDocument($order->store, [$createOrder]);
        $this->assertPlatformSuccess($created, 'Shopee');

        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }
            $result = $this->shopee->getShippingDocumentResult($order->store, [$documentOrder]);
            $this->assertPlatformSuccess($result, 'Shopee');
            $status = strtoupper((string) data_get($result, 'response.result_list.0.status', ''));
            if (in_array($status, ['READY', 'SUCCESS'], true)) {
                $download = $this->shopee->downloadShippingDocument($order->store, [$documentOrder]);
                $document = $this->documentFromResponse($download, 'Shopee');
                $normalized = $this->normalizeSingleLabelDocument([
                    'type' => 'binary',
                    'body' => $document['body'],
                    'content_type' => $document['content_type'],
                    'filename' => "resi-shopee-{$order->order_sn}.pdf",
                ], 1);
                $printMode = str_contains($documentOrder['shipping_document_type'], 'THERMAL')
                    ? 'thermal'
                    : 'normal';

                $label = [
                    'type' => 'binary',
                    'body' => $normalized['body'],
                    'content_type' => 'application/pdf',
                    'filename' => "resi-shopee-{$printMode}-{$order->order_sn}.pdf",
                    'document_type' => $documentOrder['shipping_document_type'],
                ];

                $this->cachePlatformLabel($order, $label);

                return $label;
            }
            if (in_array($status, ['FAILED', 'ERROR'], true)) {
                throw new RuntimeException(data_get($result, 'response.result_list.0.fail_error') ?: 'Pembuatan resi Shopee gagal.');
            }
        }

        throw new RuntimeException('Resi Shopee masih diproses. Coba cetak kembali beberapa saat lagi.');
    }

    /** Prepare one PDF containing labels from every selected marketplace. */
    public function labels(iterable $orders): array
    {
        $orders = collect($orders)->values();
        if ($orders->isEmpty()) {
            throw new RuntimeException('Pilih minimal satu pesanan untuk mencetak resi.');
        }
        if ($orders->count() === 1) {
            return $this->label($orders->first());
        }

        $orders->each(function (Order $order) {
            $order->loadMissing(['store', 'packages', 'orderProducts']);
            $this->assertCapable($order, 'can_print');
        });

        if ($orders->contains(fn (Order $order) => ($order->store?->shipping_label_template ?? 'platform') !== 'platform')) {
            throw new RuntimeException('Cetak resi massal memerlukan template resi dari marketplace.');
        }

        $documents = collect();
        $shopeeOrders = $orders->filter(fn (Order $order) => $this->platform($order) === 'Shopee');
        foreach ($shopeeOrders->groupBy('store_id') as $storeOrders) {
            foreach ($this->shopeeLabelDocumentsForStore($storeOrders) as $document) {
                $documents->push($document);
            }
        }
        foreach ($orders->filter(fn (Order $order) => $this->platform($order) === 'Tiktokshop') as $order) {
            $documents->push($this->tiktokLabelDocument($order));
        }

        if ($documents->count() === 1) {
            return $this->normalizeSingleLabelDocument($documents->first(), $orders->count());
        }

        $sourceCount = $orders
            ->map(fn (Order $order) => $this->platform($order).':'.$order->store_id)
            ->unique()
            ->count();

        return $this->mergeShippingLabelPdfs($sourceCount, $documents, $orders->count());
    }

    /** @return array<int, array<string, mixed>> */
    private function shopeeLabelDocumentsForStore(iterable $orders): array
    {
        $orders = collect($orders)->values();
        $store = $orders->first()->store;
        $documentsByOrder = [];
        $uncachedOrders = $orders->filter(function (Order $order) use (&$documentsByOrder) {
            $cached = $this->cachedPlatformLabel($order);
            if ($cached) {
                $documentsByOrder[$order->order_sn] = $cached;

                return false;
            }

            return true;
        })->values();

        if ($uncachedOrders->isEmpty()) {
            return $orders
                ->map(fn (Order $order) => $documentsByOrder[$order->order_sn])
                ->all();
        }

        $baseOrders = [];
        $trackingNumbers = [];
        $ordersByNumber = [];
        foreach ($uncachedOrders as $order) {
            $baseOrder = ['order_sn' => $order->order_sn];
            $packageNumber = $this->shopeePackageNumber($order);
            if ($packageNumber !== '') {
                $baseOrder['package_number'] = $packageNumber;
            }
            $baseOrders[] = $baseOrder;
            $ordersByNumber[$order->order_sn] = $order;

            $trackingNumber = $this->localTrackingNumber($order);
            if ($trackingNumber === '') {
                $tracking = $this->shopee->getTrackingNumber($store, $order->order_sn, $packageNumber);
                $this->assertPlatformSuccess($tracking, 'Shopee');
                $trackingNumber = $this->shopeeTrackingNumber($tracking);
            }
            $trackingNumbers[$order->order_sn] = $trackingNumber;
        }

        $parameters = $this->shopee->getShippingDocumentParameter($store, $baseOrders);
        $this->assertPlatformSuccess($parameters, 'Shopee');

        $documentOrders = [];
        $createOrders = [];
        foreach ($baseOrders as $index => $baseOrder) {
            $parameter = $this->shopeeShippingDocumentParameter($parameters, $baseOrder, $index);
            $parameterResponse = ['response' => ['result_list' => [$parameter]]];
            $documentOrder = $baseOrder + [
                'shipping_document_type' => $this->shopeeSuggestedShippingDocumentType($parameterResponse),
            ];
            $createOrder = $documentOrder;
            $trackingNumber = $trackingNumbers[$baseOrder['order_sn']] ?? '';
            if ($trackingNumber !== '') {
                $createOrder['tracking_number'] = $trackingNumber;
            }

            $documentOrders[] = $documentOrder;
            $createOrders[] = $createOrder;
        }

        $created = $this->shopee->createShippingDocument($store, $createOrders);
        $this->assertPlatformSuccess($created, 'Shopee');

        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }

            $result = $this->shopee->getShippingDocumentResult($store, $documentOrders);
            $this->assertPlatformSuccess($result, 'Shopee');
            $resultItems = collect(Arr::wrap(data_get($result, 'response.result_list', [])));
            $statuses = $resultItems
                ->map(fn ($item) => strtoupper((string) data_get($item, 'status', '')))
                ->filter();

            if ($statuses->count() === count($documentOrders)
                && $statuses->every(fn (string $status) => in_array($status, ['READY', 'SUCCESS'], true))) {
                foreach ($documentOrders as $documentOrder) {
                    // Shopee rejects some otherwise printable packages when they
                    // are submitted together to the download endpoint.
                    $download = $this->shopee->downloadShippingDocument($store, [$documentOrder]);
                    $document = $this->documentFromResponse($download, 'Shopee');
                    $order = $ordersByNumber[$documentOrder['order_sn']];
                    $documentsByOrder[$order->order_sn] = $this->cachePlatformLabel($order, [
                        'type' => 'binary',
                        'body' => $document['body'],
                        'content_type' => $document['content_type'],
                        'filename' => 'resi-shopee-'.$documentOrder['order_sn'].'.pdf',
                    ]);
                }

                return $orders
                    ->map(fn (Order $order) => $documentsByOrder[$order->order_sn])
                    ->all();
            }

            $failed = $resultItems->first(fn ($item) => in_array(
                strtoupper((string) data_get($item, 'status', '')),
                ['FAILED', 'ERROR'],
                true,
            ));
            if ($failed) {
                throw new RuntimeException(
                    data_get($failed, 'fail_message')
                    ?: data_get($failed, 'fail_error')
                    ?: 'Pembuatan resi massal Shopee gagal.',
                );
            }
        }

        throw new RuntimeException('Resi massal Shopee masih diproses. Coba cetak kembali beberapa saat lagi.');
    }

    private function mergeShippingLabelPdfs(int $storeCount, $documents, int $orderCount): array
    {
        $temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'finesheet-labels-'.bin2hex(random_bytes(8));
        if (! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
            throw new RuntimeException('Direktori sementara untuk dokumen resi tidak dapat dibuat.');
        }

        try {
            $sourcePaths = [];
            foreach ($documents as $document) {
                foreach ($this->pdfBodiesFromDocument($document) as $pdfBody) {
                    $path = $temporaryDirectory.DIRECTORY_SEPARATOR
                        .sprintf('label-%04d.pdf', count($sourcePaths) + 1);
                    if (file_put_contents($path, $pdfBody) === false) {
                        throw new RuntimeException('Dokumen resi sementara tidak dapat dibuat.');
                    }
                    $sourcePaths[] = $path;
                }
            }

            $pdfUniteError = null;
            $body = $this->mergePdfFilesWithPdfUnite(
                $sourcePaths,
                $temporaryDirectory.DIRECTORY_SEPARATOR.'merged.pdf',
                $pdfUniteError,
            );

            if ($body === null) {
                try {
                    $body = $this->mergePdfFilesWithFpdi($sourcePaths);
                } catch (\Throwable $exception) {
                    Log::warning('Shipping label PDFs could not be merged', [
                        'store_count' => $storeCount,
                        'order_count' => $orderCount,
                        'pdfunite_error' => $pdfUniteError,
                        'fpdi_exception' => $exception::class,
                        'fpdi_error' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(
                        'Dokumen resi gagal digabungkan. Pastikan utilitas PDF server tersedia, lalu coba lagi.',
                    );
                }
            }

            return [
                'type' => 'binary',
                'body' => $body,
                'content_type' => 'application/pdf',
                'filename' => 'resi-massal-'.$storeCount.'-toko-'.$orderCount.'-pesanan.pdf',
            ];
        } finally {
            foreach (glob($temporaryDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($temporaryDirectory);
        }
    }

    private function mergePdfFilesWithPdfUnite(array $sourcePaths, string $outputPath, ?string &$error): ?string
    {
        $configuredBinary = trim((string) config('services.shipping_labels.pdfunite_binary'));
        $binary = $configuredBinary !== ''
            ? $configuredBinary
            : (new ExecutableFinder)->find('pdfunite');
        if (! $binary) {
            $error = 'pdfunite tidak ditemukan pada server.';

            return null;
        }

        try {
            $process = new Process(array_merge([$binary], $sourcePaths, [$outputPath]));
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput())
                    ?: "pdfunite berhenti dengan exit code {$process->getExitCode()}.";

                return null;
            }

            $body = is_file($outputPath) ? file_get_contents($outputPath) : false;
            if (! is_string($body) || ! str_starts_with($body, '%PDF-')) {
                $error = 'pdfunite tidak menghasilkan dokumen PDF yang valid.';

                return null;
            }

            return $body;
        } catch (\Throwable $exception) {
            $error = $exception::class.': '.$exception->getMessage();

            return null;
        }
    }

    private function mergePdfFilesWithFpdi(array $sourcePaths): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        foreach ($sourcePaths as $path) {
            $pageCount = $pdf->setSourceFile($path);
            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);
                $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template, 0, 0, $size['width'], $size['height'], true);
            }
        }

        return $pdf->Output('resi-massal.pdf', 'S');
    }

    private function tiktokLabelDocument(Order $order): array
    {
        $cached = $this->cachedPlatformLabel($order);
        if ($cached) {
            return $cached;
        }

        $packageId = $this->resolveTiktokPackageIds($order)[0] ?? null;
        if (! $packageId) {
            throw new RuntimeException('Package ID TikTok Shop belum tersedia. Sinkronkan pesanan terlebih dahulu.');
        }

        $response = $this->tiktok->getPackageShippingDocument(
            $order->store,
            $packageId,
            $this->tiktokDocumentSize($order->store),
            $this->tiktokDocumentType($order->store),
        );
        $this->assertPlatformSuccess($response, 'TikTok Shop');
        $url = data_get($response, 'data.doc_url')
            ?? data_get($response, 'data.document_url')
            ?? data_get($response, 'data.shipping_document_url');
        if (! $url) {
            throw new RuntimeException('TikTok Shop belum menyediakan dokumen resi untuk paket ini.');
        }

        $document = $this->documentFromResponse(
            $this->tiktok->downloadShippingDocument($url),
            'TikTok Shop',
        );

        return $this->cachePlatformLabel($order, [
            'type' => 'binary',
            'body' => $document['body'],
            'content_type' => $document['content_type'],
            'filename' => "resi-tiktok-{$order->order_sn}.pdf",
        ]);
    }

    private function cachedPlatformLabel(Order $order): ?array
    {
        $disk = Storage::disk('local');
        $path = $this->platformLabelCachePath($order);
        if (! $disk->exists($path)) {
            return null;
        }

        $ttlDays = max(0, (int) config('services.shipping_labels.cache_ttl_days', 14));
        if ($ttlDays > 0 && $disk->lastModified($path) < now()->subDays($ttlDays)->timestamp) {
            $disk->delete($path);

            return null;
        }

        $body = $disk->get($path);
        if (! is_string($body) || ! str_starts_with($body, '%PDF-')) {
            $disk->delete($path);

            return null;
        }

        return [
            'type' => 'binary',
            'body' => $body,
            'content_type' => 'application/pdf',
            'filename' => 'resi-'.strtolower($this->platform($order)).'-'.$order->order_sn.'.pdf',
        ];
    }

    private function cachePlatformLabel(Order $order, array $document): array
    {
        $normalized = $this->normalizeSingleLabelDocument($document, 1);
        $normalized['filename'] = $document['filename']
            ?? 'resi-'.strtolower($this->platform($order)).'-'.$order->order_sn.'.pdf';

        if (str_starts_with((string) ($normalized['body'] ?? ''), '%PDF-')) {
            Storage::disk('local')->put($this->platformLabelCachePath($order), $normalized['body']);
        }

        return $normalized;
    }

    private function platformLabelCachePath(Order $order): string
    {
        $packages = $order->packages
            ->map(fn (OrderPackage $package) => [
                'id' => (string) $package->package_id,
                'tracking' => (string) $package->tracking_number,
            ])
            ->values()
            ->all();
        $fingerprint = hash('sha256', json_encode([
            'platform' => $this->platform($order),
            'store_id' => $order->store_id,
            'order_sn' => $order->order_sn,
            'packages' => $packages,
            'printing' => $this->printingSettings($order->store),
        ], JSON_UNESCAPED_SLASHES));

        return 'shipping-labels/'.strtolower($this->platform($order)).'/'.$fingerprint.'.pdf';
    }

    private function localTrackingNumber(Order $order): string
    {
        return trim((string) (
            $order->packages->pluck('tracking_number')->first(fn ($number) => filled($number))
            ?? data_get($order->raw_data, 'tracking_no')
            ?? data_get($order->raw_data, 'tracking_number')
            ?? ''
        ));
    }

    private function documentFromResponse(Response $response, string $platform): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("{$platform} gagal mengunduh dokumen resi (HTTP {$response->status()}).");
        }

        $body = $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));
        $trimmed = ltrim($body);
        if (str_contains($contentType, 'json') || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $payload = json_decode($body, true);
            if (is_array($payload)) {
                $this->assertPlatformSuccess($payload, $platform === 'Shopee' ? 'Shopee' : 'TikTok Shop');
            }

            throw new RuntimeException("{$platform} tidak mengembalikan dokumen resi yang valid.");
        }

        $isPdf = str_starts_with($body, '%PDF-') || str_contains($contentType, 'pdf');
        $isZip = str_starts_with($body, "PK\x03\x04") || str_contains($contentType, 'zip');
        if (! $isPdf && ! $isZip) {
            throw new RuntimeException("{$platform} tidak mengembalikan file PDF atau ZIP yang valid.");
        }

        return [
            'body' => $body,
            'content_type' => $isZip ? 'application/zip' : 'application/pdf',
        ];
    }

    private function normalizeSingleLabelDocument(array $document, int $orderCount): array
    {
        $pdfBodies = $this->pdfBodiesFromDocument($document);
        if (count($pdfBodies) === 1) {
            return [
                'type' => 'binary',
                'body' => $pdfBodies[0],
                'content_type' => 'application/pdf',
                'filename' => $document['filename'] ?? "resi-massal-{$orderCount}-pesanan.pdf",
            ];
        }

        $merged = $this->mergeShippingLabelPdfs(1, [$document], $orderCount);
        $merged['filename'] = $document['filename'] ?? $merged['filename'];

        return $merged;
    }

    /** @return array<int, string> */
    private function pdfBodiesFromDocument(array $document): array
    {
        $body = (string) ($document['body'] ?? '');
        $contentType = strtolower((string) ($document['content_type'] ?? ''));
        if (str_starts_with($body, '%PDF-') || str_contains($contentType, 'pdf')) {
            return [$body];
        }
        if (! str_starts_with($body, "PK\x03\x04") && ! str_contains($contentType, 'zip')) {
            throw new RuntimeException('Salah satu sumber tidak mengembalikan dokumen PDF yang dapat digabungkan.');
        }

        $path = tempnam(sys_get_temp_dir(), 'finesheet-label-');
        if ($path === false || file_put_contents($path, $body) === false) {
            throw new RuntimeException('Dokumen ZIP resi tidak dapat diproses.');
        }

        $zip = new ZipArchive;
        $opened = false;
        $pdfs = [];
        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Dokumen ZIP resi tidak valid.');
            }
            $opened = true;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $contents = $zip->getFromIndex($index);
                if (str_ends_with(strtolower($name), '.pdf') && is_string($contents) && str_starts_with($contents, '%PDF-')) {
                    $pdfs[] = $contents;
                }
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }

        if ($pdfs === []) {
            throw new RuntimeException('Dokumen ZIP resi tidak berisi file PDF.');
        }

        return $pdfs;
    }

    private function buildShopeeShipment(array $options, string $method, array $selection): array
    {
        if ($method === 'pickup') {
            $required = Arr::wrap(data_get($options, 'info_needed.pickup', []));
            $addresses = Arr::wrap(data_get($options, 'pickup.address_list', []));
            $address = collect($addresses)->firstWhere('address_id', $selection['address_id'] ?? null)
                ?? $addresses[0] ?? null;
            $slots = Arr::wrap(data_get($address, 'time_slot_list', []));
            $slot = collect($slots)->firstWhere('pickup_time_id', $selection['pickup_time_id'] ?? null)
                ?? $slots[0] ?? null;
            if ((in_array('address_id', $required, true) && ! isset($address['address_id']))
                || (in_array('pickup_time_id', $required, true) && ! isset($slot['pickup_time_id']))) {
                throw new RuntimeException('Alamat atau jadwal pickup Shopee tidak tersedia.');
            }

            $pickup = [];
            if (isset($address['address_id'])) {
                $pickup['address_id'] = (int) $address['address_id'];
            }
            if (isset($slot['pickup_time_id'])) {
                $pickup['pickup_time_id'] = (string) $slot['pickup_time_id'];
            }
            $this->assertShopeeRequiredFields($required, $pickup);

            return ['pickup' => $pickup === [] ? (object) [] : $pickup];
        }

        if ($method === 'dropoff') {
            $required = Arr::wrap(data_get($options, 'info_needed.dropoff', []));
            $dropoff = [];
            if (in_array('sender_real_name', $required, true)) {
                $dropoff['sender_real_name'] = (string) ($selection['sender_real_name'] ?? 'Seller');
            }
            $branches = Arr::wrap(data_get($options, 'dropoff.branch_list', []));
            $branchId = $selection['branch_id'] ?? data_get($branches, '0.branch_id');
            if ($branchId && in_array('branch_id', $required, true)) {
                $dropoff['branch_id'] = (int) $branchId;
            }
            $this->assertShopeeRequiredFields($required, $dropoff);

            return ['dropoff' => $dropoff === [] ? (object) [] : $dropoff];
        }

        throw new RuntimeException('Metode pengiriman tidak tersedia untuk pesanan ini.');
    }

    private function assertShopeeRequiredFields(array $required, array $payload): void
    {
        foreach ($required as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '') {
                throw new RuntimeException("Shopee memerlukan {$field} untuk pengiriman pesanan ini.");
            }
        }
    }

    private function resolveTiktokPackageIds(Order $order): array
    {
        $stored = $order->packages
            ->pluck('package_id')
            ->filter(fn ($id) => $id && (string) $id !== (string) $order->order_sn)
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
        if ($stored !== []) {
            return $stored;
        }

        $response = $this->tiktok->getOrderDetail($order->store, $order->order_sn);
        $this->assertPlatformSuccess($response, 'TikTok Shop');
        $payload = data_get($response, 'data.orders.0', []);
        $packages = data_get($payload, 'packages', data_get($payload, 'package_list', []));
        $ids = collect(Arr::wrap($packages))
            ->map(fn ($package) => data_get($package, 'id') ?? data_get($package, 'package_id'))
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        foreach ($ids as $packageId) {
            OrderPackage::updateOrCreate(
                ['order_id' => $order->id, 'package_id' => $packageId],
                ['platform' => 'Tiktokshop']
            );
        }

        if ($ids->isEmpty()) {
            throw new RuntimeException('Package ID TikTok Shop belum tersedia. Sinkronkan pesanan terlebih dahulu.');
        }

        return $ids->all();
    }

    private function shopeePackageNumber(Order $order): string
    {
        $raw = $order->raw_data ?? [];
        if (! array_key_exists('split_up', $raw)) {
            // Resolve incomplete/legacy orders directly instead of waiting for
            // a potentially large background import to reach this order.
            $response = $this->shopee->getOrderDetails($order->store, [$order->order_sn]);
            $this->assertPlatformSuccess($response, 'Shopee');
            $detail = collect(data_get($response, 'response.order_list', []))
                ->firstWhere('order_sn', $order->order_sn);
            if (! is_array($detail) || ! array_key_exists('split_up', $detail)) {
                throw new RuntimeException('Status paket Shopee belum tersedia. Coba proses pengiriman kembali.');
            }
            $raw = array_replace($raw, $detail);
            $order->raw_data = $raw;
        }

        // Shopee also returns package_list for unsplit orders. Its presence
        // alone does not allow package_number on logistics requests.
        if (! filter_var($raw['split_up'], FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        $packageNumber = collect($raw['package_list'] ?? [])->pluck('package_number')
            ->first(fn ($id) => $id && (string) $id !== (string) $order->order_sn);

        $packageNumber = (string) ($packageNumber ?? $order->packages
            ->pluck('package_id')
            ->first(fn ($id) => $id && (string) $id !== (string) $order->order_sn) ?? '');

        if ($packageNumber === '') {
            throw new RuntimeException('Nomor paket split Shopee belum tersedia. Sinkronkan pesanan terlebih dahulu.');
        }

        return $packageNumber;
    }

    private function shopeeTrackingNumber(array $response): string
    {
        return trim((string) (
            data_get($response, 'response.tracking_number')
            ?? data_get($response, 'response.tracking_no')
            ?? data_get($response, 'response.tracking_info.tracking_number')
            ?? data_get($response, 'response.tracking_info.tracking_no')
            ?? ''
        ));
    }

    private function shopeeShippingDocumentParameter(array $response, array $order, int $index): array
    {
        $items = Arr::wrap(data_get($response, 'response.result_list', []));
        $matched = collect($items)->first(function ($item) use ($order) {
            if ((string) data_get($item, 'order_sn') !== (string) $order['order_sn']) {
                return false;
            }

            return ! isset($order['package_number'])
                || (string) data_get($item, 'package_number') === (string) $order['package_number'];
        });

        return is_array($matched) ? $matched : (is_array($items[$index] ?? null) ? $items[$index] : []);
    }

    private function shopeeSuggestedShippingDocumentType(array $response): string
    {
        return trim((string) (
            data_get($response, 'response.result_list.0.suggest_shipping_document_type')
            ?? data_get($response, 'response.result_list.0.suggested_shipping_document_type')
            ?? data_get($response, 'response.result_list.0.shipping_document_type')
            ?? 'NORMAL_AIR_WAYBILL'
        ));
    }

    private function printingSettings(?Store $store): array
    {
        return [
            'print_shipping_label' => (bool) ($store?->print_shipping_label ?? true),
            'print_packing_list' => (bool) ($store?->print_packing_list ?? false),
            'shipping_label_size' => $store?->shipping_label_size ?? 'a6',
            'packing_list_size' => $store?->packing_list_size ?? 'a6',
            'printing_layout' => $store?->printing_layout ?? 'separate',
            'printing_file_format' => 'pdf',
        ];
    }

    private function tiktokDocumentType(?Store $store): string
    {
        $label = (bool) ($store?->print_shipping_label ?? true);
        $packingSlip = (bool) ($store?->print_packing_list ?? false);

        if ($label && $packingSlip) {
            return 'SHIPPING_LABEL_AND_PACKING_SLIP';
        }

        return $packingSlip ? 'PACKING_SLIP' : 'SHIPPING_LABEL';
    }

    private function tiktokDocumentSize(?Store $store): string
    {
        $size = (string) ($store?->shipping_label_size ?? 'a6');
        if (! ($store?->print_shipping_label ?? true)) {
            $size = (string) ($store?->packing_list_size ?? 'a6');
        }

        return strtolower($size) === 'a5' ? 'A5' : 'A6';
    }

    private function platform(Order $order): string
    {
        return strcasecmp((string) $order->platform, 'Shopee') === 0 ? 'Shopee' : 'Tiktokshop';
    }

    private function configuredMethod(Order $order): string
    {
        $method = strtolower((string) ($order->store?->fulfillment_handover_method ?? 'pickup'));

        return $method === 'dropoff' ? 'dropoff' : 'pickup';
    }

    private function shippingLabelData(Order $order): array
    {
        $raw = $order->raw_data ?? [];
        $recipient = data_get($raw, 'recipient_address', []);
        $recipient = is_array($recipient) ? $recipient : [];
        $package = $order->packages->first();

        return [
            'recipient_name' => data_get($recipient, 'name') ?: data_get($raw, 'buyer_username') ?: 'Penerima',
            'recipient_phone' => data_get($recipient, 'phone') ?: data_get($recipient, 'phone_number') ?: '-',
            'recipient_address' => data_get($recipient, 'full_address') ?: data_get($recipient, 'address_detail') ?: '-',
            'shipping_provider' => data_get($raw, 'shipping_carrier')
                ?: data_get($raw, 'shipping_provider')
                ?: 'Kurir platform',
            'tracking_number' => $package?->tracking_number
                ?: data_get($raw, 'tracking_no')
                ?: data_get($raw, 'tracking_number')
                ?: '-',
            'package_id' => $package?->package_id ?: $order->order_sn,
        ];
    }

    private function assertCapable(Order $order, string $capability): void
    {
        if (! ($this->capabilities($order)[$capability] ?? false)) {
            throw new RuntimeException($this->capabilities($order)[str_replace('can_', '', $capability).'_reason'] ?? 'Aksi tidak tersedia.');
        }
    }

    private function assertArrangeStatus(Order $order): void
    {
        $status = strtoupper((string) $order->order_status);
        $allowed = ($this->platform($order) === 'Shopee' && $status === 'READY_TO_SHIP')
            || ($this->platform($order) === 'Tiktokshop' && $status === 'AWAITING_SHIPMENT');

        if (! $allowed) {
            throw new RuntimeException('Pesanan sudah diatur atau belum siap diproses.');
        }
    }

    private function assertPlatformSuccess(array $response, string $platform): void
    {
        $successful = $platform === 'Shopee'
            ? empty($response['error'])
            : (int) ($response['code'] ?? -1) === 0;
        $itemFailure = $platform === 'Shopee'
            ? collect(Arr::wrap(data_get($response, 'response.result_list', [])))
                ->first(fn ($item) => filled(data_get($item, 'fail_error')) || filled(data_get($item, 'fail_message')))
            : null;

        if ($successful && ! $itemFailure) {
            return;
        }

        $itemError = trim((string) data_get($itemFailure, 'fail_error'));
        $itemMessage = trim((string) data_get($itemFailure, 'fail_message'));
        $message = $itemMessage !== '' ? $itemMessage : $itemError;
        if ($itemMessage !== '' && $itemError !== '' && ! str_contains($itemMessage, $itemError)) {
            $message .= " [{$itemError}]";
        }
        if ($message === '') {
            $message = (string) ($response['message'] ?? $response['error'] ?? "{$platform} menolak permintaan.");
        }
        $requestId = $response['request_id'] ?? null;
        if ($requestId) {
            $message .= " (request {$requestId})";
        }

        throw new RuntimeException($message);
    }
}
