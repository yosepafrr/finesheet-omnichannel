<section class="block" style="padding: 0">
    <table>
        <thead><tr><th>Produk</th><th>SKU</th><th>Qty</th></tr></thead>
        <tbody>
            @forelse ($order->orderProducts as $product)
                <tr>
                    <td><div class="product-name">{{ $product->product_name ?: 'Produk' }}</div>@if ($product->model_name)<div class="variant">Varian: {{ $product->model_name }}</div>@endif</td>
                    <td>{{ $product->sku ?: '-' }}</td><td>{{ $product->quantity_purchased ?: 1 }}</td>
                </tr>
            @empty
                <tr><td colspan="3">Rincian produk belum tersedia.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
