<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPackage;
use App\Models\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        $tracking = $this->shopee->getTrackingNumber($order->store, $order->order_sn, $packageNumber);
        $this->assertPlatformSuccess($tracking, 'Shopee');
        $trackingNumber = $this->shopeeTrackingNumber($tracking);

        $parameters = $this->shopee->getShippingDocumentParameter($order->store, [$documentOrder]);
        $this->assertPlatformSuccess($parameters, 'Shopee');
        $suggestedDocumentType = $this->shopeeSuggestedShippingDocumentType($parameters);
        $documentOrder['shipping_document_type'] = $this->shopeeShippingDocumentType(
            $parameters,
            (bool) ($order->store?->shopee_thermal_printing ?? false) ? 'a6' : 'a4',
        );
        Log::info('Shopee shipping document type selected', [
            'order_sn' => $order->order_sn,
            'thermal_enabled' => (bool) ($order->store?->shopee_thermal_printing ?? false),
            'selected_type' => $documentOrder['shipping_document_type'],
            'suggested_type' => $suggestedDocumentType,
        ]);

        $createOrder = $documentOrder;
        if ($trackingNumber !== '') {
            $createOrder['tracking_number'] = $trackingNumber;
        }

        $created = $this->shopee->createShippingDocument($order->store, [$createOrder]);
        if ($this->shopeeDocumentTypeRejected($created)
            && $suggestedDocumentType !== ''
            && $documentOrder['shipping_document_type'] !== $suggestedDocumentType) {
            $rejectedDocumentType = $documentOrder['shipping_document_type'];
            $documentOrder['shipping_document_type'] = $suggestedDocumentType;
            $createOrder['shipping_document_type'] = $suggestedDocumentType;
            Log::warning('Shopee rejected preferred shipping document type; retrying suggested type', [
                'order_sn' => $order->order_sn,
                'rejected_type' => $rejectedDocumentType,
                'suggested_type' => $suggestedDocumentType,
            ]);
            $created = $this->shopee->createShippingDocument($order->store, [$createOrder]);
        }
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
                if (! $download->successful()) {
                    throw new RuntimeException('Shopee gagal mengunduh dokumen resi.');
                }
                $printMode = str_contains($documentOrder['shipping_document_type'], 'THERMAL')
                    ? 'thermal'
                    : 'normal';

                return [
                    'type' => 'binary',
                    'body' => $download->body(),
                    'content_type' => $download->header('Content-Type') ?: 'application/pdf',
                    'filename' => "resi-shopee-{$printMode}-{$order->order_sn}.pdf",
                    'document_type' => $documentOrder['shipping_document_type'],
                ];
            }
            if (in_array($status, ['FAILED', 'ERROR'], true)) {
                throw new RuntimeException(data_get($result, 'response.result_list.0.fail_error') ?: 'Pembuatan resi Shopee gagal.');
            }
        }

        throw new RuntimeException('Resi Shopee masih diproses. Coba cetak kembali beberapa saat lagi.');
    }

    /**
     * Prepare one marketplace document containing multiple Shopee labels.
     * Shopee's download endpoint accepts an order_list, so the browser only
     * needs to open one response instead of one popup per selected order.
     */
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

        $storeIds = $orders->pluck('store_id')->filter()->unique();
        if ($storeIds->count() !== 1) {
            throw new RuntimeException('Cetak resi massal hanya dapat dilakukan untuk pesanan dari satu toko.');
        }

        $store = $orders->first()->store;
        if ($orders->contains(fn (Order $order) => $this->platform($order) !== 'Shopee')) {
            throw new RuntimeException('Cetak resi massal dalam satu dokumen saat ini tersedia untuk pesanan Shopee.');
        }
        if (($store?->shipping_label_template ?? 'platform') !== 'platform') {
            throw new RuntimeException('Cetak resi massal memerlukan template resi dari marketplace.');
        }

        $baseOrders = [];
        $trackingNumbers = [];
        foreach ($orders as $order) {
            $baseOrder = ['order_sn' => $order->order_sn];
            $packageNumber = $this->shopeePackageNumber($order);
            if ($packageNumber !== '') {
                $baseOrder['package_number'] = $packageNumber;
            }
            $baseOrders[] = $baseOrder;

            $tracking = $this->shopee->getTrackingNumber($store, $order->order_sn, $packageNumber);
            $this->assertPlatformSuccess($tracking, 'Shopee');
            $trackingNumbers[$order->order_sn] = $this->shopeeTrackingNumber($tracking);
        }

        $parameters = $this->shopee->getShippingDocumentParameter($store, $baseOrders);
        $this->assertPlatformSuccess($parameters, 'Shopee');

        $documentOrders = [];
        $createOrders = [];
        foreach ($baseOrders as $index => $baseOrder) {
            $parameter = $this->shopeeShippingDocumentParameter($parameters, $baseOrder, $index);
            $parameterResponse = ['response' => ['result_list' => [$parameter]]];
            $documentOrder = $baseOrder + [
                'shipping_document_type' => $this->shopeeShippingDocumentType(
                    $parameterResponse,
                    (bool) ($store?->shopee_thermal_printing ?? false) ? 'a6' : 'a4',
                ),
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
                $download = $this->shopee->downloadShippingDocument($store, $documentOrders);
                if (! $download->successful()) {
                    throw new RuntimeException('Shopee gagal mengunduh dokumen resi massal.');
                }

                $contentType = $download->header('Content-Type') ?: 'application/pdf';
                $extension = str_contains(strtolower($contentType), 'zip') ? 'zip' : 'pdf';

                return [
                    'type' => 'binary',
                    'body' => $download->body(),
                    'content_type' => $contentType,
                    'filename' => 'resi-shopee-'.$orders->count().'-pesanan.'.$extension,
                ];
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

    private function shopeeShippingDocumentType(array $response, string $preferredSize = 'a6'): string
    {
        $suggested = $this->shopeeSuggestedShippingDocumentType($response);
        $wantsThermal = strtolower($preferredSize) === 'a6';
        $preferredType = $this->shopeePreferredDocumentType($suggested, $wantsThermal);

        $selectable = data_get($response, 'response.result_list.0.selectable_shipping_document_type')
            ?? data_get($response, 'response.result_list.0.selectable_shipping_document_type_list')
            ?? [];
        if (is_string($selectable)) {
            $selectable = [$selectable];
        }
        if (! is_array($selectable) || $selectable === []) {
            return $preferredType;
        }

        $selectable = collect($selectable)
            ->map(fn ($type) => trim((string) (is_array($type)
                ? ($type['shipping_document_type'] ?? $type['document_type'] ?? '')
                : $type)))
            ->filter()
            ->values();
        $preferred = $selectable->first(function (string $type) use ($wantsThermal) {
            $isThermal = str_contains(strtoupper($type), 'THERMAL');

            return $wantsThermal ? $isThermal : ! $isThermal;
        });

        return $preferred ?: ($selectable->contains($suggested) ? $suggested : $selectable->first());
    }

    private function shopeePreferredDocumentType(string $suggested, bool $wantsThermal): string
    {
        $suggested = strtoupper($suggested);
        if (str_contains($suggested, 'UNPACKAGED')) {
            return $suggested;
        }
        if (str_contains($suggested, '_JOB_AIR_WAYBILL')) {
            return $wantsThermal ? 'THERMAL_JOB_AIR_WAYBILL' : 'NORMAL_JOB_AIR_WAYBILL';
        }

        return $wantsThermal ? 'THERMAL_AIR_WAYBILL' : 'NORMAL_AIR_WAYBILL';
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

    private function shopeeResponseSucceeded(array $response): bool
    {
        if (! empty($response['error'])) {
            return false;
        }

        return ! collect(Arr::wrap(data_get($response, 'response.result_list', [])))
            ->contains(fn ($item) => filled(data_get($item, 'fail_error')) || filled(data_get($item, 'fail_message')));
    }

    private function shopeeDocumentTypeRejected(array $response): bool
    {
        if ($this->shopeeResponseSucceeded($response)) {
            return false;
        }

        $details = strtolower((string) json_encode([
            $response['error'] ?? null,
            $response['message'] ?? null,
            data_get($response, 'response.result_list', []),
        ]));

        return str_contains($details, 'shipping_document_type')
            || str_contains($details, 'document type')
            || str_contains($details, 'tipe dokumen');
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
