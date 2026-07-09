import { useState, useEffect, useCallback } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";

const POLLING_INTERVAL = 10000;

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

function PlatformIcon({ platform }) {
    if (platform === "Shopee") return <span className="text-orange-500 font-bold text-lg">S</span>;
    if (platform === "Tokopedia") return <span className="text-green-500 font-bold text-lg">T</span>;
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
            <span className={`text-xs font-medium ${hpp ? "text-gray-600 dark:text-slate-300" : "text-gray-400 dark:text-slate-500 italic"}`}>
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

export default function ProductList() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState("");
    const [expandedProducts, setExpandedProducts] = useState({});

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
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">Product Management</h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">Kelola stok, harga, dan HPP produk dari semua toko Anda.</p>
                        </div>
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
                                    <>
                                        {/* Desktop Table */}
                                        <div className="hidden md:block overflow-x-auto">
                                            <table className="w-full text-left text-sm text-gray-600 dark:text-slate-300">
                                                <thead className="bg-white dark:bg-slate-800 border-b border-gray-100 dark:border-slate-700 text-xs uppercase text-gray-400 dark:text-slate-500 font-semibold sticky top-0 z-10">
                                                    <tr>
                                                        <th className="px-6 py-4 w-24">Img</th>
                                                        <th className="px-6 py-4 w-1/4">Product Info</th>
                                                        <th className="px-6 py-4">Variant / SKU</th>
                                                        <th className="px-6 py-4 text-center">Stock</th>
                                                        <th className="px-6 py-4">Price</th>
                                                        <th className="px-6 py-4 w-60">HPP (Modal)</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-50 dark:divide-slate-700/50">
                                                    {store.products.map((product) => {
                                                        const variants = product.variants || [];
                                                        if (variants.length > 0) {
                                                            return variants.map((variant, i) => (
                                                                <tr key={`${product.id}-v-${variant.id}`} className="group hover:bg-blue-50/30 dark:hover:bg-blue-500/5 transition-colors">
                                                                    {i === 0 && (
                                                                        <>
                                                                            <td className="px-6 py-4 align-top border-r border-dashed border-gray-100 dark:border-slate-700" rowSpan={variants.length}>
                                                                                {product.image ? (
                                                                                    <img src={product.image} className="w-12 h-12 rounded-lg object-cover border border-gray-100 dark:border-slate-600 shadow-sm" alt="" />
                                                                                ) : (
                                                                                    <div className="w-12 h-12 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                                        <span className="material-symbols-rounded text-gray-300 dark:text-slate-500">image</span>
                                                                                    </div>
                                                                                )}
                                                                            </td>
                                                                            <td className="px-6 py-4 align-top border-r border-dashed border-gray-100 dark:border-slate-700" rowSpan={variants.length}>
                                                                                <p className="font-semibold text-gray-800 dark:text-slate-200 text-sm leading-snug line-clamp-2">{product.item_name || "Unknown"}</p>
                                                                                <div className="mt-2 flex gap-2">
                                                                                    <span className="text-[10px] bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 px-2 py-0.5 rounded">{variants.length} Varian</span>
                                                                                </div>
                                                                            </td>
                                                                        </>
                                                                    )}
                                                                    <td className="px-6 py-3 align-middle">
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium text-gray-700 dark:text-slate-300 text-xs">{variant.model_name}</span>
                                                                            <span className="text-[10px] text-gray-400 dark:text-slate-500 font-mono mt-0.5">{variant.model_sku || "-"}</span>
                                                                        </div>
                                                                    </td>
                                                                    <td className="px-6 py-3 text-center"><StockBadge stock={variant.stock} /></td>
                                                                    <td className="px-6 py-3 font-medium text-gray-700 dark:text-slate-300">{formatRp(variant.price)}</td>
                                                                    <td className="px-6 py-3">
                                                                        <HppEditor type="variant" id={variant.id} hpp={variant.hpp} onSave={() => fetchData()} />
                                                                    </td>
                                                                </tr>
                                                            ));
                                                        }

                                                        return (
                                                            <tr key={product.id} className="group hover:bg-blue-50/30 dark:hover:bg-blue-500/5 transition-colors">
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
                                                                </td>
                                                                <td className="px-6 py-4 align-middle">
                                                                    <span className="text-xs text-gray-400 dark:text-slate-500 italic">Single Product</span>
                                                                </td>
                                                                <td className="px-6 py-4 text-center"><StockBadge stock={product.stock} /></td>
                                                                <td className="px-6 py-4 font-medium text-gray-700 dark:text-slate-300">{formatRp(product.price)}</td>
                                                                <td className="px-6 py-4">
                                                                    <HppEditor type="item" id={product.id} hpp={product.hpp} onSave={() => fetchData()} />
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>

                                        {/* Mobile Cards */}
                                        <div className="md:hidden p-4 space-y-4 bg-gray-50/50 dark:bg-slate-900/30">
                                            {store.products.map((product) => (
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

                                                            {(!product.variants || product.variants.length === 0) ? (
                                                                <div className="mt-3 flex items-center justify-between border-t border-dashed border-gray-100 dark:border-slate-700 pt-2">
                                                                    <div>
                                                                        <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wider">Harga Jual</p>
                                                                        <p className="text-sm font-bold text-[#304674] dark:text-blue-400">{formatRp(product.price)}</p>
                                                                    </div>
                                                                    <div className="text-right">
                                                                        <p className="text-[10px] text-gray-400 dark:text-slate-500 uppercase tracking-wider">HPP</p>
                                                                        <HppEditorMobile type="item" id={product.id} hpp={product.hpp} onSave={() => fetchData()} />
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <button
                                                                    onClick={() => toggleExpand(product.id)}
                                                                    className="mt-2 text-xs font-medium text-[#304674] dark:text-blue-400 flex items-center gap-1"
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
                                            ))}
                                        </div>
                                    </>
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
