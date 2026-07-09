import { useState, useEffect, useCallback, useRef } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import { motion, AnimatePresence } from "framer-motion";
const POLLING_INTERVAL = 10000;

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

const STATUS_CONFIG = {
    READY_TO_SHIP: { label: "Perlu Dikirim", bg: "bg-yellow-50 dark:bg-yellow-500/10", text: "text-yellow-700 dark:text-yellow-400", border: "border-yellow-200 dark:border-yellow-500/20" },
    TO_CONFIRM_RECEIVE: { label: "Perlu Diproses", bg: "bg-green-50 dark:bg-green-500/10", text: "text-green-700 dark:text-green-400", border: "border-green-200 dark:border-green-500/20" },
    PROCESSED: { label: "Diproses", bg: "bg-blue-50 dark:bg-blue-500/10", text: "text-blue-700 dark:text-blue-400", border: "border-blue-200 dark:border-blue-500/20" },
    SHIPPED: { label: "Sedang Dikirim", bg: "bg-purple-50 dark:bg-purple-500/10", text: "text-purple-700 dark:text-purple-400", border: "border-purple-200 dark:border-purple-500/20" },
    COMPLETED: { label: "Selesai", bg: "bg-green-50 dark:bg-green-500/10", text: "text-green-700 dark:text-green-400", border: "border-green-200 dark:border-green-500/20" },
    CANCELLED: { label: "Batal", bg: "bg-red-50 dark:bg-red-500/10", text: "text-red-700 dark:text-red-400", border: "border-red-200 dark:border-red-500/20" },
};

const PLATFORM_CONFIG = {
    Shopee: { bg: "bg-orange-50 dark:bg-orange-500/10", text: "text-orange-600 dark:text-orange-400", icon: "S" },
    Tokopedia: { bg: "bg-green-50 dark:bg-green-500/10", text: "text-green-600 dark:text-green-400", icon: "T" },
    Tiktokshop: { bg: "bg-gray-100 dark:bg-slate-700", text: "text-black dark:text-white", icon: "♪" },
};

function StatusBadge({ status }) {
    const cfg = STATUS_CONFIG[status] || { label: status, bg: "bg-gray-50", text: "text-gray-600", border: "border-gray-200" };
    return (
        <span className={`px-2.5 py-1 rounded-full text-xs font-bold border ${cfg.bg} ${cfg.text} ${cfg.border}`}>
            {cfg.label}
        </span>
    );
}

function SkeletonRow() {
    return (
        <div className="animate-pulse flex items-center gap-4 p-5 border-b border-gray-50 dark:border-slate-700/50">
            <div className="w-8 h-8 bg-slate-200 dark:bg-slate-700 rounded"></div>
            <div className="flex-1 space-y-2">
                <div className="h-4 w-40 bg-slate-200 dark:bg-slate-700 rounded"></div>
                <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded"></div>
            </div>
            <div className="h-6 w-20 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
        </div>
    );
}

export default function OrderList() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selectedStatuses, setSelectedStatuses] = useState([]);
    const [selectedStore, setSelectedStore] = useState("");
    const [searchQuery, setSearchQuery] = useState("");
    const [expandedOrders, setExpandedOrders] = useState({});
    const [isStoreDropdownOpen, setIsStoreDropdownOpen] = useState(false);
    const storeDropdownRef = useRef(null);
    const [syncing, setSyncing] = useState(false);

    const handleSync = async () => {
        setSyncing(true);
        try {
            await axios.post("/api/sync/orders");
            // Re-fetch immediately in case some data was updated fast,
            // the 10s polling will catch the rest.
            fetchData();
        } catch (err) {
            console.error("Failed to sync orders", err);
        } finally {
            setTimeout(() => setSyncing(false), 2000); // Visual feedback
        }
    };

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (storeDropdownRef.current && !storeDropdownRef.current.contains(e.target)) {
                setIsStoreDropdownOpen(false);
            }
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    const fetchData = useCallback(async () => {
        try {
            const params = {};
            if (selectedStatuses.length) params.statuses = selectedStatuses.join(",");
            if (selectedStore) params.store_id = selectedStore;
            const res = await axios.get("/api/orders", { params });
            setData(res.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, [selectedStatuses, selectedStore]);

    useEffect(() => {
        setLoading(true);
        fetchData();
    }, [fetchData]);

    // Polling
    useEffect(() => {
        const interval = setInterval(() => fetchData(), POLLING_INTERVAL);
        return () => clearInterval(interval);
    }, [fetchData]);

    const toggleStatus = (status) => {
        setSelectedStatuses(prev =>
            prev.includes(status) ? prev.filter(s => s !== status) : [...prev, status]
        );
    };

    const toggleExpand = (orderId) => {
        setExpandedOrders(prev => ({ ...prev, [orderId]: !prev[orderId] }));
    };

    // Group orders by store
    const ordersByStore = {};
    if (data?.orders) {
        data.orders.forEach(order => {
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                const matchOrderId = order.order_sn?.toLowerCase().includes(q);
                const matchItemName = order.items?.some(item => item.item_name?.toLowerCase().includes(q)) || 
                                      order.first_item?.item_name?.toLowerCase().includes(q);
                if (!matchOrderId && !matchItemName) {
                    return;
                }
            }

            if (!ordersByStore[order.store_id]) ordersByStore[order.store_id] = [];
            ordersByStore[order.store_id].push(order);
        });
    }

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 py-2 px-1 sm:px-2">
                <div className="space-y-6 pb-20 animate-fade-in-up">

                    {/* Header */}
                    <div className="flex flex-col gap-6">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Order Management</h1>
                                <p className="text-sm text-gray-500 dark:text-slate-400">Kelola dan pantau status pesanan dari semua marketplace.</p>
                            </div>

                            {/* Search and Store Filter */}
                            <div className="flex flex-col md:flex-row items-center gap-3 w-full md:w-auto">
                                <button 
                                    onClick={handleSync}
                                    disabled={syncing}
                                    className="flex w-full md:w-auto items-center justify-center gap-2 px-4 py-2 bg-[#304674] hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-70 whitespace-nowrap"
                                >
                                    <span className={`material-symbols-rounded text-[20px] ${syncing ? 'animate-spin' : ''}`}>sync</span>
                                    {syncing ? 'Menyelaraskan...' : 'Tarik Data Shopee'}
                                </button>
                                <div className="relative w-full md:w-64">
                                    <span className="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">search</span>
                                    <input 
                                        type="text" 
                                        placeholder="Cari order ID atau produk..." 
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        className="w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-10 pr-4 rounded-xl focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-[#304674] dark:focus:border-blue-500 shadow-sm text-sm transition-all outline-none"
                                    />
                                </div>
                                <div className="relative w-full md:w-64" ref={storeDropdownRef}>
                                    <button
                                        onClick={() => setIsStoreDropdownOpen(!isStoreDropdownOpen)}
                                        className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-4 pr-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-sm transition-all hover:border-gray-300 dark:hover:border-slate-600"
                                    >
                                        <span className="truncate pr-2">
                                            {selectedStore === "" 
                                                ? "Semua Toko" 
                                                : data?.stores?.find(s => s.id == selectedStore)?.store_name || "Toko Tidak Diketahui"
                                            }
                                        </span>
                                        <span className={`material-symbols-rounded text-gray-400 dark:text-slate-500 transition-transform duration-300 ${isStoreDropdownOpen ? "rotate-180" : ""}`}>
                                            expand_more
                                        </span>
                                    </button>

                                    <AnimatePresence>
                                        {isStoreDropdownOpen && (
                                            <motion.div
                                                initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                                animate={{ opacity: 1, y: 0, scale: 1 }}
                                                exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                                transition={{ duration: 0.2, ease: "easeOut" }}
                                                className="absolute right-0 md:left-0 mt-2 w-full min-w-[240px] bg-white dark:bg-slate-900 rounded-2xl shadow-lg border border-slate-100 dark:border-slate-800 overflow-hidden z-50 origin-top"
                                            >
                                                <div className="max-h-64 overflow-y-auto p-2">
                                                    <button
                                                        onClick={() => {
                                                            setSelectedStore("");
                                                            setIsStoreDropdownOpen(false);
                                                        }}
                                                        className={`w-full text-left px-3 py-2 text-sm font-medium rounded-xl transition-colors ${
                                                            selectedStore === ""
                                                                ? "bg-[#304674] text-white dark:bg-blue-600"
                                                                : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                        }`}
                                                    >
                                                        Semua Toko
                                                    </button>
                                                    {data?.stores?.map(store => (
                                                        <button
                                                            key={store.id}
                                                            onClick={() => {
                                                                setSelectedStore(store.id);
                                                                setIsStoreDropdownOpen(false);
                                                            }}
                                                            className={`w-full flex items-center justify-between text-left px-3 py-2 mt-1 text-sm font-medium rounded-xl transition-colors ${
                                                                selectedStore == store.id
                                                                    ? "bg-[#304674] text-white dark:bg-blue-600"
                                                                    : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                            }`}
                                                        >
                                                            <span className="truncate">{store.store_name}</span>
                                                            <span className={`text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full ${
                                                                selectedStore == store.id
                                                                    ? "bg-white/20 text-white"
                                                                    : "bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400"
                                                            }`}>
                                                                {store.platform}
                                                            </span>
                                                        </button>
                                                    ))}
                                                </div>
                                            </motion.div>
                                        )}
                                    </AnimatePresence>
                                </div>
                            </div>
                        </div>

                        {/* Status Tabs */}
                        <div className="border-b border-gray-200 dark:border-slate-700">
                            <div className="flex overflow-x-auto gap-4 pb-2 no-scrollbar">
                                <button
                                    onClick={() => setSelectedStatuses([])}
                                    className={`whitespace-nowrap pb-2 text-sm font-medium border-b-2 transition-colors ${
                                        selectedStatuses.length === 0
                                            ? "border-[#304674] dark:border-blue-500 text-[#304674] dark:text-blue-400"
                                            : "border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300"
                                    }`}
                                >
                                    Semua
                                </button>
                                {Object.entries(STATUS_CONFIG).map(([key, cfg]) => (
                                    <button
                                        key={key}
                                        onClick={() => toggleStatus(key)}
                                        className={`whitespace-nowrap pb-2 text-sm font-medium border-b-2 transition-colors ${
                                            selectedStatuses.includes(key)
                                                ? "border-[#304674] dark:border-blue-500 text-[#304674] dark:text-blue-400"
                                                : "border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300"
                                        }`}
                                    >
                                        {cfg.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Order Content */}
                    {loading ? (
                        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                            <SkeletonRow />
                            <SkeletonRow />
                            <SkeletonRow />
                        </div>
                    ) : !data?.stores?.length ? (
                        <div className="bg-white dark:bg-slate-800 rounded-2xl p-12 text-center border border-dashed border-gray-300 dark:border-slate-600">
                            <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 dark:bg-slate-700 mb-4">
                                <span className="material-symbols-rounded text-3xl text-gray-300 dark:text-slate-500">shopping_cart_off</span>
                            </div>
                            <h3 className="text-lg font-bold text-gray-800 dark:text-white">Belum ada pesanan</h3>
                            <p className="text-gray-500 dark:text-slate-400">Coba ubah filter atau sinkronisasi data toko Anda.</p>
                        </div>
                    ) : (
                        data.stores.map((store) => {
                            const storeOrders = ordersByStore[store.id] || [];
                            if (storeOrders.length === 0) return null;

                            const theme = PLATFORM_CONFIG[store.platform] || { bg: "bg-blue-50", text: "text-blue-600", icon: "?" };

                            return (
                                <div key={store.id} className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                    {/* Store Header */}
                                    <div className="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/30 flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className={`w-8 h-8 rounded-lg ${theme.bg} ${theme.text} flex items-center justify-center font-bold text-lg border border-white dark:border-slate-600 shadow-sm`}>
                                                {theme.icon}
                                            </div>
                                            <h2 className="font-bold text-gray-800 dark:text-white">{store.store_name}</h2>
                                            <span className="text-xs text-gray-400 dark:text-slate-500 font-mono hidden sm:inline">ID: {store.id}</span>
                                        </div>
                                        <span className="text-xs font-medium bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-1 rounded-md">{storeOrders.length} Orders</span>
                                    </div>

                                    {/* Desktop Table */}
                                    <div className="hidden md:block overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-white dark:bg-slate-800 text-gray-400 dark:text-slate-500 font-medium text-xs uppercase border-b border-gray-100 dark:border-slate-700">
                                                <tr>
                                                    <th className="px-6 py-4 w-10"></th>
                                                    <th className="px-6 py-4">Order Details</th>
                                                    <th className="px-6 py-4">Items Summary</th>
                                                    <th className="px-6 py-4">Status</th>
                                                    <th className="px-6 py-4 text-right">Total Price</th>
                                                    <th className="px-6 py-4 text-right">Escrow (Cuan)</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-50 dark:divide-slate-700/50">
                                                {storeOrders.map((order) => (
                                                    <>
                                                        <tr key={order.id} className="hover:bg-gray-50/50 dark:hover:bg-slate-700/30 transition-colors group">
                                                            <td className="px-6 py-4 align-top">
                                                                <button
                                                                    onClick={() => toggleExpand(order.id)}
                                                                    className={`text-gray-400 dark:text-slate-500 hover:text-[#304674] dark:hover:text-blue-400 transition-transform duration-200 ${expandedOrders[order.id] ? "rotate-180" : ""}`}
                                                                >
                                                                    <span className="material-symbols-rounded">expand_more</span>
                                                                </button>
                                                            </td>
                                                            <td className="px-6 py-4 align-top">
                                                                <div className="font-bold text-[#304674] dark:text-blue-400 font-mono">{order.order_sn}</div>
                                                                <div className="text-xs text-gray-400 dark:text-slate-500 mt-1">{order.created_at}</div>
                                                            </td>
                                                            <td className="px-6 py-4 align-top">
                                                                <div className="flex items-center gap-3">
                                                                    {order.first_item?.image ? (
                                                                        <img src={order.first_item.image} className="w-10 h-10 rounded-md border border-gray-200 dark:border-slate-600 object-cover" alt="" />
                                                                    ) : (
                                                                        <div className="w-10 h-10 rounded-md bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                            <span className="material-symbols-rounded text-sm text-gray-300 dark:text-slate-500">image</span>
                                                                        </div>
                                                                    )}
                                                                    <div>
                                                                        <p className="font-medium text-gray-700 dark:text-slate-300 truncate w-48">{order.first_item?.item_name}</p>
                                                                        {order.item_count > 1 ? (
                                                                            <span className="text-xs text-blue-600 dark:text-blue-400 font-medium">+{order.item_count - 1} produk lainnya</span>
                                                                        ) : (
                                                                            <span className="text-xs text-gray-400 dark:text-slate-500">Variant: {order.first_item?.model_name}</span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="px-6 py-4 align-top"><StatusBadge status={order.order_status} /></td>
                                                            <td className="px-6 py-4 align-top text-right font-medium text-gray-600 dark:text-slate-300">{formatRp(order.order_selling_price)}</td>
                                                            <td className="px-6 py-4 align-top text-right font-bold text-[#304674] dark:text-blue-400">{formatRp(order.escrow_amount)}</td>
                                                        </tr>
                                                        {/* Expanded Detail */}
                                                        {expandedOrders[order.id] && (
                                                            <tr key={`${order.id}-detail`} className="bg-gray-50/50 dark:bg-slate-900/30">
                                                                <td colSpan={6} className="px-6 py-4">
                                                                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
                                                                        <h4 className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-3">Detail Pesanan</h4>
                                                                        <div className="space-y-3">
                                                                            {order.items?.map((item, idx) => (
                                                                                <div key={idx} className="flex items-start justify-between">
                                                                                    <div className="flex items-start gap-3">
                                                                                        {item.image ? (
                                                                                            <img src={item.image} className="w-12 h-12 rounded-lg border border-gray-100 dark:border-slate-600" alt="" />
                                                                                        ) : (
                                                                                            <div className="w-12 h-12 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                                                <span className="material-symbols-rounded text-sm text-gray-300 dark:text-slate-500">image</span>
                                                                                            </div>
                                                                                        )}
                                                                                        <div>
                                                                                            <p className="text-sm font-semibold text-gray-700 dark:text-slate-200">{item.item_name}</p>
                                                                                            <p className="text-xs text-gray-500 dark:text-slate-400">Var: {item.model_name} • Qty: {item.quantity_purchased}</p>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        )}
                                                    </>
                                                ))}
                                            </tbody>
                                            <tfoot className="bg-gray-50 dark:bg-slate-900/30 border-t border-gray-100 dark:border-slate-700">
                                                <tr>
                                                    <td colSpan={4} className="px-6 py-3 font-bold text-gray-500 dark:text-slate-400 text-right">Total Summary:</td>
                                                    <td className="px-6 py-3 font-bold text-gray-800 dark:text-white text-right">{formatRp(storeOrders.reduce((s, o) => s + (o.order_selling_price || 0), 0))}</td>
                                                    <td className="px-6 py-3 font-bold text-green-600 dark:text-green-400 text-right">{formatRp(storeOrders.reduce((s, o) => s + (o.escrow_amount || 0), 0))}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    {/* Mobile Cards */}
                                    <div className="md:hidden p-4 space-y-4 bg-gray-50/50 dark:bg-slate-900/30">
                                        {storeOrders.map((order) => {
                                            const statusCfg = STATUS_CONFIG[order.order_status] || {};
                                            return (
                                                <div key={order.id} className="bg-white dark:bg-slate-800 rounded-xl p-4 shadow-sm border border-gray-100 dark:border-slate-700">
                                                    <div className="flex justify-between items-start mb-3">
                                                        <div>
                                                            <div className="flex items-center gap-2 mb-1">
                                                                <span className="font-mono text-xs text-gray-500 dark:text-slate-400">#{order.order_sn}</span>
                                                                <StatusBadge status={order.order_status} />
                                                            </div>
                                                            <p className="text-xs text-gray-400 dark:text-slate-500">{order.created_at}</p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-xs text-gray-400 dark:text-slate-500">Escrow</p>
                                                            <p className="text-sm font-bold text-green-600 dark:text-green-400">{formatRp(order.escrow_amount)}</p>
                                                        </div>
                                                    </div>

                                                    <div
                                                        className="flex items-center gap-3 bg-gray-50 dark:bg-slate-900/50 p-2 rounded-lg cursor-pointer"
                                                        onClick={() => toggleExpand(order.id)}
                                                    >
                                                        {order.first_item?.image ? (
                                                            <img src={order.first_item.image} className="w-10 h-10 rounded border border-gray-200 dark:border-slate-600" alt="" />
                                                        ) : (
                                                            <div className="w-10 h-10 rounded bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                <span className="material-symbols-rounded text-sm text-gray-300">image</span>
                                                            </div>
                                                        )}
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium text-gray-700 dark:text-slate-300 truncate">{order.first_item?.item_name}</p>
                                                            {order.item_count > 1 ? (
                                                                <p className="text-xs text-blue-600 dark:text-blue-400">+{order.item_count - 1} produk lainnya</p>
                                                            ) : (
                                                                <p className="text-xs text-gray-400 dark:text-slate-500">Qty: {order.first_item?.quantity}</p>
                                                            )}
                                                        </div>
                                                        <span className={`material-symbols-rounded text-gray-400 dark:text-slate-500 transition-transform ${expandedOrders[order.id] ? "rotate-180" : ""}`}>expand_more</span>
                                                    </div>

                                                    {expandedOrders[order.id] && (
                                                        <div className="mt-3 space-y-3 border-t border-gray-100 dark:border-slate-700 pt-3">
                                                            {order.items?.map((item, idx) => (
                                                                <div key={idx} className="flex items-start justify-between text-xs">
                                                                    <span className="text-gray-600 dark:text-slate-300 w-2/3">{item.item_name} <span className="text-gray-400 dark:text-slate-500">(x{item.quantity_purchased})</span></span>
                                                                </div>
                                                            ))}
                                                            <div className="border-t border-dashed border-gray-200 dark:border-slate-700 pt-2 flex justify-between items-center">
                                                                <span className="text-xs font-bold text-gray-600 dark:text-slate-300">Total Penjualan</span>
                                                                <span className="text-sm font-bold text-gray-800 dark:text-white">{formatRp(order.order_selling_price)}</span>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            </div>

            <style>{`
                .no-scrollbar::-webkit-scrollbar { display: none; }
                .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
            `}</style>
        </AppLayout>
    );
}
