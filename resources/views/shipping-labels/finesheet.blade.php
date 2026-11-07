<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resi {{ $order->order_sn }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #172033; }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 16px; }
        .toolbar button { border: 0; border-radius: 8px; background: #304674; color: #fff; cursor: pointer; font-weight: 700; padding: 10px 18px; }
        .label { width: 100mm; min-height: 150mm; margin: 0 auto 24px; background: #fff; border: 1px solid #cbd5e1; }
        .header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 6mm; border-bottom: 2px solid #172033; }
        .brand { font-size: 20px; font-weight: 800; letter-spacing: .2px; }
        .brand span { font-weight: 400; }
        .platform { margin-top: 4px; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .route { text-align: right; }
        .route strong { display: block; max-width: 48mm; font-size: 13px; }
        .route small { color: #64748b; font-size: 9px; }
        .block { padding: 4mm 6mm; border-bottom: 1px solid #94a3b8; }
        .eyebrow { margin-bottom: 2mm; color: #64748b; font-size: 8px; font-weight: 800; text-transform: uppercase; }
        .recipient { font-size: 15px; font-weight: 800; }
        .address { margin-top: 2mm; font-size: 10px; line-height: 1.45; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-bottom: 1px solid #94a3b8; }
        .meta > div { min-height: 18mm; padding: 4mm 6mm; }
        .meta > div + div { border-left: 1px solid #94a3b8; }
        .meta strong { display: block; margin-top: 1.5mm; font-size: 12px; overflow-wrap: anywhere; }
        .order-number { font-family: "Courier New", monospace; letter-spacing: .7px; }
        table { width: 100%; border-collapse: collapse; font-size: 9px; }
        th { background: #eef2f7; color: #334155; font-size: 8px; text-align: left; text-transform: uppercase; }
        th, td { padding: 2.5mm 3mm; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th:last-child, td:last-child { text-align: center; }
        .product-name { font-weight: 700; line-height: 1.35; }
        .variant { margin-top: 1mm; color: #64748b; }
        .notice { margin: 4mm 6mm 6mm; padding: 3.5mm; border: 1px solid #f3c96a; border-radius: 6px; background: #fff9e8; font-size: 9px; line-height: 1.45; }
        .notice strong { display: block; margin-bottom: 1mm; color: #92400e; }
        .footer { display: flex; justify-content: space-between; gap: 12px; padding: 0 6mm 6mm; color: #64748b; font-size: 8px; }
        @page { size: 100mm 150mm; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .label { width: 100mm; min-height: 150mm; margin: 0; border: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak resi</button>
    </div>

    <main class="label">
        <header class="header">
            <div>
                <div class="brand"><span>fine</span>sheet</div>
                <div class="platform">{{ $order->platform }} · {{ $order->store?->store_name }}</div>
            </div>
            <div class="route">
                <small>Kurir</small>
                <strong>{{ $shipping['shipping_provider'] }}</strong>
            </div>
        </header>

        <section class="block">
            <div class="eyebrow">Penerima</div>
            <div class="recipient">{{ $shipping['recipient_name'] }}</div>
            <div class="address">
                {{ $shipping['recipient_phone'] }}<br>
                {{ $shipping['recipient_address'] }}
            </div>
        </section>

        <section class="meta">
            <div>
                <span class="eyebrow">Nomor pesanan</span>
                <strong class="order-number">{{ $order->order_sn }}</strong>
            </div>
            <div>
                <span class="eyebrow">Nomor resi</span>
                <strong class="order-number">{{ $shipping['tracking_number'] }}</strong>
            </div>
        </section>

        <section class="block" style="padding: 0">
            <table>
                <thead>
                    <tr>
                        <th>Isi paket</th>
                        <th>SKU</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->orderProducts as $product)
                        <tr>
                            <td>
                                <div class="product-name">{{ $product->product_name ?: 'Produk' }}</div>
                                @if ($product->model_name)
                                    <div class="variant">Varian: {{ $product->model_name }}</div>
                                @endif
                            </td>
                            <td>{{ $product->sku ?: '-' }}</td>
                            <td>{{ $product->quantity_purchased ?: 1 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <aside class="notice">
            <strong>Catatan untuk penerima</strong>
            Mohon rekam video unboxing tanpa jeda sejak paket masih tersegel. Video membantu proses verifikasi agar kendala pada pesanan dapat ditangani dengan lebih cepat.
        </aside>

        <footer class="footer">
            <span>Paket: {{ $shipping['package_id'] }}</span>
            <span>{{ $order->cod ? 'COD' : 'Non-COD' }}</span>
        </footer>
    </main>
</body>
</html>
