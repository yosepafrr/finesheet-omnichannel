<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use TCPDF;

class PickingListPdfService
{
    private const PAGE_WIDTH = 210;

    private const MARGIN = 12;

    private const CONTENT_WIDTH = 186;

    private const FOOTER_Y = 285;

    /** @var array<int, int> */
    private const NAVY = [15, 28, 63];

    /** @var array<int, int> */
    private const BRAND = [48, 70, 116];

    /** @var array<int, int> */
    private const SLATE = [100, 116, 139];

    /** @var array<int, int> */
    private const BORDER = [219, 226, 234];

    /** @var array<int, int> */
    private const SOFT_BLUE = [241, 245, 251];

    /** @var array<int, int> */
    private const ROW_ALT = [248, 250, 252];

    public function generate(iterable $orders): array
    {
        $orders = collect($orders)->values();
        $generatedAt = now();
        $items = $this->buildItems($orders);
        $stores = $orders->pluck('store.store_name')->filter()->unique()->join(', ');
        $documentNumber = 'PL-'.$generatedAt->format('Ymd-His');

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Finesheet');
        $pdf->SetAuthor('Finesheet');
        $pdf->SetTitle('Picking List '.$generatedAt->format('Y-m-d H:i'));
        $pdf->SetSubject('Daftar pengambilan barang untuk proses fulfillment');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $y = $this->drawFirstPageHeader(
            $pdf,
            $orders,
            $items,
            $stores ?: 'Semua toko terpilih',
            $documentNumber,
            $generatedAt->format('d M Y, H.i').' WIB'
        );
        $y = $this->drawTableHeader($pdf, $y);

        foreach ($items as $index => $item) {
            $rowHeight = $this->itemRowHeight($pdf, $item);

            if ($y + $rowHeight > self::FOOTER_Y - 5) {
                $pdf->AddPage();
                $y = $this->drawContinuationHeader($pdf, $documentNumber, $stores ?: 'Semua toko terpilih');
                $y = $this->drawTableHeader($pdf, $y);
            }

            $this->drawItemRow($pdf, $item, $index, $y, $rowHeight);
            $y += $rowHeight;
        }

        $this->drawCompletionBlock($pdf, $y);
        $this->drawFooters($pdf, $documentNumber);

        return [
            'body' => $pdf->Output('picking-list.pdf', 'S'),
            'filename' => 'picking-list-'.$generatedAt->format('Ymd-His').'-'.$orders->count().'-pesanan.pdf',
        ];
    }

    private function buildItems(Collection $orders): Collection
    {
        return $orders
            ->flatMap(fn (Order $order) => $order->orderProducts->map(fn ($product) => [
                'sku' => trim((string) $product->sku),
                'name' => trim((string) $product->product_name),
                'variant' => trim((string) $product->model_name),
                'quantity' => max(1, (int) $product->quantity_purchased),
                'order_sn' => (string) $order->order_sn,
            ]))
            ->groupBy(fn (array $item) => mb_strtolower($item['sku'].'|'.$item['name'].'|'.$item['variant']))
            ->map(function ($rows) {
                $first = $rows->first();

                return array_merge($first, [
                    'quantity' => $rows->sum('quantity'),
                    'orders' => $rows->pluck('order_sn')->unique()->values()->all(),
                ]);
            })
            ->sortBy(fn (array $item) => [$item['sku'] === '' ? 1 : 0, $item['sku'], $item['name']])
            ->values();
    }

    private function drawFirstPageHeader(
        TCPDF $pdf,
        Collection $orders,
        Collection $items,
        string $stores,
        string $documentNumber,
        string $generatedAt
    ): float {
        $left = self::MARGIN;

        $this->setTextColor($pdf, self::NAVY);
        $pdf->SetFont('helvetica', 'B', 23);
        $pdf->SetXY($left, 11);
        $pdf->Cell(90, 8, 'finesheet', 0, 0, 'L');

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', 'B', 5.8);
        $pdf->SetXY($left + 0.5, 20.2);
        $pdf->Cell(90, 3, 'OMNICHANNEL COMMERCE', 0, 0, 'L');

        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->SetXY(122, 11.5);
        $pdf->Cell(76, 3, 'FULFILLMENT DOCUMENT', 0, 0, 'R');

        $this->setTextColor($pdf, self::NAVY);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(122, 15.3);
        $pdf->Cell(76, 4, $documentNumber, 0, 0, 'R');

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY(122, 20.1);
        $pdf->Cell(76, 3.5, $generatedAt, 0, 0, 'R');

        $this->setFillColor($pdf, self::BRAND);
        $pdf->Rect($left, 27, self::CONTENT_WIDTH, 1.5, 'F');

        $this->setFillColor($pdf, self::SOFT_BLUE);
        $pdf->Rect($left, 31, self::CONTENT_WIDTH, 23, 'F');

        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->SetXY($left + 4, 34);
        $pdf->Cell(112, 3, 'DAFTAR PENGAMBILAN BARANG', 0, 0, 'L');

        $this->setTextColor($pdf, self::NAVY);
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetXY($left + 4, 38);
        $pdf->Cell(112, 8, 'Picking List', 0, 0, 'L');

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY($left + 4, 47.5);
        $pdf->MultiCell(112, 3.5, 'Toko: '.$stores, 0, 'L', false, 0);

        $this->setDrawColor($pdf, self::BORDER);
        $pdf->SetLineWidth(0.25);
        $pdf->Line(145, 34, 145, 51);

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetXY(148, 35.5);
        $pdf->Cell(46, 3, 'DIBUAT OLEH', 0, 0, 'R');

        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(148, 40);
        $pdf->Cell(46, 4, 'Finesheet Operations', 0, 0, 'R');

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY(148, 45.2);
        $pdf->Cell(46, 3.5, 'Siap untuk proses picking', 0, 0, 'R');

        $metricY = 57;
        $metricWidth = 60;
        $metricGap = 3;
        $metrics = [
            ['TOTAL PESANAN', $orders->count()],
            ['SKU / VARIAN', $items->count()],
            ['TOTAL UNIT', $items->sum('quantity')],
        ];

        foreach ($metrics as $index => [$label, $value]) {
            $x = $left + (($metricWidth + $metricGap) * $index);
            $this->setFillColor($pdf, [255, 255, 255]);
            $this->setDrawColor($pdf, self::BORDER);
            $pdf->Rect($x, $metricY, $metricWidth, 15, 'DF');

            $this->setTextColor($pdf, self::SLATE);
            $pdf->SetFont('helvetica', 'B', 6.3);
            $pdf->SetXY($x + 4, $metricY + 2.5);
            $pdf->Cell($metricWidth - 8, 3, $label, 0, 0, 'L');

            $this->setTextColor($pdf, self::NAVY);
            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->SetXY($x + 4, $metricY + 6.5);
            $pdf->Cell($metricWidth - 8, 5, (string) $value, 0, 0, 'L');
        }

        return 76;
    }

    private function drawContinuationHeader(TCPDF $pdf, string $documentNumber, string $stores): float
    {
        $this->setTextColor($pdf, self::NAVY);
        $pdf->SetFont('helvetica', 'B', 17);
        $pdf->SetXY(self::MARGIN, 11);
        $pdf->Cell(65, 7, 'finesheet', 0, 0, 'L');

        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY(77, 11.5);
        $pdf->Cell(55, 5, 'Picking List', 0, 0, 'L');

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY(77, 17);
        $pdf->Cell(90, 3.5, 'Toko: '.$stores, 0, 0, 'L');
        $pdf->SetXY(155, 12);
        $pdf->Cell(43, 3.5, $documentNumber, 0, 0, 'R');

        $this->setFillColor($pdf, self::BRAND);
        $pdf->Rect(self::MARGIN, 25, self::CONTENT_WIDTH, 1.2, 'F');

        return 29;
    }

    private function drawTableHeader(TCPDF $pdf, float $y): float
    {
        $columns = $this->columns();
        $this->setFillColor($pdf, self::BRAND);
        $this->setTextColor($pdf, [255, 255, 255]);
        $pdf->SetFont('helvetica', 'B', 7.5);

        foreach ($columns as $column) {
            $pdf->SetXY($column['x'], $y);
            $pdf->Cell($column['width'], 8, $column['label'], 0, 0, $column['align'], true, '', 0, false, 'T', 'M');
        }

        return $y + 8;
    }

    private function itemRowHeight(TCPDF $pdf, array $item): float
    {
        $pdf->SetFont('helvetica', '', 8.8);
        $nameHeight = $pdf->getStringHeight(65, $item['name'] ?: 'Produk tanpa nama');
        $pdf->SetFont('helvetica', '', 7.2);
        $ordersHeight = $pdf->getStringHeight(43, implode("\n", $item['orders']));

        return max(18, $nameHeight + 8, $ordersHeight + 6);
    }

    private function drawItemRow(TCPDF $pdf, array $item, int $index, float $y, float $height): void
    {
        $columns = $this->columns();
        $fill = $index % 2 === 0 ? [255, 255, 255] : self::ROW_ALT;

        $this->setFillColor($pdf, $fill);
        $this->setDrawColor($pdf, self::BORDER);
        $pdf->SetLineWidth(0.2);

        foreach ($columns as $column) {
            $pdf->Rect($column['x'], $y, $column['width'], $height, 'DF');
        }

        $this->setTextColor($pdf, self::SLATE);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY($columns[0]['x'], $y);
        $pdf->Cell($columns[0]['width'], $height, (string) ($index + 1), 0, 0, 'C', false, '', 0, false, 'T', 'M');

        $sku = $item['sku'] !== '' ? strtoupper($item['sku']) : 'SKU BELUM TERSEDIA';
        $this->setTextColor($pdf, self::NAVY);
        $pdf->SetFont('helvetica', 'B', 8.8);
        $pdf->MultiCell(
            $columns[1]['width'] - 4,
            $height,
            $sku,
            0,
            'L',
            false,
            0,
            $columns[1]['x'] + 2,
            $y,
            true,
            0,
            false,
            true,
            $height,
            'M'
        );

        $productX = $columns[2]['x'] + 2;
        $productWidth = $columns[2]['width'] - 4;
        $productName = $item['name'] ?: 'Produk tanpa nama';
        $this->setTextColor($pdf, [30, 41, 59]);
        $pdf->SetFont('helvetica', '', 8.8);
        $nameHeight = $pdf->getStringHeight($productWidth, $productName);
        $contentHeight = $nameHeight + ($item['variant'] !== '' ? 4.5 : 0);
        $contentY = $y + max(2, ($height - $contentHeight) / 2);
        $pdf->MultiCell($productWidth, $nameHeight, $productName, 0, 'L', false, 0, $productX, $contentY);

        if ($item['variant'] !== '') {
            $this->setTextColor($pdf, self::SLATE);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetXY($productX, $contentY + $nameHeight + 0.6);
            $pdf->Cell($productWidth, 3.5, 'Varian: '.$item['variant'], 0, 0, 'L');
        }

        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($columns[3]['x'], $y);
        $pdf->Cell($columns[3]['width'], $height, (string) $item['quantity'], 0, 0, 'C', false, '', 0, false, 'T', 'M');

        $this->setTextColor($pdf, [51, 65, 85]);
        $pdf->SetFont('helvetica', '', 7.3);
        $pdf->MultiCell(
            $columns[4]['width'] - 4,
            $height,
            implode("\n", $item['orders']),
            0,
            'L',
            false,
            0,
            $columns[4]['x'] + 2,
            $y,
            true,
            0,
            false,
            true,
            $height,
            'M'
        );
    }

    private function drawCompletionBlock(TCPDF $pdf, float $y): void
    {
        $requiredHeight = 42;

        if ($y + $requiredHeight > self::FOOTER_Y - 4) {
            $pdf->AddPage();
            $this->setTextColor($pdf, self::NAVY);
            $pdf->SetFont('helvetica', 'B', 17);
            $pdf->SetXY(self::MARGIN, 12);
            $pdf->Cell(self::CONTENT_WIDTH, 7, 'Penyelesaian Picking', 0, 0, 'L');
            $this->setFillColor($pdf, self::BRAND);
            $pdf->Rect(self::MARGIN, 23, self::CONTENT_WIDTH, 1.2, 'F');
            $y = 28;
        } else {
            $y += 4;
        }

        $this->setFillColor($pdf, [238, 244, 255]);
        $pdf->Rect(self::MARGIN, $y, self::CONTENT_WIDTH, 11, 'F');
        $this->setTextColor($pdf, self::BRAND);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(self::MARGIN + 4, $y + 3.2);
        $pdf->Cell(8, 4, 'i', 0, 0, 'C');
        $this->setTextColor($pdf, [71, 85, 105]);
        $pdf->SetFont('helvetica', '', 7.6);
        $pdf->SetXY(self::MARGIN + 13, $y + 2.5);
        $pdf->MultiCell(
            self::CONTENT_WIDTH - 17,
            5,
            'Ambil barang sesuai SKU dan jumlah, lalu cocokkan kembali varian sebelum barang masuk ke proses pengemasan.',
            0,
            'L'
        );

        $signatureY = $y + 16;
        $signatureWidth = 90;
        $this->setDrawColor($pdf, self::BORDER);
        $pdf->Rect(self::MARGIN, $signatureY, $signatureWidth, 20, 'D');
        $pdf->Rect(self::MARGIN + 96, $signatureY, $signatureWidth, 20, 'D');

        foreach ([
            [self::MARGIN, 'DIAMBIL OLEH'],
            [self::MARGIN + 96, 'DIPERIKSA OLEH'],
        ] as [$x, $label]) {
            $this->setTextColor($pdf, self::SLATE);
            $pdf->SetFont('helvetica', 'B', 6.5);
            $pdf->SetXY($x + 4, $signatureY + 3);
            $pdf->Cell($signatureWidth - 8, 3, $label, 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetXY($x + 4, $signatureY + 13);
            $pdf->Cell($signatureWidth - 8, 3, 'Nama / waktu: __________________________', 0, 0, 'L');
        }
    }

    private function drawFooters(TCPDF $pdf, string $documentNumber): void
    {
        $pageCount = $pdf->getNumPages();

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $pdf->setPage($pageNumber);
            $this->setDrawColor($pdf, self::BORDER);
            $pdf->Line(self::MARGIN, self::FOOTER_Y, self::PAGE_WIDTH - self::MARGIN, self::FOOTER_Y);

            $this->setTextColor($pdf, self::SLATE);
            $pdf->SetFont('helvetica', '', 6.5);
            $pdf->SetXY(self::MARGIN, self::FOOTER_Y + 2);
            $pdf->Cell(110, 3, 'Finesheet - Omnichannel Commerce Operations', 0, 0, 'L');
            $pdf->SetXY(132, self::FOOTER_Y + 2);
            $pdf->Cell(66, 3, $documentNumber.'  |  '.$pageNumber.' / '.$pageCount, 0, 0, 'R');
        }
    }

    /** @return array<int, array{x: float, width: float, label: string, align: string}> */
    private function columns(): array
    {
        return [
            ['x' => 12, 'width' => 10, 'label' => 'NO', 'align' => 'C'],
            ['x' => 22, 'width' => 42, 'label' => 'SKU', 'align' => 'L'],
            ['x' => 64, 'width' => 69, 'label' => 'PRODUK / VARIAN', 'align' => 'L'],
            ['x' => 133, 'width' => 16, 'label' => 'QTY', 'align' => 'C'],
            ['x' => 149, 'width' => 49, 'label' => 'PESANAN', 'align' => 'L'],
        ];
    }

    /** @param array<int, int> $color */
    private function setTextColor(TCPDF $pdf, array $color): void
    {
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
    }

    /** @param array<int, int> $color */
    private function setFillColor(TCPDF $pdf, array $color): void
    {
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
    }

    /** @param array<int, int> $color */
    private function setDrawColor(TCPDF $pdf, array $color): void
    {
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
    }
}
