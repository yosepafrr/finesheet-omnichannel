<div class="space-y-8 pb-10">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Product Management</h1>
            <p class="text-sm text-gray-500">Kelola stok, harga, dan HPP produk dari semua toko Anda.</p>
        </div>
        <div class="relative w-full md:w-64">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                <span class="material-symbols-rounded text-lg">search</span>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari produk..." 
                   class="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-sm focus:ring-[#304674] focus:border-[#304674] transition">
        </div>
    </div>

    <div wire:poll.10s class="space-y-8">
        @forelse ($stores as $store)
            @php
                $storeProducts = $products->where('store_id', $store->id);
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                
                <div class="bg-gray-50/80 dark:bg-gray-700/50 p-4 border-b border-gray-100 dark:border-gray-600 flex flex-col sm:flex-row sm:items-center justify-between gap-4 backdrop-blur-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-white shadow-sm border border-gray-100 flex items-center justify-center text-xl">
                            @if($store->platform == 'Shopee') <span class="text-orange-500 font-bold">S</span>
                            @elseif($store->platform == 'Tokopedia') <span class="text-green-500 font-bold">T</span>
                            @elseif($store->platform == 'Tiktokshop') <span class="text-black font-bold">♪</span>
                            @else <span class="material-symbols-rounded text-gray-400">store</span>
                            @endif
                        </div>
                        <div>
                            <h2 class="font-bold text-gray-800 dark:text-white text-lg">{{ $store->store_name ?? 'Unknown Store' }}</h2>
                            <span class="text-xs font-medium text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full">{{ $store->platform }}</span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-400 hidden sm:block">{{ $storeProducts->count() }} Produk Ditemukan</span>
                        <a href="{{ route('shopee.update-product', $store->id) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 text-gray-700 text-xs font-bold rounded-lg hover:bg-gray-50 hover:text-[#304674] transition shadow-sm">
                            <span class="material-symbols-rounded text-sm">sync</span> Update Produk
                        </a>
                    </div>
                </div>

                <div class="relative">
                    @if ($storeProducts->isEmpty())
                        <div class="p-12 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 mb-4">
                                <span class="material-symbols-rounded text-3xl text-gray-300">inventory_2</span>
                            </div>
                            <p class="text-gray-500 font-medium">Belum ada data produk.</p>
                            <p class="text-xs text-gray-400 mb-6">Sinkronisasi data untuk mengambil produk terbaru.</p>
                        </div>
                    @else
                        
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                                <thead class="bg-white border-b border-gray-100 text-xs uppercase text-gray-400 font-semibold sticky top-0 z-10">
                                    <tr>
                                        <th class="px-6 py-4 w-24">Img</th>
                                        <th class="px-6 py-4 w-1/4">Product Info</th>
                                        <th class="px-6 py-4">Variant / SKU</th>
                                        <th class="px-6 py-4 text-center">Stock</th>
                                        <th class="px-6 py-4">Price</th>
                                        <th class="px-6 py-4 w-60">HPP (Modal)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @foreach ($storeProducts as $product)
                                        @php
                                            $variantCount = $product->variantItems->count();
                                        @endphp

                                        @if ($variantCount > 0)
                                            @foreach ($product->variantItems as $i => $variant)
                                                <tr class="group hover:bg-blue-50/30 transition-colors">
                                                    @if ($i === 0)
                                                        <td class="px-6 py-4 align-top border-r border-dashed border-gray-100" rowspan="{{ $variantCount }}">
                                                            <div class="relative group/img cursor-pointer">
                                                                <img src="{{ $product->image ?? 'https://placehold.co/100' }}" class="w-12 h-12 rounded-lg object-cover border border-gray-100 group-hover/img:scale-110 transition-transform shadow-sm">
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 align-top border-r border-dashed border-gray-100" rowspan="{{ $variantCount }}">
                                                            <div x-data="{ expanded: false }" class="max-w-xs">
                                                                <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm leading-snug cursor-pointer hover:text-[#304674]"
                                                                   @click="expanded = !expanded"
                                                                   :class="expanded ? '' : 'line-clamp-2'">
                                                                    {{ $product->item_name ?? 'Unknown' }}
                                                                </p>
                                                            </div>
                                                            <div class="mt-2 flex gap-2">
                                                                 <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">{{ $variantCount }} Varian</span>
                                                            </div>
                                                        </td>
                                                    @endif

                                                    <td class="px-6 py-3 align-middle">
                                                        <div class="flex flex-col">
                                                            <span class="font-medium text-gray-700 text-xs">{{ $variant->model_name }}</span>
                                                            <span class="text-[10px] text-gray-400 font-mono mt-0.5">{{ $variant->model_sku ?? '-' }}</span>
                                                        </div>
                                                    </td>
                                                    
                                                    <td class="px-6 py-3 text-center">
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium {{ $variant->stock > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                                            {{ $variant->stock }}
                                                        </span>
                                                    </td>

                                                    <td class="px-6 py-3 font-medium text-gray-700">
                                                        Rp {{ number_format($variant->price, 0, ',', '.') }}
                                                    </td>

                                                    <td class="px-6 py-3">
                                                        @include('livewire.partials.hpp-editor', ['type' => 'variant', 'id' => $variant->id, 'hpp' => $variant->hpp])
                                                    </td>
                                                </tr>
                                            @endforeach

                                        @else
                                            <tr class="group hover:bg-blue-50/30 transition-colors">
                                                <td class="px-6 py-4 align-top">
                                                    <img src="{{ $product->image ?? 'https://placehold.co/100' }}" class="w-12 h-12 rounded-lg object-cover border border-gray-100 shadow-sm">
                                                </td>
                                                <td class="px-6 py-4 align-top">
                                                     <div x-data="{ expanded: false }" class="max-w-xs">
                                                        <p class="font-semibold text-gray-800 dark:text-gray-200 text-sm leading-snug cursor-pointer hover:text-[#304674]"
                                                            @click="expanded = !expanded"
                                                            :class="expanded ? '' : 'line-clamp-2'">
                                                            {{ $product->item_name ?? 'Unknown' }}
                                                        </p>
                                                        <p class="text-xs text-gray-400 font-mono mt-1">{{ $product->item_sku ?? '-' }}</p>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 align-middle">
                                                    <span class="text-xs text-gray-400 italic">Single Product</span>
                                                </td>
                                                <td class="px-6 py-4 text-center">
                                                     <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium {{ $product->stock > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                                        {{ $product->stock }}
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 font-medium text-gray-700">
                                                    Rp {{ number_format($product->price, 0, ',', '.') }}
                                                </td>
                                                <td class="px-6 py-4">
                                                    @include('livewire.partials.hpp-editor', ['type' => 'item', 'id' => $product->id, 'hpp' => $product->hpp])
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="md:hidden p-4 space-y-4 bg-gray-50/50">
                             @foreach ($storeProducts as $product)
                                <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 relative overflow-hidden" x-data="{ openVariants: false }">
                                    
                                    <div class="flex gap-4">
                                        <img src="{{ $product->image ?? 'https://placehold.co/100' }}" class="w-16 h-16 rounded-lg object-cover border border-gray-100 shrink-0">
                                        <div class="flex-1 min-w-0">
                                            <h3 class="text-sm font-bold text-gray-800 leading-tight line-clamp-2">{{ $product->item_name }}</h3>
                                            <p class="text-xs text-gray-400 font-mono mt-1">{{ $product->item_sku ?? 'No SKU' }}</p>
                                            
                                            @if($product->variantItems->count() == 0)
                                                <div class="mt-3 flex items-center justify-between border-t border-dashed border-gray-100 pt-2">
                                                    <div>
                                                        <p class="text-[10px] text-gray-400 uppercase tracking-wider">Harga Jual</p>
                                                        <p class="text-sm font-bold text-[#304674]">Rp {{ number_format($product->price, 0, ',', '.') }}</p>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-[10px] text-gray-400 uppercase tracking-wider">HPP</p>
                                                         @include('livewire.partials.hpp-editor', ['type' => 'item', 'id' => $product->id, 'hpp' => $product->hpp, 'isMobile' => true])
                                                    </div>
                                                </div>
                                            @else
                                                <button @click="openVariants = !openVariants" class="mt-2 text-xs font-medium text-[#304674] flex items-center gap-1">
                                                    <span x-text="openVariants ? 'Tutup Varian' : 'Lihat ' + {{ $product->variantItems->count() }} + ' Varian'"></span>
                                                    <span class="material-symbols-rounded text-base" :class="openVariants ? 'rotate-180' : ''">expand_more</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    @if($product->variantItems->count() > 0)
                                        <div x-show="openVariants" x-collapse class="mt-3 pt-3 border-t border-gray-100 space-y-3 bg-gray-50 -mx-4 px-4 pb-2">
                                            @foreach($product->variantItems as $variant)
                                                <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                                                    <div class="flex justify-between items-start mb-2">
                                                        <div>
                                                            <p class="text-xs font-bold text-gray-700">{{ $variant->model_name }}</p>
                                                            <p class="text-[10px] text-gray-400 font-mono">{{ $variant->model_sku }}</p>
                                                        </div>
                                                        <span class="text-[10px] px-2 py-0.5 rounded {{ $variant->stock > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                                            Stok: {{ $variant->stock }}
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-xs font-medium text-gray-600">Rp {{ number_format($variant->price, 0, ',', '.') }}</span>
                                                        <div class="w-1/2 flex justify-end">
                                                             @include('livewire.partials.hpp-editor', ['type' => 'variant', 'id' => $variant->id, 'hpp' => $variant->hpp, 'isMobile' => true])
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                </div>
                             @endforeach
                        </div>
                        
                    @endif
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-10 text-center border border-dashed border-gray-300">
                <p class="text-gray-500">Tidak ada toko yang terhubung.</p>
            </div>
        @endforelse
    </div>
</div>