<div class="space-y-6 pb-20">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Financial Overview</h1>
            <p class="text-sm text-gray-500">Pantau seluruh arus kas, saldo bank, dan investasi aset.</p>
        </div>
        
        <div class="flex gap-3">
            <select class="bg-white border border-gray-200 text-sm rounded-xl focus:ring-[#304674] focus:border-[#304674] py-2 px-7 shadow-sm">
                <option>Bulan Ini</option>
                <option>Bulan Lalu</option>
                <option>Tahun Ini</option>
            </select>

            <button @click="$dispatch('open-transaction-modal')" 
               class="inline-flex items-center gap-2 px-4 py-2 bg-[#304674] text-white text-sm font-bold rounded-xl hover:bg-[#25365a] transition shadow-lg shadow-blue-900/20">
                <span class="material-symbols-rounded text-lg">add</span>
                Catat Transaksi
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        
        <div class="p-5 rounded-2xl bg-gradient-to-br from-[#304674] to-[#1e2f50] text-white shadow-lg relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 opacity-10 group-hover:opacity-20 transition-transform group-hover:scale-110 duration-500">
                <span class="material-symbols-rounded text-9xl">account_balance</span>
            </div>
            <p class="text-xs font-bold text-blue-200 uppercase tracking-wider mb-1">Total Likuiditas</p>
            <h3 class="text-3xl font-bold">Rp 145.200.000</h3>
            <div class="mt-4 flex gap-2">
                <span class="bg-white/20 px-2 py-0.5 rounded text-xs backdrop-blur-sm">+ Rp 12jt bulan ini</span>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col justify-between group hover:border-blue-300 transition-colors">
            <div class="flex justify-between items-start">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                    BCA
                </div>
                <span class="text-xs text-gray-400">Bank</span>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Main Account</p>
                <p class="text-xl font-bold text-gray-800 dark:text-white">Rp 85.500.000</p>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col justify-between group hover:border-blue-300 transition-colors">
            <div class="flex justify-between items-start">
                <div class="w-10 h-10 rounded-lg bg-sky-50 text-sky-500 flex items-center justify-center font-bold">
                    <span class="material-symbols-rounded">account_balance_wallet</span>
                </div>
                <span class="text-xs text-gray-400">E-Wallet</span>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Operational</p>
                <p class="text-xl font-bold text-gray-800 dark:text-white">Rp 4.250.000</p>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm flex flex-col justify-between group hover:border-green-300 transition-colors">
            <div class="flex justify-between items-start">
                <div class="w-10 h-10 rounded-lg bg-green-50 text-green-600 flex items-center justify-center font-bold">
                    <span class="material-symbols-rounded">trending_up</span>
                </div>
                <span class="text-xs text-gray-400">Investasi</span>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">Emergency Fund</p>
                <p class="text-xl font-bold text-gray-800 dark:text-white">Rp 55.450.000</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold text-gray-800 dark:text-white">Arus Kas (Income vs Expense)</h3>
                <div class="flex items-center gap-2 text-xs">
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Masuk</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-500"></span> Keluar</span>
                </div>
            </div>
            <div id="cashflowChart" class="h-64 w-full"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-6 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col justify-center space-y-6">
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-500">Total Pemasukan (System + Manual)</span>
                    <span class="font-bold text-green-600">+ Rp 45.000.000</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1.5">
                    <div class="bg-green-500 h-1.5 rounded-full" style="width: 70%"></div>
                </div>
            </div>
            
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-500">Total Pengeluaran</span>
                    <span class="font-bold text-red-500">- Rp 12.500.000</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1.5">
                    <div class="bg-red-500 h-1.5 rounded-full" style="width: 30%"></div>
                </div>
            </div>

            <div class="p-4 bg-gray-50 rounded-xl border border-gray-100">
                <h4 class="font-bold text-gray-800 text-sm mb-2">Insight AI</h4>
                <p class="text-xs text-gray-500 leading-relaxed">
                    Pengeluaran iklan bulan ini naik 15%. Pastikan ROAS tetap terjaga. Saldo operasional di BCA cukup aman untuk 3 bulan ke depan.
                </p>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
            <h3 class="font-bold text-gray-800">Riwayat Transaksi</h3>
            <div class="relative">
                <input type="text" placeholder="Cari transaksi..." class="text-xs border-gray-200 rounded-lg focus:ring-[#304674] pl-8">
                <span class="material-symbols-rounded absolute left-2 top-1.5 text-gray-400 text-base">search</span>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-white text-gray-400 font-medium text-xs uppercase border-b border-gray-100">
                    <tr>
                        <th class="px-6 py-4">Tanggal</th>
                        <th class="px-6 py-4">Deskripsi</th>
                        <th class="px-6 py-4">Akun</th>
                        <th class="px-6 py-4">Kategori</th>
                        <th class="px-6 py-4 text-right">Nominal</th>
                        <th class="px-6 py-4 text-center">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-gray-500">10 Okt 2023</td>
                        <td class="px-6 py-4 font-medium text-gray-800">
                            Pencairan Escrow #ORD-23991
                            <div class="text-[10px] text-gray-400">Order ID: 23991</div>
                        </td>
                        <td class="px-6 py-4"><span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded">BCA</span></td>
                        <td class="px-6 py-4"><span class="text-xs border border-gray-200 text-gray-500 px-2 py-0.5 rounded">Sales Revenue</span></td>
                        <td class="px-6 py-4 text-right font-bold text-green-600">+ Rp 1.500.000</td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center gap-1 text-[10px] bg-orange-50 text-orange-600 px-2 py-0.5 rounded-full border border-orange-100">
                                <span class="material-symbols-rounded text-xs">api</span> Shopee
                            </span>
                        </td>
                    </tr>

                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-gray-500">09 Okt 2023</td>
                        <td class="px-6 py-4 font-medium text-gray-800">
                            Bayar Listrik Kantor
                        </td>
                        <td class="px-6 py-4"><span class="text-xs bg-sky-50 text-sky-600 px-2 py-0.5 rounded">Gopay</span></td>
                        <td class="px-6 py-4"><span class="text-xs border border-gray-200 text-gray-500 px-2 py-0.5 rounded">Utilities</span></td>
                        <td class="px-6 py-4 text-right font-bold text-red-500">- Rp 1.200.000</td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center gap-1 text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full border border-gray-200">
                                <span class="material-symbols-rounded text-xs">edit</span> Manual
                            </span>
                        </td>
                    </tr>
                     <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-gray-500">08 Okt 2023</td>
                        <td class="px-6 py-4 font-medium text-gray-800">
                            Dividen Saham BBCA
                        </td>
                        <td class="px-6 py-4"><span class="text-xs bg-green-50 text-green-600 px-2 py-0.5 rounded">Bibit</span></td>
                        <td class="px-6 py-4"><span class="text-xs border border-gray-200 text-gray-500 px-2 py-0.5 rounded">Investment Return</span></td>
                        <td class="px-6 py-4 text-right font-bold text-green-600">+ Rp 450.000</td>
                        <td class="px-6 py-4 text-center">
                            <span class="inline-flex items-center gap-1 text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full border border-gray-200">
                                <span class="material-symbols-rounded text-xs">edit</span> Manual
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div x-data="{ open: false }" 
         @open-transaction-modal.window="open = true" 
         x-show="open" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
         style="display: none;">
         
        <div @click.away="open = false" class="bg-white w-full max-w-md rounded-2xl p-6 shadow-2xl transform transition-all">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Catat Transaksi Manual</h3>
            
            <form class="space-y-4">
                <div class="grid grid-cols-2 gap-2 bg-gray-100 p-1 rounded-xl">
                    <button type="button" class="py-2 text-sm font-bold rounded-lg bg-white shadow text-green-600">Pemasukan</button>
                    <button type="button" class="py-2 text-sm font-medium rounded-lg text-gray-500 hover:text-red-500">Pengeluaran</button>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Nominal</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-gray-400 text-sm">Rp</span>
                        <input type="number" class="w-full pl-10 border-gray-200 rounded-xl focus:ring-[#304674]" placeholder="0">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-500 mb-1">Deskripsi</label>
                    <input type="text" class="w-full border-gray-200 rounded-xl focus:ring-[#304674]" placeholder="Contoh: Bayar Wi-Fi">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Akun</label>
                        <select class="w-full border-gray-200 rounded-xl text-sm">
                            <option>BCA</option>
                            <option>Gopay</option>
                            <option>Bibit</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Kategori</label>
                        <select class="w-full border-gray-200 rounded-xl text-sm">
                            <option>Operasional</option>
                            <option>Gaji</option>
                            <option>Marketing</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 flex gap-3">
                    <button type="button" @click="open = false" class="w-full py-2.5 rounded-xl border border-gray-200 text-gray-600 font-bold hover:bg-gray-50">Batal</button>
                    <button type="button" class="w-full py-2.5 rounded-xl bg-[#304674] text-white font-bold hover:bg-[#202f4d]">Simpan</button>
                </div>
            </form>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener('livewire:navigated', () => {
        initChart();
    });

    // Inisialisasi awal saat hard refresh
    document.addEventListener('DOMContentLoaded', () => {
        initChart();
    });

    function initChart() {
        const options = {
            series: [{
                name: 'Pemasukan',
                data: [31, 40, 28, 51, 42, 109, 100]
            }, {
                name: 'Pengeluaran',
                data: [11, 32, 45, 32, 34, 52, 41]
            }],
            chart: {
                height: 250,
                type: 'area',
                toolbar: { show: false },
                fontFamily: 'Figtree, sans-serif',
            },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            colors: ['#22c55e', '#ef4444'], // Green & Red
            xaxis: {
                categories: ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"],
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            grid: {
                borderColor: '#f1f1f1',
                strokeDashArray: 4,
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.4,
                    opacityTo: 0.05,
                    stops: [0, 90, 100]
                }
            }
        };

        const chart = new ApexCharts(document.querySelector("#cashflowChart"), options);
        chart.render();
    }
</script>