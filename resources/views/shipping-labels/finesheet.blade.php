<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumen {{ $order->order_sn }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #172033; }
        .toolbar { display: flex; justify-content: center; padding: 16px; }
        .toolbar button { border: 0; border-radius: 8px; background: #304674; color: #fff; cursor: pointer; font-weight: 700; padding: 10px 18px; }
        .sheet { margin: 0 auto 24px; background: #fff; border: 1px solid #cbd5e1; break-after: page; }
        .size-a6 { width: 105mm; min-height: 148mm; }
        .size-a4 { width: 210mm; min-height: 297mm; }
        .header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 6mm; border-bottom: 2px solid #172033; }
        .brand { font-size: 20px; font-weight: 800; letter-spacing: .2px; }
        .brand span { font-weight: 400; }
        .platform { margin-top: 4px; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .route { text-align: right; }
        .route strong { display: block; max-width: 70mm; font-size: 13px; }
        .route small { color: #64748b; font-size: 9px; }
        .document-title { padding: 4mm 6mm; border-bottom: 1px solid #94a3b8; font-size: 16px; font-weight: 800; }
        .block { padding: 4mm 6mm; border-bottom: 1px solid #94a3b8; }
        .eyebrow { margin-bottom: 2mm; color: #64748b; font-size: 8px; font-weight: 800; text-transform: uppercase; }
        .recipient { font-size: 15px; font-weight: 800; }
        .address { margin-top: 2mm; font-size: 10px; line-height: 1.45; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid #94a3b8; }
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
        .footer { display: flex; justify-content: space-between; gap: 12px; padding: 4mm 6mm 6mm; color: #64748b; font-size: 8px; }
        .combined-divider { margin: 0 6mm; border-top: 2px dashed #64748b; }
        @page a6 { size: A6; margin: 0; }
        @page a4 { size: A4; margin: 0; }
        .page-a6 { page: a6; }
        .page-a4 { page: a4; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; border: 0; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Cetak / simpan PDF</button></div>

    @php
        $combined = ($printing['printing_layout'] ?? 'separate') === 'combined';
        $showLabel = (bool) ($printing['print_shipping_label'] ?? true);
        $showPacking = (bool) ($printing['print_packing_list'] ?? false);
        $labelSize = $printing['shipping_label_size'] ?? 'a6';
        $packingSize = $printing['packing_list_size'] ?? 'a6';
    @endphp

    @if ($combined && ($showLabel || $showPacking))
        <main class="sheet size-{{ $labelSize }} page-{{ $labelSize }}">
            <header class="header">
                <div><div class="brand"><span>fine</span>sheet</div><div class="platform">{{ $order->platform }} &middot; {{ $order->store?->store_name }}</div></div>
                <div class="route"><small>Kurir</small><strong>{{ $shipping['shipping_provider'] }}</strong></div>
            </header>
            @if ($showLabel)
                <section class="block"><div class="eyebrow">Penerima</div><div class="recipient">{{ $shipping['recipient_name'] }}</div><div class="address">{{ $shipping['recipient_phone'] }}<br>{{ $shipping['recipient_address'] }}</div></section>
            @endif
            <section class="meta"><div><span class="eyebrow">Nomor pesanan</span><strong class="order-number">{{ $order->order_sn }}</strong></div><div><span class="eyebrow">Nomor resi</span><strong class="order-number">{{ $shipping['tracking_number'] }}</strong></div></section>
            @if ($showPacking)
                <div class="combined-divider"></div><div class="document-title">Daftar pengemasan</div>
                @include('shipping-labels.partials.product-table')
            @endif
            @if ($showLabel)<aside class="notice"><strong>Catatan untuk penerima</strong>Mohon rekam video unboxing tanpa jeda sejak paket masih tersegel.</aside>@endif
            <footer class="footer"><span>Paket: {{ $shipping['package_id'] }}</span><span>{{ $order->cod ? 'COD' : 'Non-COD' }}</span></footer>
        </main>
    @else
        @if ($showLabel)
            <main class="sheet size-{{ $labelSize }} page-{{ $labelSize }}">
                <header class="header"><div><div class="brand"><span>fine</span>sheet</div><div class="platform">{{ $order->platform }} &middot; {{ $order->store?->store_name }}</div></div><div class="route"><small>Kurir</small><strong>{{ $shipping['shipping_provider'] }}</strong></div></header>
                <section class="block"><div class="eyebrow">Penerima</div><div class="recipient">{{ $shipping['recipient_name'] }}</div><div class="address">{{ $shipping['recipient_phone'] }}<br>{{ $shipping['recipient_address'] }}</div></section>
                <section class="meta"><div><span class="eyebrow">Nomor pesanan</span><strong class="order-number">{{ $order->order_sn }}</strong></div><div><span class="eyebrow">Nomor resi</span><strong class="order-number">{{ $shipping['tracking_number'] }}</strong></div></section>
                <aside class="notice"><strong>Catatan untuk penerima</strong>Mohon rekam video unboxing tanpa jeda sejak paket masih tersegel.</aside>
                <footer class="footer"><span>Paket: {{ $shipping['package_id'] }}</span><span>{{ $order->cod ? 'COD' : 'Non-COD' }}</span></footer>
            </main>
        @endif
        @if ($showPacking)
            <main class="sheet size-{{ $packingSize }} page-{{ $packingSize }}">
                <header class="header"><div><div class="brand"><span>fine</span>sheet</div><div class="platform">{{ $order->store?->store_name }}</div></div><div class="route"><small>Pesanan</small><strong>{{ $order->order_sn }}</strong></div></header>
                <div class="document-title">Daftar pengemasan</div>
                @include('shipping-labels.partials.product-table')
            </main>
        @endif
    @endif
</body>
</html>
