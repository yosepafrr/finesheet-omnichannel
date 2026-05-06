<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Your Stores</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola koneksi toko marketplace Anda di sini.</p>
        </div>
        
        <a href="{{ route('shopee.connect') }}" wire:navigate 
           class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-[#304674] text-white text-sm font-medium rounded-xl shadow-lg shadow-blue-900/20 hover:bg-[#25365a] hover:-translate-y-0.5 transition-all duration-200">
            <span class="material-symbols-rounded text-lg">add_circle</span>
            Hubungkan Toko Baru
        </a>
    </div>

    <div wire:poll.10s>
        
        @if($stores->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-dashed border-gray-300 dark:border-gray-700">
                <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-rounded text-3xl text-gray-400">storefront</span>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Belum ada toko terhubung</h3>
                <p class="text-gray-500 mb-6 max-w-sm mx-auto">Mulai hubungkan toko Shopee, Tokopedia, atau TikTok Shop Anda untuk memantau penjualan.</p>
                <a href="{{ route('shopee.connect') }}" class="text-[#304674] font-semibold hover:underline">Hubungkan Sekarang &rarr;</a>
            </div>
        @else

            <div class="hidden md:block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="w-full text-left text-sm text-gray-500">
                    <thead class="bg-gray-50/50 border-b border-gray-100 text-xs uppercase text-gray-400 font-semibold tracking-wider">
                        <tr>
                            <th class="px-6 py-4 font-medium">Platform</th>
                            <th class="px-6 py-4 font-medium">Store Name</th>
                            <th class="px-6 py-4 font-medium">Status Koneksi</th>
                            <th class="px-6 py-4 font-medium text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($stores as $store)
                            @php
                                $logos = [
                                    'Shopee' => 'Marketplace-logo/shopee.png',
                                    'Tokopedia' => 'Marketplace-logo/tokopedia.png',
                                    'Tiktokshop' => 'Marketplace-logo/tts.png'
                                ];
                                $logo = $logos[$store->platform] ?? null;
                                $isActive = $store->shop_expired_at && \Carbon\Carbon::parse($store->shop_expired_at)->isFuture();
                            @endphp
                            <tr class="hover:bg-gray-50/50 transition-colors group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-white border border-gray-100 p-1 shadow-sm flex items-center justify-center">
                                            @if($logo)
                                                <img src="{{ asset($logo) }}" alt="{{ $store->platform }}" class="w-full h-full object-contain">
                                            @else
                                                <span class="material-symbols-rounded text-gray-300">image</span>
                                            @endif
                                        </div>
                                        <span class="font-medium text-gray-900">{{ $store->platform }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-semibold text-gray-800 text-base">{{ $store->store_name ?? '-' }}</p>
                                    <p class="text-xs text-gray-400">ID: #{{ $store->shopee_shop_id ?? 'N/A' }}</p>
                                </td>
                                <td class="px-6 py-4">
                                    @if($isActive)
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-100">
                                            <span class="relative flex h-2 w-2">
                                              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                              <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                                            </span>
                                            Terhubung
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-100">
                                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                                            Terputus
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    @if(!$isActive)
                                        <a href="{{ route('shopee.connect', $store->id) }}" 
                                           class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-red-500 rounded-lg hover:bg-red-600 transition shadow-sm shadow-red-200">
                                            Reconnect
                                        </a>
                                    @else
                                        <button class="text-gray-400 hover:text-[#304674] transition p-2 rounded-full hover:bg-gray-100">
                                            <span class="material-symbols-rounded">settings</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 gap-4 md:hidden">
                @foreach ($stores as $store)
                    @php
                        $logos = [ 'Shopee' => 'Marketplace-logo/shopee.png', 'Tokopedia' => 'Marketplace-logo/tokopedia.png', 'Tiktokshop' => 'Marketplace-logo/tts.png' ];
                        $logo = $logos[$store->platform] ?? null;
                        $isActive = $store->shop_expired_at && \Carbon\Carbon::parse($store->shop_expired_at)->isFuture();
                    @endphp
                    
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-lg bg-gray-50 p-1.5 border border-gray-100 flex-shrink-0">
                                 @if($logo)
                                    <img src="{{ asset($logo) }}" alt="{{ $store->platform }}" class="w-full h-full object-contain">
                                @else
                                    <span class="material-symbols-rounded text-gray-300">store</span>
                                @endif
                            </div>
                            
                            <div>
                                <h3 class="font-bold text-gray-800 text-sm">{{ $store->store_name ?? 'Unknown Store' }}</h3>
                                <div class="mt-1 flex items-center gap-2">
                                     @if($isActive)
                                        <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded-full border border-green-100">Active</span>
                                     @else
                                        <span class="text-[10px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded-full border border-red-100">Expired</span>
                                     @endif
                                     <span class="text-[10px] text-gray-400">• {{ $store->platform }}</span>
                                </div>
                            </div>
                        </div>

                        <div>
                             @if(!$isActive)
                                <a href="{{ route('shopee.connect', $store->id) }}" class="p-2 bg-red-50 text-red-600 rounded-lg border border-red-100">
                                    <span class="material-symbols-rounded text-xl">sync_problem</span>
                                </a>
                            @else
                                <button class="p-2 text-gray-400 hover:text-[#304674]">
                                    <span class="material-symbols-rounded text-xl">more_vert</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        @endif
    </div>
</div>