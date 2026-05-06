<div class="space-y-8 pb-10">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Profit & Cashflow Tracker</h1>
            <p class="text-sm text-gray-500">Pemantauan dana escrow dan pengeluaran iklan secara real-time.</p>
        </div>
        
        <div class="flex items-center gap-2 bg-white px-3 py-1.5 rounded-full border border-gray-200 shadow-sm">
            <span class="relative flex h-2.5 w-2.5">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
            </span>
            <span class="text-xs font-medium text-gray-600">Live Update (5s)</span>
        </div>
    </div>

    <div wire:poll.5s>
        
        @php
            $totalAds = 1000500; // Contoh data statis (bisa diambil dari DB nanti)
            $netEstimation = $totalEscrowAmount - $totalAds;
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
            <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity transform group-hover:scale-110 duration-500">
                    <span class="material-symbols-rounded text-8xl text-green-600">savings</span>
                </div>
                <div class="relative z-10">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total Dana Escrow</p>
                    <h3 class="text-3xl font-bold text-gray-800 dark:text-white transition-all duration-300">
                        Rp {{ number_format($totalEscrowAmount, 0, ',', '.') }}
                    </h3>
                    <div class="mt-4 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-green-50 text-green-700 text-xs font-medium">
                        <span class="material-symbols-rounded text-sm">arrow_upward</span>
                        Pemasukan Bruto
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-100 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity transform group-hover:scale-110 duration-500">
                    <span class="material-symbols-rounded text-8xl text-red-500">campaign</span>
                </div>
                <div class="relative z-10">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Total Ads Spent</p>
                    <h3 class="text-3xl font-bold text-gray-800 dark:text-white">
                        Rp {{ number_format($totalAds, 0, ',', '.') }}
                    </h3>
                    <div class="mt-4 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-red-50 text-red-700 text-xs font-medium">
                        <span class="material-symbols-rounded text-sm">arrow_downward</span>
                        Beban Iklan
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-[#304674] to-[#1e2f50] p-6 rounded-2xl shadow-lg shadow-blue-900/20 relative overflow-hidden text-white group">
                <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle, #ffffff 1px, transparent 1px); background-size: 20px 20px;"></div>
                
                <div class="absolute right-0 top-0 p-4 opacity-20 group-hover:opacity-30 transition-opacity transform group-hover:rotate-12 duration-500">
                    <span class="material-symbols-rounded text-8xl text-white">account_balance_wallet</span>
                </div>
                
                <div class="relative z-10">
                    <p class="text-xs font-bold text-blue-200 uppercase tracking-wider mb-1">Estimasi Profit Bersih</p>
                    <h3 class="text-3xl font-bold">
                        Rp {{ number_format($netEstimation, 0, ',', '.') }}
                    </h3>
                    
                    <div class="mt-4 flex items-center gap-3">
                        <div class="bg-white/10 backdrop-blur-sm border border-white/20 px-3 py-1 rounded-lg flex items-center gap-2">
                             @if($totalEscrowAmount > 0)
                                <span class="text-xs text-blue-100">Margin:</span>
                                <span class="text-sm font-bold">{{ round(($netEstimation / $totalEscrowAmount) * 100, 1) }}%</span>
                             @else
                                <span class="text-xs text-blue-100">Menunggu Data</span>
                             @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-gray-800 dark:text-white flex items-center gap-2">
                    <span class="material-symbols-rounded text-[#304674]">storefront</span>
                    Rincian Per Toko
                </h3>
                <span class="text-xs text-gray-500">Diurutkan berdasarkan nominal escrow</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach ($stores as $store)
                    @php
                        // Konfigurasi Warna Platform
                        $platformConfig = [
                            'Shopee' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-600', 'border' => 'border-orange-100', 'icon' => 'S'],
                            'Tokopedia' => ['bg' => 'bg-green-50', 'text' => 'text-green-600', 'border' => 'border-green-100', 'icon' => 'T'],
                            'Tiktokshop' => ['bg' => 'bg-gray-100', 'text' => 'text-black', 'border' => 'border-gray-200', 'icon' => '♪'],
                        ];
                        $theme = $platformConfig[$store->platform] ?? ['bg' => 'bg-gray-50', 'text' => 'text-gray-600', 'border' => 'border-gray-100', 'icon' => 'Store'];
                        
                        $escrowValue = $storeEscrowTotal[$store->id] ?? 0;
                    @endphp

                    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-200 dark:border-gray-700 hover:shadow-md transition-shadow duration-200 flex flex-col justify-between h-full group">
                        
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg {{ $theme['bg'] }} {{ $theme['text'] }} border {{ $theme['border'] }} flex items-center justify-center font-bold text-lg shadow-sm">
                                    {{ $theme['icon'] }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-bold text-gray-800 text-sm truncate w-32" title="{{ $store->store_name }}">
                                        {{ $store->store_name }}
                                    </h4>
                                    <p class="text-[10px] text-gray-400 uppercase tracking-wide">{{ $store->platform }}</p>
                                </div>
                            </div>
                            
                            <button class="text-gray-300 hover:text-[#304674]">
                                <span class="material-symbols-rounded text-lg">more_vert</span>
                            </button>
                        </div>

                        <div>
                            <p class="text-[10px] text-gray-400 font-medium mb-1">Escrow Balance</p>
                            <div class="flex items-end justify-between">
                                <span class="text-xl font-bold text-[#304674] dark:text-white">
                                    Rp {{ number_format($escrowValue, 0, ',', '.') }}
                                </span>
                            </div>
                            
                            <div class="w-full bg-gray-100 rounded-full h-1.5 mt-3 overflow-hidden">
                                @php
                                    // Hitung persentase kontribusi toko ini terhadap total
                                    $percent = $totalEscrowAmount > 0 ? ($escrowValue / $totalEscrowAmount) * 100 : 0;
                                @endphp
                                <div class="bg-[#304674] h-1.5 rounded-full transition-all duration-500" style="width: {{ $percent }}%"></div>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1 text-right">{{ round($percent, 1) }}% dari total</p>
                        </div>

                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>