import { useEffect, useState, useCallback } from "react";
import { createPortal } from "react-dom";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import OnboardingTour from "@/components/OnboardingTour";
import { useOnboarding } from "@/hooks/useOnboarding";

const STORE_TOUR_STEPS = [
    {
        selector: "#tour-connect-btn",
        title: "Hubungkan Toko",
        description: "Klik tombol ini untuk menghubungkan toko Shopee atau TikTok Shop Anda. Setelah terhubung, pesanan akan otomatis tersinkronisasi.",
        position: "bottom",
    },
    {
        selector: "#tour-store-list",
        title: "Daftar Toko",
        description: "Semua toko yang terhubung tampil di sini. Indikator hijau berarti koneksi aktif. Anda bisa disconnect atau hapus toko dari menu di setiap kartu.",
        position: "top",
    },
    {
        selector: null,
        title: "Token Otomatis Diperbarui",
        description: "Finesheet secara otomatis memperbarui token OAuth setiap toko agar koneksi tidak pernah terputus. Anda tidak perlu login ulang secara rutin.",
        position: "bottom",
    },
];

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
            ) : isShopee ? (
                <img src="/Marketplace-logo/shopee.png" alt="Shopee" className="w-5 h-5 object-contain rounded" />
            ) : isTiktok ? (
                <img src="/Marketplace-logo/tts.png" alt="TikTok" className="w-5 h-5 object-contain rounded" />
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
    const tour = useOnboarding("stores", STORE_TOUR_STEPS.length);
    
    // State Hapus Toko
    const [storeToDelete, setStoreToDelete] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
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

    const handleDelete = async () => {
        if (!storeToDelete) return;
        setIsDeleting(true);
        try {
            await axios.delete(`/api/stores/${storeToDelete.id}`);
            import('react-hot-toast').then(({ toast }) => {
                toast.success('Toko beserta seluruh datanya berhasil dihapus!', {
                    icon: '🗑️',
                    style: {
                        borderRadius: '10px',
                        background: '#333',
                        color: '#fff',
                    },
                });
            });
            setStoreToDelete(null);
            fetchStores();
        } catch (error) {
            import('react-hot-toast').then(({ toast }) => {
                toast.error('Gagal menghapus toko.');
            });
            console.error('Error deleting store:', error);
        } finally {
            setIsDeleting(false);
        }
    };

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
                        <div id="tour-connect-btn" className="flex items-center gap-3 w-full sm:w-auto relative group">
                            <button
                                className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-5 py-2.5 bg-[#304674] hover:bg-[#203155] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-sm font-medium rounded-xl shadow-lg shadow-[#304674]/20 dark:shadow-blue-900/30 transition-all active:scale-95"
                            >
                                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                                Hubungkan Toko
                                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="ml-1 transition-transform group-hover:-rotate-180">
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </button>

                            {/* Dropdown Menu */}
                            <div className="absolute top-full right-0 mt-2 w-56 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden translate-y-2 group-hover:translate-y-0">
                                <div className="p-1.5">
                                    <a
                                        href="/connect/shopee"
                                        className="flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-orange-50 dark:hover:bg-orange-500/10 hover:text-orange-600 dark:hover:text-orange-400 rounded-lg transition-colors"
                                    >
                                        <div className="w-8 h-8 rounded bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center font-bold">
                                            <img src="/Marketplace-logo/shopee.png" alt="Shopee" className="w-5 h-5 object-contain" />
                                        </div>
                                        Shopee
                                    </a>
                                    <a
                                        href="/connect/tiktok"
                                        className="flex items-center gap-3 px-3 py-2.5 mt-1 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-green-50 dark:hover:bg-emerald-500/10 hover:text-emerald-700 dark:hover:text-emerald-400 rounded-lg transition-colors"
                                    >
                                        <div className="w-8 h-8 rounded bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center font-bold">
                                            <img src="/Marketplace-logo/tts.png" alt="TikTok" className="w-5 h-5 object-contain" />
                                        </div>
                                        TikTok Shop
                                    </a>
                                </div>
                            </div>
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
                            {/* <button
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
                            </button> */}
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
                                    className="flex flex-col sm:flex-row items-start sm:items-center justify-between p-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 gap-4"
                                >
                                    {/* Info Kiri */}
                                    <div className="flex items-start gap-4 w-full sm:w-auto min-w-0 flex-1">
                                        <PlatformBadge platform={store.platform} logo={store.logo} />
                                        <div className="flex-1 min-w-0">
                                            <h3 className="font-bold text-slate-900 dark:text-white text-base leading-tight truncate">
                                                {store.store_name}
                                            </h3>
                                            <code className="text-xs text-slate-500 dark:text-slate-400 font-mono mt-1 inline-block truncate max-w-full">
                                                #{store.shop_id}
                                            </code>
                                        </div>
                                    </div>

                                    {/* Action Kanan */}
                                    <div className="flex w-full sm:w-auto items-center justify-between sm:justify-end gap-6 pt-4 sm:pt-0 border-t sm:border-0 border-slate-100 dark:border-slate-700">
                                        <StatusPill isActive={store.is_active} />
                                        
                                        <div className="flex gap-2">
                                            <div className="relative group">
                                                <a 
                                                    href={`/connect/${store.platform.toLowerCase() === 'tiktokshop' ? 'tiktok' : store.platform.toLowerCase()}`}
                                                    className="flex items-center justify-center p-2 text-[#304674] dark:text-blue-400 bg-blue-50 dark:bg-slate-800 hover:bg-blue-100 dark:hover:bg-slate-700 rounded-xl transition-all"
                                                >
                                                    <span className="material-symbols-rounded text-lg group-hover:rotate-180 transition-transform duration-500">
                                                        sync
                                                    </span>
                                                </a>
                                                <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none w-max z-10">
                                                    <div className="bg-slate-800 text-white text-[10px] font-medium px-2.5 py-1.5 rounded-lg shadow-lg">
                                                        Otorisasi Ulang
                                                    </div>
                                                    <div className="w-2 h-2 bg-slate-800 transform rotate-45 absolute -bottom-1 left-1/2 -translate-x-1/2"></div>
                                                </div>
                                            </div>

                                            <div className="relative group">
                                                <button 
                                                    onClick={() => setStoreToDelete(store)}
                                                    className="flex items-center justify-center min-h-[45px] p-2 text-rose-500 dark:text-rose-400 bg-rose-50 dark:bg-rose-500/10 hover:bg-rose-100 dark:hover:bg-rose-500/20 rounded-xl transition-all"
                                                >
                                                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2M10 11v6M14 11v6" />
                                                    </svg>
                                                </button>
                                                <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none w-max z-10">
                                                    <div className="bg-rose-600 text-white text-[10px] font-medium px-2.5 py-1.5 rounded-lg shadow-lg">
                                                        Hapus Toko
                                                    </div>
                                                    <div className="w-2 h-2 bg-rose-600 transform rotate-45 absolute -bottom-1 left-1/2 -translate-x-1/2"></div>
                                                </div>
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
                            {/* <SyncIndicator lastSync={lastSync} /> */}
                        </div>
                    )}
                </div>
            </div>

            {/* --- MODAL HAPUS TOKO --- */}
            {storeToDelete && createPortal(
                <div className="fixed inset-0 z-[9999] flex items-center justify-center px-4">
                    {/* Backdrop */}
                    <div 
                        className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" 
                        onClick={() => !isDeleting && setStoreToDelete(null)}
                    ></div>
                    
                    {/* Modal Content */}
                    <div className="relative bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl shadow-2xl p-6 sm:p-8 overflow-hidden animate-fade-in-up border border-slate-200 dark:border-slate-800">
                        {/* Decorative background element */}
                        <div className="absolute top-0 right-0 -mr-8 -mt-8 w-32 h-32 rounded-full bg-rose-500/10 blur-2xl"></div>
                        
                        <div className="flex flex-col items-center text-center relative z-10">
                            {/* Platform Icon Dynamics */}
                            <div className={`w-16 h-16 rounded-full flex items-center justify-center shadow-lg mb-5 ${
                                storeToDelete.platform.toLowerCase() === 'shopee' 
                                ? 'bg-gradient-to-br from-orange-400 to-orange-600 shadow-orange-500/30' 
                                : 'bg-gradient-to-br from-slate-800 to-black shadow-cyan-500/20 border border-slate-700'
                            }`}>
                                {storeToDelete.platform.toLowerCase() === 'shopee' ? (
                                    <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                ) : (
                                    <svg className="w-8 h-8 text-cyan-400" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 15.68a6.34 6.34 0 006.27 6.36 6.34 6.34 0 006.33-6.36v-6.32a8.28 8.28 0 004 1.05V6.84a4.93 4.93 0 01-2.01-.15z"/>
                                    </svg>
                                )}
                            </div>

                            <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-2">
                                Hapus Toko {storeToDelete.store_name}?
                            </h3>
                            <p className="text-sm text-slate-500 dark:text-slate-400 leading-relaxed mb-8">
                                Tindakan ini hanya akan memutuskan koneksi toko ini dari Finesheet Omnichannel. 
                                <br/><br/>
                                <strong className="text-rose-600 dark:text-rose-400 font-semibold bg-rose-50 dark:bg-rose-900/20 px-2 py-1 rounded">Semua data pesanan & produk di database lokal akan ikut terhapus!</strong> 
                                <br/><br/>
                                (Toko asli Anda di {storeToDelete.platform} tetap aman).
                            </p>

                            <div className="flex items-center gap-3 w-full">
                                <button 
                                    onClick={() => setStoreToDelete(null)}
                                    disabled={isDeleting}
                                    className="flex-1 py-3 px-4 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-bold rounded-xl transition-colors disabled:opacity-50"
                                >
                                    Batal
                                </button>
                                <button 
                                    onClick={handleDelete}
                                    disabled={isDeleting}
                                    className="flex-1 py-3 px-4 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-rose-600/30 transition-all active:scale-95 disabled:opacity-50 flex justify-center items-center gap-2"
                                >
                                    {isDeleting ? (
                                        <>
                                            <svg className="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                            Menghapus...
                                        </>
                                    ) : (
                                        "Ya, Hapus Toko"
                                    )}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>,
                document.body
            )}

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
            <OnboardingTour
                steps={STORE_TOUR_STEPS}
                isOpen={tour.isOpen}
                currentStep={tour.currentStep}
                onNext={tour.next}
                onPrev={tour.prev}
                onSkip={tour.skip}
                onFinish={tour.finish}
                onStart={tour.start}
            />
        </AppLayout>
    );
}