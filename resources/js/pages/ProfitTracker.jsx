import { useState, useEffect, useCallback } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";

const POLLING_INTERVAL = 5000;

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

const PLATFORM_CONFIG = {
    Shopee: { bg: "bg-orange-50 dark:bg-orange-500/10", text: "text-orange-600 dark:text-orange-400", border: "border-orange-100 dark:border-orange-500/20", icon: "S" },
    Tokopedia: { bg: "bg-green-50 dark:bg-green-500/10", text: "text-green-600 dark:text-green-400", border: "border-green-100 dark:border-green-500/20", icon: "T" },
    Tiktokshop: { bg: "bg-gray-100 dark:bg-slate-700", text: "text-black dark:text-white", border: "border-gray-200 dark:border-slate-600", icon: "♪" },
};

function SkeletonCards() {
    return (
        <div className="animate-pulse space-y-8">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                {[1, 2, 3].map(i => (
                    <div key={i} className="h-40 bg-slate-200 dark:bg-slate-700 rounded-2xl"></div>
                ))}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                {[1, 2, 3, 4].map(i => (
                    <div key={i} className="h-48 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
                ))}
            </div>
        </div>
    );
}

export default function ProfitTracker() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    const fetchData = useCallback(async () => {
        try {
            const res = await axios.get("/api/profit-tracker");
            setData(res.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchData();
        const interval = setInterval(fetchData, POLLING_INTERVAL);
        return () => clearInterval(interval);
    }, [fetchData]);

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 py-2 px-1 sm:px-2">
                <div className="space-y-8 pb-10 animate-fade-in-up">

                    {/* Header */}
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Profit & Cashflow Tracker</h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">Pemantauan dana escrow dan pengeluaran iklan secara real-time.</p>
                        </div>

                        <div className="flex items-center gap-2 bg-white dark:bg-slate-800 px-3 py-1.5 rounded-full border border-gray-200 dark:border-slate-700 shadow-sm">
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                            </span>
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-300">Live Update (5s)</span>
                        </div>
                    </div>

                    {loading ? <SkeletonCards /> : data && (
                        <>
                            {/* Summary Cards */}
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                                {/* Total Escrow */}
                                <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden group">
                                    <div className="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity transform group-hover:scale-110 duration-500">
                                        <span className="material-symbols-rounded text-8xl text-green-600 dark:text-green-400">savings</span>
                                    </div>
                                    <div className="relative z-10">
                                        <p className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Total Dana Escrow</p>
                                        <h3 className="text-3xl font-bold text-gray-800 dark:text-white transition-all duration-300">
                                            {formatRp(data.total_escrow_amount)}
                                        </h3>
                                        <div className="mt-4 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 text-xs font-medium">
                                            <span className="material-symbols-rounded text-sm">arrow_upward</span>
                                            Pemasukan Bruto
                                        </div>
                                    </div>
                                </div>

                                {/* Total Ads */}
                                <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden group">
                                    <div className="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity transform group-hover:scale-110 duration-500">
                                        <span className="material-symbols-rounded text-8xl text-red-500 dark:text-red-400">campaign</span>
                                    </div>
                                    <div className="relative z-10">
                                        <p className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">Total Ads Spent</p>
                                        <h3 className="text-3xl font-bold text-gray-800 dark:text-white">
                                            Coming soon ...
                                        </h3>
                                        <div className="mt-4 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 text-xs font-medium">
                                            <span className="material-symbols-rounded text-sm">arrow_downward</span>
                                            Beban Iklan
                                        </div>
                                    </div>
                                </div>

                                {/* Net Profit */}
                                <div className="bg-gradient-to-br from-[#304674] to-[#1e2f50] p-6 rounded-2xl shadow-lg shadow-blue-900/20 relative overflow-hidden text-white group">
                                    <div className="absolute inset-0 opacity-10" style={{ backgroundImage: "radial-gradient(circle, #ffffff 1px, transparent 1px)", backgroundSize: "20px 20px" }}></div>
                                    <div className="absolute right-0 top-0 p-4 opacity-20 group-hover:opacity-30 transition-opacity transform group-hover:rotate-12 duration-500">
                                        <span className="material-symbols-rounded text-8xl text-white">account_balance_wallet</span>
                                    </div>
                                    <div className="relative z-10">
                                        <p className="text-xs font-bold text-blue-200 uppercase tracking-wider mb-1">Estimasi Profit Bersih</p>
                                        <h3 className="text-3xl font-bold">
                                            Coming soon ...
                                        </h3>
                                        <div className="mt-4 flex items-center gap-3">
                                            <div className="bg-white/10 backdrop-blur-sm border border-white/20 px-3 py-1 rounded-lg flex items-center gap-2">
                                                {data.total_escrow_amount > 0 ? (
                                                    <>
                                                        <span className="text-xs text-blue-100">Margin:</span>
                                                        {/* <span className="text-sm font-bold">{data.margin}%</span> */}
                                                        <span className="text-sm font-bold">Coming soon ...</span>
                                                    </>
                                                ) : (
                                                    <span className="text-xs text-blue-100">Menunggu Data</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Per-Store Breakdown */}
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-bold text-gray-800 dark:text-white flex items-center gap-2">
                                        <span className="material-symbols-rounded text-[#304674] dark:text-blue-400">storefront</span>
                                        Rincian Per Toko
                                    </h3>
                                    <span className="text-xs text-gray-500 dark:text-slate-400">Diurutkan berdasarkan nominal escrow</span>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                                    {data.stores?.map((store) => {
                                        const theme = PLATFORM_CONFIG[store.platform] || { bg: "bg-gray-50", text: "text-gray-600", border: "border-gray-100", icon: "?" };
                                        return (
                                            <div key={store.id} className="bg-white dark:bg-slate-800 rounded-xl p-5 shadow-sm border border-gray-200 dark:border-slate-700 hover:shadow-md transition-shadow duration-200 flex flex-col justify-between h-full group">
                                                <div className="flex items-start justify-between mb-4">
                                                    <div className="flex items-center gap-3">
                                                        <div className={`w-10 h-10 rounded-lg ${theme.bg} ${theme.text} border ${theme.border} flex items-center justify-center font-bold text-lg shadow-sm`}>
                                                            {theme.icon}
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <h4 className="font-bold text-gray-800 dark:text-white text-sm truncate w-32" title={store.store_name}>
                                                                {store.store_name}
                                                            </h4>
                                                            <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wide">{store.platform}</p>
                                                        </div>
                                                    </div>
                                                    {/* <button className="text-gray-300 dark:text-slate-600 hover:text-[#304674] dark:hover:text-blue-400">
                                                        <span className="material-symbols-rounded text-lg">more_vert</span>
                                                    </button> */}
                                                </div>

                                                <div>
                                                    <p className="text-[10px] text-gray-400 dark:text-slate-500 font-medium mb-1">Escrow Balance</p>
                                                    <div className="flex items-end justify-between">
                                                        <span className="text-xl font-bold text-[#304674] dark:text-blue-400">
                                                            {formatRp(store.escrow)}
                                                        </span>
                                                    </div>
                                                    <div className="w-full bg-gray-100 dark:bg-slate-700 rounded-full h-1.5 mt-3 overflow-hidden">
                                                        <div
                                                            className="bg-[#304674] dark:bg-blue-500 h-1.5 rounded-full transition-all duration-500"
                                                            style={{ width: `${store.percent}%` }}
                                                        ></div>
                                                    </div>
                                                    <p className="text-[10px] text-gray-400 dark:text-slate-500 mt-1 text-right">{store.percent}% dari total</p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
            `}</style>
        </AppLayout>
    );
}
