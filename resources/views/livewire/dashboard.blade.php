
    <div class="space-y-6">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Dashboard Overview</h2>
                <p class="text-sm text-gray-500">Update data terakhir dari Shopee: <span class="font-medium text-[#304674]">Baru saja</span></p>
            </div>
            
            <button class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-[#304674] text-white rounded-lg hover:bg-[#25365a] transition shadow-md shadow-blue-900/10">
                <span class="material-symbols-rounded text-sm">sync</span>
                <span>Sync Shopee</span>
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <div class="p-5 bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group hover:border-[#304674]/30 transition-all">
                <div class="absolute right-0 top-0 p-3 opacity-10 group-hover:opacity-20 transition-opacity">
                    <span class="material-symbols-rounded text-6xl text-[#304674]">payments</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Total Revenue (Bulan Ini)</p>
                    <h3 class="text-2xl font-bold text-gray-800 mt-1">Rp 45.200.000</h3>
                </div>
                <div class="flex items-center gap-1 text-xs font-medium text-green-600 bg-green-50 w-fit px-2 py-1 rounded-full">
                    <span class="material-symbols-rounded text-xs">trending_up</span>
                    +12.5% vs bulan lalu
                </div>
            </div>

            <div class="p-5 bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group hover:border-blue-400/30 transition-all">
                <div class="absolute right-0 top-0 p-3 opacity-10 group-hover:opacity-20 transition-opacity">
                    <span class="material-symbols-rounded text-6xl text-blue-600">shopping_cart</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Total Pesanan</p>
                    <h3 class="text-2xl font-bold text-gray-800 mt-1">1,240</h3>
                </div>
                <div class="flex items-center gap-1 text-xs font-medium text-blue-600 bg-blue-50 w-fit px-2 py-1 rounded-full">
                    35 pesanan baru hari ini
                </div>
            </div>

            <div class="p-5 bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group hover:border-purple-400/30 transition-all">
                <div class="absolute right-0 top-0 p-3 opacity-10 group-hover:opacity-20 transition-opacity">
                    <span class="material-symbols-rounded text-6xl text-purple-600">account_balance_wallet</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Est. Net Profit</p>
                    <h3 class="text-2xl font-bold text-gray-800 mt-1">Rp 12.450.000</h3>
                </div>
                <div class="flex items-center gap-1 text-xs font-medium text-purple-600 bg-purple-50 w-fit px-2 py-1 rounded-full">
                    Margin: 27.5%
                </div>
            </div>

            <div class="p-5 bg-white rounded-xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group hover:border-orange-400/30 transition-all">
                 <div class="absolute right-0 top-0 p-3 opacity-10 group-hover:opacity-20 transition-opacity">
                    <span class="material-symbols-rounded text-6xl text-orange-500">pending_actions</span>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Perlu Dikirim</p>
                    <h3 class="text-2xl font-bold text-gray-800 mt-1">15 <span class="text-sm font-normal text-gray-500">Paket</span></h3>
                </div>
                <div class="flex items-center gap-1 text-xs font-medium text-orange-600 bg-orange-50 w-fit px-2 py-1 rounded-full">
                    Segera proses sebelum 14:00
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800">Grafik Penjualan</h3>
                    <select class="text-xs border-gray-200 rounded-lg focus:ring-[#304674]">
                        <option>7 Hari Terakhir</option>
                        <option>Bulan Ini</option>
                    </select>
                </div>
                
                <div class="h-64 bg-gray-50 rounded-lg flex items-center justify-center border border-dashed border-gray-200 text-gray-400">
                    <span class="flex items-center gap-2">
                        <span class="material-symbols-rounded">bar_chart</span>
                        Area Grafik Statistik
                    </span>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-4">Produk Terlaris</h3>
                <div class="space-y-4">
                    <div class="flex items-center gap-3 pb-3 border-b border-gray-50 last:border-0 last:pb-0">
                        <img src="https://placehold.co/100" class="w-10 h-10 rounded-lg object-cover bg-gray-100" alt="Produk">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">Jaket Gunung Waterproof</p>
                            <p class="text-xs text-gray-500">124 Terjual</p>
                        </div>
                        <span class="text-xs font-bold text-[#304674]">#1</span>
                    </div>
                     <div class="flex items-center gap-3 pb-3 border-b border-gray-50 last:border-0 last:pb-0">
                        <img src="https://placehold.co/100" class="w-10 h-10 rounded-lg object-cover bg-gray-100" alt="Produk">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">Kaos Polos Cotton 30s</p>
                            <p class="text-xs text-gray-500">89 Terjual</p>
                        </div>
                        <span class="text-xs font-bold text-[#304674]">#2</span>
                    </div>
                     <div class="flex items-center gap-3 pb-3 border-b border-gray-50 last:border-0 last:pb-0">
                        <img src="https://placehold.co/100" class="w-10 h-10 rounded-lg object-cover bg-gray-100" alt="Produk">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">Kemeja Flanel Kotak</p>
                            <p class="text-xs text-gray-500">56 Terjual</p>
                        </div>
                        <span class="text-xs font-bold text-[#304674]">#3</span>
                    </div>
                </div>
                 <button class="w-full mt-4 text-xs font-medium text-[#304674] hover:underline">Lihat Semua Produk</button>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-bold text-gray-800">Pesanan Terbaru</h3>
                <a href="#" class="text-sm text-blue-600 hover:text-blue-700 font-medium">Lihat Semua</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50/50">
                        <tr>
                            <th class="px-6 py-3">Order ID</th>
                            <th class="px-6 py-3">Pelanggan</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Total</th>
                            <th class="px-6 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-white border-b hover:bg-gray-50 transition">
                            <td class="px-6 py-4 font-medium text-[#304674]">#ORD-23091</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs font-bold">A</div>
                                    Asep Surasep
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded-full border border-yellow-200">
                                    Perlu Dikirim
                                </span>
                            </td>
                            <td class="px-6 py-4 font-semibold">Rp 150.000</td>
                            <td class="px-6 py-4 text-right">
                                <button class="text-gray-400 hover:text-[#304674]">
                                    <span class="material-symbols-rounded text-lg">visibility</span>
                                </button>
                            </td>
                        </tr>
                         <tr class="bg-white border-b hover:bg-gray-50 transition">
                            <td class="px-6 py-4 font-medium text-[#304674]">#ORD-23092</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-pink-100 text-pink-600 flex items-center justify-center text-xs font-bold">S</div>
                                    Siti Aminah
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full border border-green-200">
                                    Selesai
                                </span>
                            </td>
                            <td class="px-6 py-4 font-semibold">Rp 320.000</td>
                            <td class="px-6 py-4 text-right">
                                <button class="text-gray-400 hover:text-[#304674]">
                                    <span class="material-symbols-rounded text-lg">visibility</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
