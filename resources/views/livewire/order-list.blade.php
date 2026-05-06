<div class="space-y-6 pb-20">

    <div class="flex flex-col gap-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Order Management</h1>
                <p class="text-sm text-gray-500">Kelola dan pantau status pesanan dari semua marketplace.</p>
            </div>
            
            <div class="relative w-full md:w-64">
                <select wire:change="toggleStoreFilter($event.target.value)" class="w-full bg-white border border-gray-200 text-gray-700 py-2.5 px-4 rounded-xl focus:ring-[#304674] focus:border-[#304674] shadow-sm text-sm appearance-none">
                    <option value="">Semua Toko</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" {{ in_array($store->id, $selectedStores) ? 'selected' : '' }}>
                            {{ $store->store_name }} ({{ $store->platform }})
                        </option>
                    @endforeach
                </select>
                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-500">
                    {{-- <span class="material-symbols-rounded">keyboard_arrow_down</span> --}}
                </div>
            </div>
        </div>

        <div class="border-b border-gray-200 dark:border-gray-700">
            <div class="flex overflow-x-auto gap-4 pb-2 no-scrollbar mask-gradient">
                @php
                    $statuses = [
                        'ALL' => 'Semua Order',
                        'READY_TO_SHIP' => 'Perlu Dikirim',
                        'PROCESSED' => 'Diproses',
                        'SHIPPED' => 'Sedang Dikirim',
                        'COMPLETED' => 'Selesai',
                        'CANCELLED' => 'Batal',
                    ];
                @endphp
                
                {{-- Tombol Reset/All --}}
                <button wire:click="$set('selectedStatuses', [])" 
                        class="whitespace-nowrap pb-2 text-sm font-medium border-b-2 transition-colors {{ empty($selectedStatuses) ? 'border-[#304674] text-[#304674]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Semua
                </button>

                @foreach ($statuses as $status => $label)
                    @if($status !== 'ALL')
                    <button wire:click="toggleStatusFilter('{{ $status }}')"
                            class="whitespace-nowrap pb-2 text-sm font-medium border-b-2 transition-colors {{ in_array($status, $selectedStatuses) ? 'border-[#304674] text-[#304674]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

    <div wire:poll.10s class="space-y-8">
        @forelse ($stores as $store)
            @php
                $storeOrders = $orders->where('store_id', $store->id);
                // Config Warna & Icon Platform
                $platformConfig = [
                    'Shopee' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600', 'icon' => 'S'],
                    'Tokopedia' => ['bg' => 'bg-green-50', 'text' => 'text-green-600', 'icon' => 'T'],
                    'Tiktokshop' => ['bg' => 'bg-gray-100', 'text' => 'text-black', 'icon' => '♪'],
                ];
                $theme = $platformConfig[$store->platform] ?? ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'icon' => 'Store'];
            @endphp

            @if ($storeOrders->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg {{ $theme['bg'] }} {{ $theme['text'] }} flex items-center justify-center font-bold text-lg border border-white shadow-sm">
                            {{ $theme['icon'] }}
                        </div>
                        <h2 class="font-bold text-gray-800 dark:text-white">{{ $store->store_name }}</h2>
                        <span class="text-xs text-gray-400 font-mono hidden sm:inline">ID: {{ $store->id }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium bg-gray-200 text-gray-600 px-2 py-1 rounded-md">{{ $storeOrders->count() }} Orders</span>
                        <a href="{{ route('shopee.orders', ['store_id' => $store->id]) }}" class="p-1.5 text-gray-400 hover:text-[#304674] hover:bg-white rounded-lg transition" title="Sync Orders">
                            <span class="material-symbols-rounded text-lg">sync</span>
                        </a>
                    </div>
                </div>

                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-white text-gray-400 font-medium text-xs uppercase border-b border-gray-100">
                            <tr>
                                <th class="px-6 py-4 w-10"></th> <th class="px-6 py-4">Order Details</th>
                                <th class="px-6 py-4">Items Summary</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Total Price</th>
                                <th class="px-6 py-4 text-right">Escrow (Cuan)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($storeOrders as $order)
                                @php
                                    $statusColors = [
                                        'READY_TO_SHIP' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                                        'PROCESSED' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'SHIPPED' => 'bg-purple-50 text-purple-700 border-purple-200',
                                        'COMPLETED' => 'bg-green-50 text-green-700 border-green-200',
                                        'CANCELLED' => 'bg-red-50 text-red-700 border-red-200',
                                    ];
                                    $statusClass = $statusColors[$order->order_status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
                                    $firstItem = $order->orderItems->first();
                                    $itemCount = $order->orderItems->count();
                                @endphp
                                
                                <tr class="hover:bg-gray-50/50 transition-colors group" x-data="{ expanded: false }">
                                    <td class="px-6 py-4 align-top">
                                        <button @click="expanded = !expanded" class="text-gray-400 hover:text-[#304674] transition-transform duration-200" :class="expanded ? 'rotate-180' : ''">
                                            <span class="material-symbols-rounded">expand_more</span>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 align-top">
                                        <div class="font-bold text-[#304674] font-mono">{{ $order->order_sn }}</div>
                                        <div class="text-xs text-gray-400 mt-1">{{ $order->created_at->format('d M Y, H:i') }}</div>
                                    </td>
                                    <td class="px-6 py-4 align-top">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ $firstItem->item->image ?? 'https://placehold.co/50' }}" class="w-10 h-10 rounded-md border border-gray-200 object-cover">
                                            <div>
                                                <p class="font-medium text-gray-700 truncate w-48">{{ $firstItem->item_name }}</p>
                                                @if($itemCount > 1)
                                                    <span class="text-xs text-blue-600 font-medium">+{{ $itemCount - 1 }} produk lainnya</span>
                                                @else
                                                    <span class="text-xs text-gray-400">Variant: {{ $firstItem->model_name }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 align-top">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold border {{ $statusClass }}">
                                            {{ str_replace('_', ' ', $order->order_status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 align-top text-right font-medium text-gray-600">
                                        Rp {{ number_format($order->order_selling_price, 0, ',', '.') }}
                                    </td>
                                    <td class="px-6 py-4 align-top text-right font-bold text-[#304674]">
                                        Rp {{ number_format($order->escrow_amount, 0, ',', '.') }}
                                    </td>
                                    
                                    <template x-if="expanded">
                                        <tr class="bg-gray-50/50">
                                            <td colspan="6" class="px-6 py-4">
                                                <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                                                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Detail Pesanan</h4>
                                                    <div class="space-y-3">
                                                        @foreach($order->orderItems as $item)
                                                            <div class="flex items-start justify-between">
                                                                <div class="flex items-start gap-3">
                                                                    <img src="{{ $item->item->image ?? 'https://placehold.co/50' }}" class="w-12 h-12 rounded-lg border border-gray-100">
                                                                    <div>
                                                                        <p class="text-sm font-semibold text-gray-700">{{ $item->item_name }}</p>
                                                                        <p class="text-xs text-gray-500">Var: {{ $item->model_name }} • Qty: {{ $item->quantity_purchased }}</p>
                                                                    </div>
                                                                </div>
                                                                <div class="text-right">
                                                                     <p class="text-sm font-bold text-gray-700">Rp {{ number_format($item->item->variantItems->first()->price ?? 0, 0, ',', '.') }}</p>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 border-t border-gray-100">
                            <tr>
                                <td colspan="4" class="px-6 py-3 font-bold text-gray-500 text-right">Total Summary:</td>
                                <td class="px-6 py-3 font-bold text-gray-800 text-right">Rp {{ number_format($storeOrders->sum('order_selling_price'), 0, ',', '.') }}</td>
                                <td class="px-6 py-3 font-bold text-green-600 text-right">Rp {{ number_format($storeOrders->sum('escrow_amount'), 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="md:hidden p-4 space-y-4 bg-gray-50/50">
                    @foreach ($storeOrders as $order)
                         @php
                            $statusColors = [
                                'READY_TO_SHIP' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                                'PROCESSED' => 'bg-blue-50 text-blue-700 border-blue-200',
                                'SHIPPED' => 'bg-purple-50 text-purple-700 border-purple-200',
                                'COMPLETED' => 'bg-green-50 text-green-700 border-green-200',
                                'CANCELLED' => 'bg-red-50 text-red-700 border-red-200',
                            ];
                            $statusClass = $statusColors[$order->order_status] ?? 'bg-gray-50 text-gray-600 border-gray-200';

                                    $statusMap = [
                                            'READY_TO_SHIP' => 'Perlu Diproses',
                                            'PROCESSED' => 'Diproses',
                                            'SHIPPED' => 'Dikirim',
                                            'COMPLETED' => 'Selesai',
                                            'CANCELLED' => 'Dibatalkan',
                                        ];
                                    $status = $statusMap[$order->order_status] ?? $order->order_status;
                        @endphp
                        
                        <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100" x-data="{ expanded: false }">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-mono text-xs text-gray-500">#{{ $order->order_sn }}</span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $statusClass }}">
                                            {{ str_replace('_', ' ', $status) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-400">{{ $order->created_at->format('d M Y H:i') }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-400">Escrow</p>
                                    <p class="text-sm font-bold text-green-600">Rp {{ number_format($order->escrow_amount, 0, ',', '.') }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 bg-gray-50 p-2 rounded-lg" @click="expanded = !expanded">
                                <img src="{{ $order->orderItems->first()->item->image ?? 'https://placehold.co/50' }}" class="w-10 h-10 rounded border border-gray-200">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-700 truncate">{{ $order->orderItems->first()->item_name }}</p>
                                    @if($order->orderItems->count() > 1)
                                        <p class="text-xs text-blue-600">+{{ $order->orderItems->count() - 1 }} produk lainnya</p>
                                    @else
                                         <p class="text-xs text-gray-400">Qty: {{ $order->orderItems->first()->quantity_purchased }}</p>
                                    @endif
                                </div>
                                <span class="material-symbols-rounded text-gray-400 transition-transform" :class="expanded ? 'rotate-180' : ''">expand_more</span>
                            </div>

                            <div x-show="expanded" x-collapse class="mt-3 space-y-3 border-t border-gray-100 pt-3">
                                @foreach($order->orderItems as $item)
                                    <div class="flex items-start justify-between text-xs">
                                        <span class="text-gray-600 w-2/3">{{ $item->item_name }} <span class="text-gray-400">(x{{ $item->quantity_purchased }})</span></span>
                                    </div>
                                @endforeach
                                <div class="border-t border-dashed border-gray-200 pt-2 flex justify-between items-center">
                                    <span class="text-xs font-bold text-gray-600">Total Penjualan</span>
                                    <span class="text-sm font-bold text-gray-800">Rp {{ number_format($order->order_selling_price, 0, ',', '.') }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

            </div>
            @endif

        @empty
            <div class="bg-white rounded-2xl p-12 text-center border border-dashed border-gray-300">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 mb-4">
                    <span class="material-symbols-rounded text-3xl text-gray-300">shopping_cart_off</span>
                </div>
                <h3 class="text-lg font-bold text-gray-800">Belum ada pesanan</h3>
                <p class="text-gray-500">Coba ubah filter atau sinkronisasi data toko Anda.</p>
            </div>
        @endforelse
    </div>
</div>