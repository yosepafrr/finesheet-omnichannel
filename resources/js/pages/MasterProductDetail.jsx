import axios from "axios";
import React, { useCallback, useEffect, useState } from "react";
import AppLayout from "../../views/components/layouts/AppLayout";

function formatRp(value) {
    return `Rp ${Number(value || 0).toLocaleString("id-ID")}`;
}

function statusStyle(status) {
    if (status === "synced") return "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300";
    if (status === "pending") return "bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300";
    if (status === "failed") return "bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300";
    return "bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300";
}

function StoreStatus({ store }) {
    return (
        <div className="flex items-center justify-between gap-3 py-2.5">
            <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{store.name || "Toko"}</p>
                <p className="text-xs text-slate-400">{store.platform} / {store.listings_count} listing</p>
            </div>
            <span className={`shrink-0 rounded-md px-2 py-1 text-xs font-semibold ${statusStyle(store.status)}`}>
                {store.status === "synced" ? "Tersinkron" : store.status === "pending" ? "Mengirim" : store.status === "failed" ? "Gagal" : "Belum dikirim"}
            </span>
        </div>
    );
}

export default function MasterProductDetail({ routeParams }) {
    const { id } = routeParams;
    const [product, setProduct] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [syncingId, setSyncingId] = useState(null);

    const fetchProduct = useCallback(async (silent = false) => {
        if (!silent) setLoading(true);
        try {
            const response = await axios.get(`/api/master-products/${id}`);
            setProduct(response.data);
            setError("");
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Produk master tidak dapat dimuat.");
        } finally {
            if (!silent) setLoading(false);
        }
    }, [id]);

    useEffect(() => { fetchProduct(); }, [fetchProduct]);
    useEffect(() => {
        const interval = window.setInterval(() => fetchProduct(true), 10000);
        return () => window.clearInterval(interval);
    }, [fetchProduct]);

    const syncStock = async (variant) => {
        setSyncingId(variant.id);
        try {
            await axios.post(`/api/master-products/${id}/variants/${variant.id}/push`);
            await fetchProduct(true);
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Sinkronisasi stok gagal dijadwalkan.");
        } finally {
            setSyncingId(null);
        }
    };

    if (loading) {
        return <AppLayout><div className="min-h-screen bg-slate-50 p-4 dark:bg-slate-900"><div className="mx-auto max-w-6xl animate-pulse space-y-4"><div className="h-8 w-52 rounded bg-slate-200 dark:bg-slate-700" /><div className="h-44 rounded-lg bg-white dark:bg-slate-800" /></div></div></AppLayout>;
    }

    if (!product) {
        return <AppLayout><div className="min-h-screen bg-slate-50 p-4 dark:bg-slate-900"><div className="mx-auto max-w-4xl py-20 text-center"><h1 className="text-xl font-bold text-slate-900 dark:text-white">Produk master tidak ditemukan</h1><a href="#/products" className="mt-4 inline-block text-sm font-semibold text-[#304674] dark:text-blue-400">Kembali ke Product Management</a></div></div></AppLayout>;
    }

    return (
        <AppLayout>
            <div className="min-h-screen bg-slate-50 px-2 py-3 dark:bg-slate-900 sm:px-4">
                <div className="mx-auto max-w-7xl space-y-5 pb-10">
                    <a href="#/products" className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-[#304674] dark:text-slate-400 dark:hover:text-blue-400">
                        <span className="material-symbols-rounded text-lg">arrow_back</span>
                        Master Produk
                    </a>

                    {error && <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{error}</div>}

                    <header className="flex flex-col gap-5 border-b border-slate-200 pb-5 sm:flex-row sm:items-center dark:border-slate-700">
                        {product.image ? <img src={product.image} alt="" className="h-24 w-24 rounded-lg border border-slate-200 object-cover dark:border-slate-700" /> : <div className="flex h-24 w-24 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-3xl font-bold text-slate-400 dark:border-slate-700 dark:bg-slate-800">{product.name?.charAt(0)?.toUpperCase()}</div>}
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2"><h1 className="text-2xl font-bold text-slate-900 dark:text-white">{product.name}</h1><span className="rounded-md bg-slate-200 px-2 py-1 text-xs font-semibold capitalize text-slate-600 dark:bg-slate-700 dark:text-slate-300">{product.status}</span></div>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{[product.brand, product.category].filter(Boolean).join(" / ") || "Tanpa brand dan kategori"}</p>
                            {product.description && <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">{product.description}</p>}
                        </div>
                    </header>

                    <section>
                        <div className="mb-3 flex items-end justify-between"><div><h2 className="text-base font-bold text-slate-900 dark:text-white">SKU dan sinkronisasi</h2><p className="text-sm text-slate-500 dark:text-slate-400">{product.variants.length} SKU master</p></div></div>
                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            <div className="hidden overflow-x-auto lg:block">
                                <table className="w-full min-w-[980px] table-fixed text-left">
                                    <thead className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500 dark:border-slate-700 dark:bg-slate-800"><tr><th className="w-[21%] px-5 py-3">SKU</th><th className="w-[10%] px-4 py-3 text-right">Stok</th><th className="w-[14%] px-4 py-3 text-right">HPP</th><th className="w-[16%] px-4 py-3">Barcode</th><th className="w-[31%] px-4 py-3">Toko terhubung</th><th className="w-[8%] px-4 py-3" /></tr></thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                        {product.variants.map((variant) => <tr key={variant.id}><td className="px-5 py-4"><p className="font-mono text-sm font-bold text-slate-900 dark:text-white">{variant.sku}</p><p className="text-xs text-slate-400">{variant.variant_name || "Varian utama"}</p></td><td className="px-4 py-4 text-right font-bold text-slate-900 dark:text-white">{variant.stock.toLocaleString("id-ID")}</td><td className="px-4 py-4 text-right font-semibold text-slate-700 dark:text-slate-200">{formatRp(variant.hpp)}</td><td className="px-4 py-4 text-sm text-slate-600 dark:text-slate-300">{variant.barcode || "-"}</td><td className="px-4 py-3"><div className="divide-y divide-slate-100 dark:divide-slate-700">{variant.stores.length ? variant.stores.map((store) => <StoreStatus key={store.id} store={store} />) : <span className="text-sm text-slate-400">Belum ada SKU marketplace yang cocok</span>}</div></td><td className="px-4 py-4"><button type="button" disabled={syncingId === variant.id || variant.stores_count === 0} onClick={() => syncStock(variant)} className="flex h-9 w-9 items-center justify-center rounded-lg text-[#304674] hover:bg-blue-50 disabled:opacity-30 dark:text-blue-400 dark:hover:bg-blue-500/10" title="Sinkronkan stok"><span className={`material-symbols-rounded ${syncingId === variant.id ? "animate-spin" : ""}`}>sync</span></button></td></tr>)}
                                    </tbody>
                                </table>
                            </div>

                            <div className="divide-y divide-slate-200 lg:hidden dark:divide-slate-700">
                                {product.variants.map((variant) => (
                                    <article key={variant.id} className="p-4">
                                        <div className="flex items-start justify-between gap-3"><div><p className="font-mono text-sm font-bold text-slate-900 dark:text-white">{variant.sku}</p><p className="mt-0.5 text-xs text-slate-400">{variant.variant_name || "Varian utama"}</p></div><button type="button" disabled={syncingId === variant.id || variant.stores_count === 0} onClick={() => syncStock(variant)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-[#304674] disabled:opacity-30 dark:border-slate-600 dark:text-blue-400"><span className={`material-symbols-rounded ${syncingId === variant.id ? "animate-spin" : ""}`}>sync</span></button></div>
                                        <div className="mt-4 grid grid-cols-3 gap-3 border-y border-slate-100 py-3 dark:border-slate-700"><div><p className="text-xs text-slate-400">Stok</p><p className="font-bold text-slate-900 dark:text-white">{variant.stock.toLocaleString("id-ID")}</p></div><div><p className="text-xs text-slate-400">HPP</p><p className="font-semibold text-slate-800 dark:text-slate-100">{formatRp(variant.hpp)}</p></div><div><p className="text-xs text-slate-400">Barcode</p><p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{variant.barcode || "-"}</p></div></div>
                                        <div className="mt-2 divide-y divide-slate-100 dark:divide-slate-700">{variant.stores.length ? variant.stores.map((store) => <StoreStatus key={store.id} store={store} />) : <p className="py-3 text-sm text-slate-400">Belum ada SKU marketplace yang cocok</p>}</div>
                                    </article>
                                ))}
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
