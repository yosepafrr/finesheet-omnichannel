import React, { useState, useEffect, useCallback, useRef, Fragment } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import { motion, AnimatePresence } from "framer-motion";

const POLLING_INTERVAL = 10000;

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

function PlatformIcon({ platform }) {
    if (platform === "Shopee") return <span className="text-orange-500 font-bold text-lg">S</span>;
    if (platform === "Tiktokshop") return <span className="text-black dark:text-white font-bold text-lg">♪</span>;
    return <span className="material-symbols-rounded text-gray-400">store</span>;
}

function StockBadge({ stock }) {
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium ${stock > 0 ? "bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400" : "bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400"}`}>
            {stock}
        </span>
    );
}

// Inline HPP Editor
function HppEditor({ type, id, hpp, onSave }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(hpp || 0);
    const [saving, setSaving] = useState(false);

    const handleSave = async () => {
        setSaving(true);
        try {
            const endpoint = type === "variant"
                ? `/api/variants/${id}/hpp`
                : `/api/products/${id}/hpp`;
            await axios.put(endpoint, { hpp: Number(value) });
            onSave?.(Number(value));
            setEditing(false);
        } catch (err) {
            console.error("Failed to save HPP:", err);
        } finally {
            setSaving(false);
        }
    };

    if (editing) {
        return (
            <div className="flex items-center gap-1 animate-fadeIn">
                <div className="relative w-full">
                    <span className="absolute left-2 top-1.5 text-xs text-gray-400">Rp</span>
                    <input
                        type="number"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        className="w-full pl-7 pr-1 py-1 text-xs border border-[#304674] dark:border-blue-500 rounded-md focus:ring-1 focus:ring-[#304674] dark:focus:ring-blue-500 focus:outline-none bg-white dark:bg-slate-900 text-gray-800 dark:text-white"
                        placeholder="0"
                        autoFocus
                    />
                </div>
                <button
                    onClick={handleSave}
                    disabled={saving}
                    className="p-1 bg-[#304674] dark:bg-blue-600 text-white rounded hover:bg-[#25365a] dark:hover:bg-blue-700 disabled:opacity-50"
                    title="Save"
                >
                    <span className="material-symbols-rounded text-sm">{saving ? "hourglass_empty" : "check"}</span>
                </button>
                <button
                    onClick={() => setEditing(false)}
                    className="p-1 bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 rounded hover:bg-gray-300 dark:hover:bg-slate-600"
                    title="Cancel"
                >
                    <span className="material-symbols-rounded text-sm">close</span>
                </button>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-between group/edit gap-4">
            <span className={`font-medium ${hpp ? "dark:text-slate-300" : "text-gray-400 dark:text-slate-500 italic text-xs"}`}>
                {hpp ? formatRp(hpp) : "Belum diisi"}
            </span>
            <button
                onClick={() => { setValue(hpp || 0); setEditing(true); }}
                className="text-gray-300 dark:text-slate-600 hover:text-[#304674] dark:hover:text-blue-400 transition-colors opacity-0 group-hover/edit:opacity-100"
            >
                <span className="material-symbols-rounded text-sm">edit</span>
            </button>
        </div>
    );
}

// Mobile HPP Editor (always visible edit button)
function HppEditorMobile({ type, id, hpp, onSave }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(hpp || 0);
    const [saving, setSaving] = useState(false);

    const handleSave = async () => {
        setSaving(true);
        try {
            const endpoint = type === "variant"
                ? `/api/variants/${id}/hpp`
                : `/api/products/${id}/hpp`;
            await axios.put(endpoint, { hpp: Number(value) });
            onSave?.(Number(value));
            setEditing(false);
        } catch (err) {
            console.error(err);
        } finally {
            setSaving(false);
        }
    };

    if (editing) {
        return (
            <div className="flex items-center gap-1 w-full">
                <div className="relative flex-1">
                    <span className="absolute left-2 top-1.5 text-xs text-gray-400">Rp</span>
                    <input type="number" value={value} onChange={(e) => setValue(e.target.value)}
                        className="w-full pl-7 pr-1 py-1 text-xs border border-[#304674] dark:border-blue-500 rounded-md bg-white dark:bg-slate-900 text-gray-800 dark:text-white" autoFocus />
                </div>
                <button onClick={handleSave} disabled={saving} className="p-1 bg-[#304674] dark:bg-blue-600 text-white rounded text-xs">
                    <span className="material-symbols-rounded text-sm">check</span>
                </button>
                <button onClick={() => setEditing(false)} className="p-1 bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 rounded">
                    <span className="material-symbols-rounded text-sm">close</span>
                </button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2 w-full justify-end">
            <span className={`text-xs font-medium ${hpp ? "text-gray-600 dark:text-slate-300" : "text-gray-400 dark:text-slate-500 italic"}`}>
                {hpp ? formatRp(hpp) : "Belum diisi"}
            </span>
            <button onClick={() => { setValue(hpp || 0); setEditing(true); }}
                className="bg-gray-100 dark:bg-slate-700 p-1 rounded text-gray-400 dark:text-slate-400 hover:text-[#304674] dark:hover:text-blue-400">
                <span className="material-symbols-rounded text-sm">edit</span>
            </button>
        </div>
    );
}

// Skeleton loader
function SkeletonTable() {
    return (
        <div className="animate-pulse p-6 space-y-4">
            {[1, 2, 3].map(i => (
                <div key={i} className="flex items-center gap-4">
                    <div className="w-12 h-12 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
                    <div className="flex-1 space-y-2">
                        <div className="h-4 w-48 bg-slate-200 dark:bg-slate-700 rounded"></div>
                        <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded"></div>
                    </div>
                    <div className="h-6 w-16 bg-slate-200 dark:bg-slate-700 rounded"></div>
                </div>
            ))}
        </div>
    );
}

function LimitDropdown({ value, onChange }) {
    const [isOpen, setIsOpen] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) setIsOpen(false);
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    return (
        <div className="relative" ref={dropdownRef}>
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="group flex items-center gap-2 px-3 py-1.5 rounded-full hover:bg-gray-100 dark:hover:bg-slate-800 border border-transparent hover:border-slate-200 dark:hover:border-slate-700 transition-all focus:outline-none"
            >
                <span className="text-sm font-semibold text-slate-700 dark:text-slate-200 group-hover:text-[#304674] dark:group-hover:text-blue-400 transition-colors">
                    {value}
                </span>
                <span className={`material-symbols-rounded text-slate-400 transition-transform duration-300 ${isOpen ? "rotate-180" : ""}`}>
                    expand_more
                </span>
            </button>
            <AnimatePresence>
                {isOpen && (
                    <motion.div
                        initial={{ opacity: 0, y: 10, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 10, scale: 0.95 }}
                        transition={{ duration: 0.2, ease: "easeOut" }}
                        className="absolute left-0 bottom-full mb-2 w-32 bg-white dark:bg-slate-900 rounded-2xl shadow-lg border border-slate-100 dark:border-slate-800 overflow-hidden origin-bottom-left z-50"
                    >
                        <div className="p-2">
                            {[30, 50, 100].map(option => (
                                <button
                                    key={option}
                                    onClick={() => {
                                        onChange(option);
                                        setIsOpen(false);
                                    }}
                                    className={`w-full flex items-center px-3 py-2 text-sm font-medium rounded-xl transition-colors text-left ${value === option ? 'text-[#304674] bg-slate-50 dark:bg-slate-800 dark:text-blue-400' : 'text-slate-600 dark:text-slate-300 hover:text-[#304674] hover:bg-slate-50 dark:hover:bg-slate-800'}`}
                                >
                                    {option} Data
                                </button>
                            ))}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

export default function ProductList() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [expandedProducts, setExpandedProducts] = useState({});
    const [syncing, setSyncing] = useState(false);
    const [limitByStore, setLimitByStore] = useState({});
    const [pageByStore, setPageByStore] = useState({});

    useEffect(() => {
        setPageByStore({});
    }, [search]);

    const handleSync = async () => {
        setSyncing(true);
        try {
            await axios.post("/api/sync/products");
            // Re-fetch immediately in case some data was updated fast, 
            // the 10s polling will catch the rest.
            fetchData();
        } catch (err) {
            console.error("Failed to sync products", err);
        } finally {
            setTimeout(() => setSyncing(false), 2000); // Visual feedback
        }
    };

    const fetchData = useCallback(async () => {
        try {
            const res = await axios.get("/api/products", { params: { search } });
            setData(res.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, [search]);

    useEffect(() => {
        setLoading(true);
        const timeout = setTimeout(() => fetchData(), 300);
        return () => clearTimeout(timeout);
    }, [fetchData]);

    // Polling
    useEffect(() => {
        const interval = setInterval(() => fetchData(), POLLING_INTERVAL);
        return () => clearInterval(interval);
    }, [fetchData]);

    const toggleExpand = (productId) => {
        setExpandedProducts(prev => ({ ...prev, [productId]: !prev[productId] }));
    };

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 py-2 px-1 sm:px-2">
                <div className="space-y-8 pb-10 animate-fade-in-up">

                    {/* Header */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 sticky top-0 z-20 bg-slate-50 dark:bg-slate-900 pt-2 pb-2">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Product Management</h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">Kelola stok, harga, dan HPP produk dari semua toko Anda.</p>
                        </div>
                        <div className="flex flex-col md:flex-row items-center gap-3 w-full md:w-auto">
                            <button 
                                onClick={handleSync}
                                disabled={syncing}
                                className="flex items-center justify-center gap-2 px-4 py-2 bg-[#304674] hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-70 whitespace-nowrap"
                            >
                                <span className={`material-symbols-rounded text-[20px] ${syncing ? 'animate-spin' : ''}`}>sync</span>
                                {syncing ? 'Menyelaraskan...' : 'Tarik Data Shopee'}
                            </button>
                            <div className="relative w-full md:w-64">
                                <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <span className="material-symbols-rounded text-lg">search</span>
                                </span>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Cari produk..."
                                    className="w-full pl-10 pr-4 py-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl text-sm text-gray-800 dark:text-white focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-[#304674] dark:focus:border-blue-500 transition"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Store Groups */}
                    {loading ? (
                        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                            <SkeletonTable />
                        </div>
                    ) : !data?.stores?.length ? (
                        <div className="bg-white dark:bg-slate-800 rounded-2xl p-10 text-center border border-dashed border-gray-300 dark:border-slate-600">
                            <p className="text-gray-500 dark:text-slate-400">Tidak ada toko yang terhubung.</p>
                        </div>
                    ) : (
                        data.stores.map((store) => (
                            <div key={store.id} className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                                {/* Store Header */}
                                <div className="bg-gray-50/80 dark:bg-slate-700/50 p-4 border-b border-gray-100 dark:border-slate-600 flex flex-col sm:flex-row sm:items-center justify-between gap-4 backdrop-blur-sm">
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-lg bg-white dark:bg-slate-800 shadow-sm border border-gray-100 dark:border-slate-600 flex items-center justify-center">
                                            <PlatformIcon platform={store.platform} />
                                        </div>
                                        <div>
                                            <h2 className="font-bold text-gray-800 dark:text-white text-lg">{store.store_name || "Unknown Store"}</h2>
                                            <span className="text-xs font-medium text-gray-500 dark:text-slate-400 bg-gray-200 dark:bg-slate-600 px-2 py-0.5 rounded-full">{store.platform}</span>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-xs text-gray-400 dark:text-slate-500 hidden sm:block">{store.product_count} Produk Ditemukan</span>
                                    </div>
                                </div>

                                {/* Product Content */}
                                {store.products.length === 0 ? (
                                    <div className="p-12 text-center">
                                        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 dark:bg-slate-700 mb-4">
                                            <span className="material-symbols-rounded text-3xl text-gray-300 dark:text-slate-500">inventory_2</span>
                                        </div>
                                        <p className="text-gray-500 dark:text-slate-400 font-medium">Tidak ada data produk.</p>
                                        <p className="text-xs text-gray-400 dark:text-slate-500 mb-6">Sinkronisasi data untuk mengambil produk terbaru.</p>
                                    </div>
                                ) : (
                                    (() => {
                                        const totalProducts = store.products.length;
                                        const itemsPerPage = limitByStore[store.id] || 30;
                                        const totalPages = Math.ceil(totalProducts / itemsPerPage);
                                        const currentPage = pageByStore[store.id] || 1;
                                        const validPage = Math.min(currentPage, totalPages > 0 ? totalPages : 1);
                                        const paginatedProducts = store.products.slice((validPage - 1) * itemsPerPage, validPage * itemsPerPage);

                                        return (
                                            <>
                                                {/* Desktop Table */}
                                                <div className="hidden md:block overflow-x-auto">
                                            <table className="w-full text-left text-sm text-gray-600 dark:text-slate-300">
                                                <thead className="bg-white dark:bg-slate-800 border-b border-gray-100 dark:border-slate-700 text-xs uppercase text-gray-400 dark:text-slate-500 font-semibold sticky top-0 z-10">
                                                    <tr>
                                                        <th className="px-6 py-4 w-10"></th>
                                                        <th className="px-6 py-4 w-24"></th>
                                                        <th className="px-6 py-4 w-1/4">Product Info</th>
                                                        <th className="px-6 py-4 text-center">Stock</th>
                                                        <th className="px-6 py-4">Price</th>
                                                        <th className="px-6 py-4 w-60">HPP (Modal)</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-50 dark:divide-slate-700/50">
                                                    {paginatedProducts.map((product) => {
                                                        const variants = product.variants || [];
                                                        const hasVariants = variants.length > 0;
                                                        
                                                        let totalStock = product.stock;
                                                        let priceDisplay = formatRp(product.price);
                                                        let hppDisplay = (product.hpp && product.hpp > 0) ? formatRp(product.hpp) : "Belum diisi";
                                                        
                                                        if (hasVariants) {
                                                            totalStock = variants.reduce((sum, v) => sum + (v.stock || 0), 0);
                                                            const prices = variants.map(v => v.price).filter(p => p != null);
                                                            if (prices.length > 0) {
                                                                const minPrice = Math.min(...prices);
                                                                const maxPrice = Math.max(...prices);
                                                                priceDisplay = minPrice === maxPrice 
                                                                    ? formatRp(minPrice) 
                                                                    : `${formatRp(minPrice)} - ${formatRp(maxPrice)}`;
                                                            }

                                                            const hpps = variants.map(v => v.hpp).filter(h => h != null && h > 0);
                                                            if (hpps.length > 0) {
                                                                const minHpp = Math.min(...hpps);
                                                                const maxHpp = Math.max(...hpps);
                                                                hppDisplay = minHpp === maxHpp 
                                                                    ? formatRp(minHpp) 
                                                                    : `${formatRp(minHpp)} - ${formatRp(maxHpp)}`;
                                                            } else {
                                                                hppDisplay = "Belum diisi";
                                                            }
                                                        }
                                                        
                                                        return (
                                                            <Fragment key={product.id}>
                                                                <tr className="group hover:bg-gray-50/50 dark:hover:bg-slate-700/30 transition-colors">
                                                                    <td className="px-6 py-4 align-top">
                                                                        {hasVariants && (
                                                                            <button
                                                                                onClick={() => toggleExpand(product.id)}
                                                                                className={`text-gray-400 dark:text-slate-500 hover:text-[#304674] dark:hover:text-blue-400 transition-transform duration-200 ${expandedProducts[product.id] ? "rotate-180" : ""}`}
                                                                            >
                                                                                <span className="material-symbols-rounded">expand_more</span>
                                                                            </button>
                                                                        )}
                                                                    </td>
                                                                    <td className="px-6 py-4 align-top">
                                                                        {product.image ? (
                                                                            <img src={product.image} className="w-12 h-12 rounded-lg object-cover border border-gray-100 dark:border-slate-600 shadow-sm" alt="" />
                                                                        ) : (
                                                                            <div className="w-12 h-12 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                                <span className="material-symbols-rounded text-gray-300 dark:text-slate-500">image</span>
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    <td className="px-6 py-4 align-top">
                                                                        <p className="font-semibold text-gray-800 dark:text-slate-200 text-sm leading-snug line-clamp-2">{product.item_name || "Unknown"}</p>
                                                                        <p className="text-xs text-gray-400 dark:text-slate-500 font-mono mt-1">{product.item_sku || "-"}</p>
                                                                        {hasVariants && (
                                                                            <div className="mt-2 flex gap-2">
                                                                                <span className="text-[10px] bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-2 py-0.5 rounded">{variants.length} Varian</span>
                                                                            </div>
                                                                        )}
                                                                    </td>
                                                                    {hasVariants ? (
                                                                        <>
                                                                            <td className="px-6 py-4 text-center"><StockBadge stock={totalStock} /></td>
                                                                            <td className="px-6 py-4 font-medium text-gray-700 dark:text-slate-300">{priceDisplay}</td>
                                                                            <td className="px-6 py-4 font-medium text-gray-700 dark:text-slate-300">
                                                                                {hppDisplay === "Belum diisi" ? (
                                                                                    <span className="text-gray-400 dark:text-slate-500 italic text-xs">Belum diisi</span>
                                                                                ) : (
                                                                                    hppDisplay
                                                                                )}
                                                                            </td>
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <td className="px-6 py-4 text-center"><StockBadge stock={product.stock} /></td>
                                                                            <td className="px-6 py-4 font-medium text-gray-700 dark:text-slate-300">{formatRp(product.price)}</td>
                                                                            <td className="px-6 py-4">
                                                                                <HppEditor type="item" id={product.id} hpp={product.hpp} onSave={() => fetchData()} />
                                                                            </td>
                                                                        </>
                                                                    )}
                                                                </tr>

                                                                {/* Expanded Variant Details */}
                                                                {hasVariants && expandedProducts[product.id] && (
                                                                    <tr key={`${product.id}-detail`} className="bg-gray-50/50 dark:bg-slate-900/30">
                                                                        <td colSpan={6} className="px-6 py-4">
                                                                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm ml-10">
                                                                                <h4 className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-3">Detail Varian</h4>
                                                                                <div className="overflow-x-auto">
                                                                                    <table className="w-full text-left text-sm">
                                                                                        <thead className="text-xs uppercase text-gray-400 dark:text-slate-500 border-b border-gray-100 dark:border-slate-700">
                                                                                            <tr>
                                                                                                <th className="pb-3 w-1/3">Varian / SKU</th>
                                                                                                <th className="pb-3 text-center">Stock</th>
                                                                                                <th className="pb-3">Price</th>
                                                                                                <th className="pb-3 w-60">HPP (Modal)</th>
                                                                                            </tr>
                                                                                        </thead>
                                                                                        <tbody className="divide-y divide-gray-50 dark:divide-slate-700/50">
                                                                                            {variants.map(variant => (
                                                                                                <tr key={variant.id} className="hover:bg-gray-50/50 dark:hover:bg-slate-700/30 transition-colors">
                                                                                                    <td className="py-3 align-middle pr-4">
                                                                                                        <div className="flex flex-col">
                                                                                                            <span className="font-medium text-gray-700 dark:text-slate-300 text-xs">{variant.model_name}</span>
                                                                                                            <span className="text-[10px] text-gray-400 dark:text-slate-500 font-mono mt-0.5">{variant.model_sku || "-"}</span>
                                                                                                        </div>
                                                                                                    </td>
                                                                                                    <td className="py-3 text-center"><StockBadge stock={variant.stock} /></td>
                                                                                                    <td className="py-3 font-medium text-gray-700 dark:text-slate-300">{formatRp(variant.price)}</td>
                                                                                                    <td className="py-3">
                                                                                                        <HppEditor type="variant" id={variant.id} hpp={variant.hpp} onSave={() => fetchData()} />
                                                                                                    </td>
                                                                                                </tr>
                                                                                            ))}
                                                                                        </tbody>
                                                                                    </table>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                )}
                                                            </Fragment>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>

                                        {/* Mobile Cards */}
                                        <div className="md:hidden p-4 space-y-4 bg-gray-50/50 dark:bg-slate-900/30">
                                            {paginatedProducts.map((product) => {
                                                const variants = product.variants || [];
                                                const hasVariants = variants.length > 0;
                                                
                                                let totalStock = product.stock;
                                                let priceDisplay = formatRp(product.price);
                                                let hppDisplay = (product.hpp && product.hpp > 0) ? formatRp(product.hpp) : "Belum diisi";
                                                
                                                if (hasVariants) {
                                                    totalStock = variants.reduce((sum, v) => sum + (v.stock || 0), 0);
                                                    const prices = variants.map(v => v.price).filter(p => p != null);
                                                    if (prices.length > 0) {
                                                        const minPrice = Math.min(...prices);
                                                        const maxPrice = Math.max(...prices);
                                                        priceDisplay = minPrice === maxPrice 
                                                            ? formatRp(minPrice) 
                                                            : `${formatRp(minPrice)} - ${formatRp(maxPrice)}`;
                                                    }

                                                    const hpps = variants.map(v => v.hpp).filter(h => h != null && h > 0);
                                                    if (hpps.length > 0) {
                                                        const minHpp = Math.min(...hpps);
                                                        const maxHpp = Math.max(...hpps);
                                                        hppDisplay = minHpp === maxHpp 
                                                            ? formatRp(minHpp) 
                                                            : `${formatRp(minHpp)} - ${formatRp(maxHpp)}`;
                                                    } else {
                                                        hppDisplay = "Belum diisi";
                                                    }
                                                }

                                                return (
                                                <div key={product.id} className="bg-white dark:bg-slate-800 rounded-xl p-4 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden">
                                                    <div className="flex gap-4">
                                                        {product.image ? (
                                                            <img src={product.image} className="w-16 h-16 rounded-lg object-cover border border-gray-100 dark:border-slate-600 shrink-0" alt="" />
                                                        ) : (
                                                            <div className="w-16 h-16 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center shrink-0">
                                                                <span className="material-symbols-rounded text-gray-300 dark:text-slate-500">image</span>
                                                            </div>
                                                        )}
                                                        <div className="flex-1 min-w-0">
                                                            <h3 className="text-sm font-bold text-gray-800 dark:text-white leading-tight line-clamp-2">{product.item_name}</h3>
                                                            <p className="text-xs text-gray-400 dark:text-slate-500 font-mono mt-1">{product.item_sku || "No SKU"}</p>

                                                            <div className="mt-3 flex items-center justify-between border-t border-dashed border-gray-100 dark:border-slate-700 pt-2">
                                                                <div className="flex-1">
                                                                    <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wider">Harga Jual</p>
                                                                    <p className="text-sm font-bold text-[#304674] dark:text-blue-400">{priceDisplay}</p>
                                                                </div>
                                                                {hasVariants && (
                                                                    <div className="flex-1 px-2 text-center">
                                                                        <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wider">HPP (Modal)</p>
                                                                        <p className="text-sm font-bold text-gray-700 dark:text-slate-300">
                                                                            {hppDisplay === "Belum diisi" ? <span className="text-[10px] text-rose-500 italic font-normal">Belum diisi</span> : hppDisplay}
                                                                        </p>
                                                                    </div>
                                                                )}
                                                                <div className="flex-1 text-right">
                                                                    <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wider">Stok Total</p>
                                                                    <span className={`text-[10px] px-2 py-0.5 rounded font-medium mt-1 inline-block ${totalStock > 0 ? "bg-emerald-100 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400" : "bg-rose-100 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400"}`}>
                                                                        {totalStock}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            
                                                            {!hasVariants ? (
                                                                <div className="mt-2 flex justify-end">
                                                                    <HppEditorMobile type="item" id={product.id} hpp={product.hpp} onSave={() => fetchData()} />
                                                                </div>
                                                            ) : (
                                                                <button
                                                                    onClick={() => toggleExpand(product.id)}
                                                                    className="mt-3 w-full py-1.5 bg-gray-50 hover:bg-gray-100 dark:bg-slate-700/50 dark:hover:bg-slate-700 text-xs font-medium text-[#304674] dark:text-blue-400 rounded-lg flex items-center justify-center gap-1 transition-colors"
                                                                >
                                                                    <span>{expandedProducts[product.id] ? "Tutup Varian" : `Lihat ${product.variants.length} Varian`}</span>
                                                                    <span className={`material-symbols-rounded text-base transition-transform ${expandedProducts[product.id] ? "rotate-180" : ""}`}>expand_more</span>
                                                                </button>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Expanded Variants (Mobile) */}
                                                    {product.variants?.length > 0 && expandedProducts[product.id] && (
                                                        <div className="mt-3 pt-3 border-t border-gray-100 dark:border-slate-700 space-y-3 bg-gray-50 dark:bg-slate-900/50 -mx-4 px-4 pb-2">
                                                            {product.variants.map((variant) => (
                                                                <div key={variant.id} className="bg-white dark:bg-slate-800 p-3 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm">
                                                                    <div className="flex justify-between items-start mb-2">
                                                                        <div>
                                                                            <p className="text-xs font-bold text-gray-700 dark:text-slate-200">{variant.model_name}</p>
                                                                            <p className="text-[10px] text-gray-400 dark:text-slate-500 font-mono">{variant.model_sku}</p>
                                                                        </div>
                                                                        <span className={`text-[10px] px-2 py-0.5 rounded ${variant.stock > 0 ? "bg-green-100 dark:bg-green-500/10 text-green-700 dark:text-green-400" : "bg-red-100 dark:bg-red-500/10 text-red-700 dark:text-red-400"}`}>
                                                                            Stok: {variant.stock}
                                                                        </span>
                                                                    </div>
                                                                    <div className="flex items-center justify-between">
                                                                        <span className="text-xs font-medium text-gray-600 dark:text-slate-300">{formatRp(variant.price)}</span>
                                                                        <div className="w-1/2 flex justify-end">
                                                                            <HppEditorMobile type="variant" id={variant.id} hpp={variant.hpp} onSave={() => fetchData()} />
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                                );
                                            })}
                                        </div>

                                        {/* Pagination Controls */}
                                        <div className="px-6 py-4 border-t border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                                            <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                                                    <div className="flex items-center gap-1">
                                                        <span className="text-sm text-gray-500 dark:text-slate-400">Tampilkan maksimal:</span>
                                                        <LimitDropdown 
                                                            value={itemsPerPage} 
                                                            onChange={(val) => {
                                                                setLimitByStore(prev => ({...prev, [store.id]: val}));
                                                                setPageByStore(prev => ({...prev, [store.id]: 1}));
                                                            }} 
                                                        />
                                                    </div>
                                                    <span className="text-sm text-gray-500 dark:text-slate-400">
                                                        Menampilkan {(validPage - 1) * itemsPerPage + 1} - {Math.min(validPage * itemsPerPage, totalProducts)} dari {totalProducts} produk
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <button 
                                                        onClick={() => setPageByStore(p => ({...p, [store.id]: validPage - 1}))}
                                                        disabled={validPage === 1}
                                                        className="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                                                    >
                                                        <span className="material-symbols-rounded text-xl">chevron_left</span>
                                                    </button>
                                                    <span className="text-sm font-medium text-gray-700 dark:text-slate-300 min-w-[60px] text-center">
                                                        {validPage} / {totalPages}
                                                    </span>
                                                    <button 
                                                        onClick={() => setPageByStore(p => ({...p, [store.id]: validPage + 1}))}
                                                        disabled={validPage === totalPages}
                                                        className="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                                                    >
                                                        <span className="material-symbols-rounded text-xl">chevron_right</span>
                                                    </button>
                                                </div>
                                            </div>
                                    </>
                                        );
                                    })()
                                )}
                            </div>
                        ))
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
