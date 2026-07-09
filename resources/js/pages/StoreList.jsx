import { useEffect, useState, useCallback } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";

const POLLING_INTERVAL = 10000;

// Removed ThemeToggle as it is now in navbar.jsx
// --- Komponen Status Pill ---
function StatusPill({ isActive }) {
    return (
        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold tracking-wide border ${
            isActive 
            ? "bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/20" 
            : "bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-200 dark:border-rose-500/20"
        }`}>
            <span className="relative flex h-2 w-2">
                {isActive && <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>}
                <span className={`relative inline-flex rounded-full h-2 w-2 ${isActive ? "bg-emerald-500" : "bg-rose-500"}`}></span>
            </span>
            {isActive ? "Terhubung" : "Terputus"}
        </span>
    );
}

// --- Komponen Platform Badge ---
function PlatformBadge({ platform, logo }) {
    const isShopee = platform.toLowerCase() === "shopee";
    const isTiktok = platform.toLowerCase() === "tiktok";
    const isTokopedia = platform.toLowerCase() === "tokopedia";

    let colorClass = "bg-slate-50 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700";
    if (isShopee) colorClass = "bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-200 dark:border-orange-500/20";
    if (isTiktok) colorClass = "bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-500/20";
    if (isTokopedia) colorClass = "bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 border-green-200 dark:border-green-500/20";

    return (
        <div className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border ${colorClass}`}>
            {logo ? (
                <img src={logo} alt={platform} className="w-5 h-5 object-contain rounded" />
            ) : (
                <span className="w-2 h-2 rounded-full bg-current opacity-50"></span>
            )}
            <span className="text-xs font-bold tracking-wide">{platform}</span>
        </div>
    );
}

// --- Komponen Progress Bar Sync ---
function SyncIndicator({ lastSync }) {
    const [elapsed, setElapsed] = useState(0);

    useEffect(() => {
        const t = setInterval(() => {
            setElapsed(Math.floor((Date.now() - lastSync) / 1000));
        }, 1000);
        return () => clearInterval(t);
    }, [lastSync]);

    const pct = Math.min((elapsed / (POLLING_INTERVAL / 1000)) * 100, 100);

    return (
        <div className="flex items-center gap-3">
            <div className="w-20 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div 
                    className="h-full bg-[#304674] dark:bg-blue-500 rounded-full transition-all duration-1000 ease-linear"
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-xs text-slate-500 dark:text-slate-400 font-mono tabular-nums">
                sync {elapsed}s ago
            </span>
        </div>
    );
}

// --- Komponen Skeleton Load ---
function SkeletonCard() {
    return (
        <div className="p-4 sm:p-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl animate-pulse flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div className="flex gap-4 items-center w-full sm:w-auto">
                <div className="w-12 h-12 bg-slate-200 dark:bg-slate-700 rounded-xl shrink-0"></div>
                <div className="space-y-2 w-full">
                    <div className="h-5 w-32 bg-slate-200 dark:bg-slate-700 rounded"></div>
                    <div className="h-4 w-24 bg-slate-200 dark:bg-slate-700 rounded"></div>
                </div>
            </div>
            <div className="flex justify-between sm:justify-end items-center w-full sm:w-auto gap-4">
                <div className="h-8 w-24 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                <div className="h-8 w-8 bg-slate-200 dark:bg-slate-700 rounded-xl"></div>
            </div>
        </div>
    );
}

// === MAIN COMPONENT ===
export default function StoreList() {
    const [stores, setStores] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState("all");
    const [search, setSearch] = useState("");
    const [lastSync, setLastSync] = useState(Date.now());
    const [syncing, setSyncing] = useState(false);
    const fetchStores = useCallback(async (manual = false) => {
        if (manual) setSyncing(true);
        try {
            const response = await axios.get("/api/stores");
            setStores(response.data);
            setLastSync(Date.now());
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
            if (manual) setTimeout(() => setSyncing(false), 600);
        }
    }, []);

    useEffect(() => {
        fetchStores();
        const interval = setInterval(() => fetchStores(), POLLING_INTERVAL);
        return () => clearInterval(interval);
    }, [fetchStores]);

    const platforms = ["all", ...new Set(stores.map((s) => s.platform))];

    const filtered = stores.filter((s) => {
        const matchPlatform = filter === "all" || s.platform === filter;
        const matchSearch =
            s.store_name.toLowerCase().includes(search.toLowerCase()) ||
            String(s.shop_id).includes(search);
        return matchPlatform && matchSearch;
    });

    const activeCount = stores.filter((s) => s.is_active).length;

    return (
        <AppLayout>
            <div className="min-h-screen min-w-screen bg-slate-50 dark:bg-slate-900 transition-colors duration-300 py-2 px-4 sm:px-6">
                <div className="mx-auto space-y-8 animate-fade-in-up">
                    
                    {/* --- HEADER --- */}
                    <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-6">
                        <div>
                            <h1 className="text-3xl font-bold text-slate-900 dark:text-white tracking-tight">
                                Toko Anda
                            </h1>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                {stores.length} toko terdaftar · <span className="text-emerald-600 dark:text-emerald-400 font-medium">{activeCount} terhubung</span>
                            </p>
                        </div>
                        <div className="flex items-center gap-3 w-full sm:w-auto">
                            <a
                                href="/shopee/connect"
                                className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-5 py-2.5 bg-[#304674] hover:bg-[#203155] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-sm font-medium rounded-xl shadow-lg shadow-[#304674]/20 dark:shadow-blue-900/30 transition-all active:scale-95"
                            >
                                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                                Hubungkan Toko
                            </a>
                        </div>
                    </div>

                    {/* --- STATISTIC CARDS --- */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        {[
                            { label: "Total Toko", value: stores.length, color: "text-slate-900 dark:text-white" },
                            { label: "Terhubung", value: activeCount, color: "text-emerald-600 dark:text-emerald-400" },
                            { label: "Terputus", value: stores.length - activeCount, color: "text-rose-600 dark:text-rose-400" },
                        ].map((stat) => (
                            <div key={stat.label} className="bg-white dark:bg-slate-800 p-5 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm transition-colors">
                                <p className="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    {stat.label}
                                </p>
                                <p className={`mt-2 text-3xl font-bold tracking-tight ${stat.color}`}>
                                    {stat.value}
                                </p>
                            </div>
                        ))}
                    </div>

                    {/* --- CONTROLS (Search & Filter) --- */}
                    <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 p-4 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-sm">
                        
                        {/* Search Input */}
                        <div className="relative w-full lg:w-80">
                            <svg className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <circle cx="11" cy="11" r="8" />
                                <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-4.35-4.35" />
                            </svg>
                            <input
                                type="text"
                                placeholder="Cari nama toko atau ID..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white text-sm rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-shadow"
                            />
                        </div>

                        {/* Filters & Actions */}
                        <div className="flex items-center justify-between w-full lg:w-auto gap-4 overflow-x-auto pb-1 lg:pb-0 hide-scrollbar">
                            <div className="flex gap-2">
                                {platforms.map((p) => (
                                    <button
                                        key={p}
                                        onClick={() => setFilter(p)}
                                        className={`px-4 py-2 rounded-xl text-xs font-semibold capitalize whitespace-nowrap transition-all ${
                                            filter === p
                                                ? "bg-[#304674] dark:bg-blue-600 text-white shadow-md"
                                                : "bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600"
                                        }`}
                                    >
                                        {p === "all" ? "Semua Platform" : p}
                                    </button>
                                ))}
                            </div>

                            {/* Sync Button */}
                            <button
                                onClick={() => fetchStores(true)}
                                className="p-2.5 bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 rounded-xl hover:bg-slate-200 dark:hover:bg-slate-600 transition-colors shrink-0"
                                title="Refresh data"
                            >
                                <svg 
                                    className={`${syncing ? "animate-spin" : ""}`} 
                                    width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"
                                >
                                    <path d="M21.5 2v6h-6M2.5 22v-6h6" />
                                    <path d="M2 12a10 10 0 0 1 18.76-4.5M22 12a10 10 0 0 1-18.76 4.5" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    {/* --- LIST DATA (Responsive Cards) --- */}
                    <div className="space-y-4">
                        {loading ? (
                            <>
                                <SkeletonCard />
                                <SkeletonCard />
                                <SkeletonCard />
                            </>
                        ) : filtered.length === 0 ? (
                            <div className="py-20 flex flex-col items-center justify-center text-center bg-white dark:bg-slate-800 rounded-3xl border border-slate-200 dark:border-slate-700 border-dashed">
                                <span className="text-4xl mb-4">🔌</span>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                    {search ? "Toko tidak ditemukan" : "Belum ada toko terhubung"}
                                </h3>
                                <p className="text-sm text-slate-500 dark:text-slate-400 mt-2">
                                    {search ? "Coba kata kunci lain atau ubah filter" : "Mulai hubungkan toko marketplace pertama Anda"}
                                </p>
                            </div>
                        ) : (
                            filtered.map((store) => (
                                <div 
                                    key={store.id} 
                                    className="group flex flex-col sm:flex-row items-start sm:items-center justify-between p-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 gap-4"
                                >
                                    {/* Info Kiri */}
                                    <div className="flex items-start gap-4 w-full sm:w-auto">
                                        <PlatformBadge platform={store.platform} logo={store.logo} />
                                        <div>
                                            <h3 className="font-bold text-slate-900 dark:text-white text-base leading-tight">
                                                {store.store_name}
                                            </h3>
                                            <code className="text-xs text-slate-500 dark:text-slate-400 font-mono mt-1 inline-block">
                                                #{store.shop_id}
                                            </code>
                                        </div>
                                    </div>

                                    {/* Action Kanan */}
                                    <div className="flex w-full sm:w-auto items-center justify-between sm:justify-end gap-6 pt-4 sm:pt-0 border-t sm:border-0 border-slate-100 dark:border-slate-700">
                                        <StatusPill isActive={store.is_active} />
                                        
                                        <div className="relative group">
                                            <a 
                                                href={`/connect/${store.platform.toLowerCase()}`}
                                                className="flex items-center justify-center p-2 text-slate-400 hover:text-[#304674] dark:hover:text-blue-400 bg-slate-50 dark:bg-slate-900 hover:bg-blue-50 dark:hover:bg-slate-800 rounded-xl transition-all"
                                            >
                                                <span className="material-symbols-rounded text-lg group-hover:rotate-180 transition-transform duration-500">
                                                    sync
                                                </span>
                                            </a>
                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none w-max z-10">
                                                <div className="bg-slate-800 text-white text-[10px] font-medium px-2.5 py-1.5 rounded-lg shadow-lg">
                                                    Otorisasi Ulang Toko
                                                </div>
                                                <div className="w-2 h-2 bg-slate-800 transform rotate-45 absolute -bottom-1 left-1/2 -translate-x-1/2"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    {/* --- FOOTER --- */}
                    {!loading && filtered.length > 0 && (
                        <div className="flex flex-col sm:flex-row justify-between items-center gap-4 py-4 px-2">
                            <span className="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                Menampilkan {filtered.length} dari {stores.length} toko
                            </span>
                            <SyncIndicator lastSync={lastSync} />
                        </div>
                    )}
                </div>
            </div>

            {/* Tambahan Animasi Global Sederhana */}
            <style jsx global>{`
                .hide-scrollbar::-webkit-scrollbar { display: none; }
                .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up {
                    animation: fadeInUp 0.4s ease-out forwards;
                }
            `}</style>
        </AppLayout>
    );
}