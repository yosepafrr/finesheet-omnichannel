<?php

namespace App\Services;

use App\Models\Order;
use TCPDF;

class PickingListPdfService
{
    public function generate(iterable $orders): array
    {
        $orders = collect($orders)->values();
        $items = $orders
            ->flatMap(fn (Order $order) => $order->orderProducts->map(fn ($product) => [
                'sku' => trim((string) $product->sku),
                'name' => trim((string) $product->product_name),
                'variant' => trim((string) $product->model_name),
                'quantity' => max(1, (int) $product->quantity_purchased),
                'order_sn' => (string) $order->order_sn,
                'store' => (string) ($order->store?->store_name ?: 'Toko'),
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

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);
        $pdf->writeHTML($this->html($orders, $items), true, false, true, false, '');

        return [
            'body' => $pdf->Output('picking-list.pdf', 'S'),
            'filename' => 'picking-list-'.now()->format('Ymd-His').'-'.$orders->count().'-pesanan.pdf',
        ];
    }

    private function html($orders, $items): string
    {
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $stores = $orders->pluck('store.store_name')->filter()->unique()->join(', ');
        $totalUnits = $items->sum('quantity');
        $rows = $items->map(function (array $item, int $index) use ($escape) {
            $identity = $item['sku'] !== '' ? strtoupper($item['sku']) : 'SKU belum tersedia';
            $variant = $item['variant'] !== '' ? '<br><span style="color:#64748b">'.$escape($item['variant']).'</span>' : '';
            $orderNumbers = collect($item['orders'])->map($escape)->join(', ');

            return '<tr>'
                .'<td style="width:7%;text-align:center">'.($index + 1).'</td>'
                .'<td style="width:23%;font-weight:bold">'.$escape($identity).'</td>'
                .'<td style="width:38%">'.$escape($item['name'] ?: 'Produk tanpa nama').$variant.'</td>'
                .'<td style="width:10%;text-align:center;font-size:13px;font-weight:bold">'.$item['quantity'].'</td>'
                .'<td style="width:22%;font-size:8px">'.$orderNumbers.'</td>'
                .'</tr>';
        })->join('');

        return '<style>
            h1{font-size:19px;color:#1e293b;margin:0}.meta{color:#64748b;font-size:9px}
            table{border-collapse:collapse;width:100%;margin-top:12px}th{background-color:#304674;color:#fff;font-weight:bold}
            th,td{border:1px solid #dbe2ea;padding:7px;vertical-align:middle}.summary{margin-top:9px;color:#334155}
        </style>
        <table cellpadding="0" cellspacing="0" style="border:0;margin:0">
            <tr><td style="border:0;width:65%;padding:0"><h1>PICKING LIST</h1><div class="meta">'.$escape($stores ?: 'Semua toko terpilih').'</div></td>
            <td style="border:0;width:35%;padding:0;text-align:right"><b>'.$escape(now()->format('d M Y, H.i')).'</b><br><span class="meta">Dibuat oleh Finesheet</span></td></tr>
        </table>
        <div class="summary"><b>'.$orders->count().' pesanan</b> &nbsp; | &nbsp; <b>'.$items->count().' SKU/varian</b> &nbsp; | &nbsp; <b>'.$totalUnits.' unit</b></div>
        <table cellpadding="5">
            <thead><tr><th style="width:7%;text-align:center">NO</th><th style="width:23%">SKU</th><th style="width:38%">PRODUK / VARIAN</th><th style="width:10%;text-align:center">QTY</th><th style="width:22%">PESANAN</th></tr></thead>
            <tbody>'.$rows.'</tbody>
        </table>
        <div style="margin-top:12px;color:#64748b;font-size:8px">Gunakan daftar ini untuk mengambil barang, lalu cocokkan kembali SKU dan jumlah sebelum pengemasan.</div>';
    }
}
