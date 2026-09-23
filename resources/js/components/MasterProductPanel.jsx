import axios from "axios";
import { AnimatePresence, motion } from "framer-motion";
import React, { useCallback, useEffect, useRef, useState } from "react";

const EMPTY_FORM = {
    name: "",
    image: "",
    brand: "",
    category: "",
    description: "",
    status: "active",
    sku: "",
    variant_name: "",
    barcode: "",
    stock: 0,
    hpp: 0,
    is_active: true,
};

function formatRp(value) {
    return `Rp ${Number(value || 0).toLocaleString("id-ID")}`;
}

function errorMessage(error) {
    const errors = error.response?.data?.errors;
    return (errors && Object.values(errors).flat()[0])
        || error.response?.data?.message
        || "Terjadi kesalahan. Silakan coba lagi.";
}

function ProductImage({ row, size = "md" }) {
    const dimensions = size === "lg" ? "h-14 w-14" : "h-11 w-11";

    if (row.image) {
        return <img src={row.image} alt="" className={`${dimensions} shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700`} />;
    }

    return (
        <div className={`${dimensions} flex shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 font-bold text-slate-500 dark:border-slate-700 dark:bg-slate-700 dark:text-slate-300`}>
            {row.name?.charAt(0)?.toUpperCase() || "P"}
        </div>
    );
}

function SyncStatus({ row, compact = false }) {
    const statuses = row.stores?.map((store) => store.status) || [];
    let label = "Belum terhubung";
    let style = "bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300";
    let icon = "link_off";

    if (statuses.includes("failed")) {
        label = "Ada yang gagal";
        style = "bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300";
        icon = "error";
    } else if (statuses.includes("pending")) {
        label = "Sedang dikirim";
        style = "bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300";
        icon = "sync";
    } else if (row.stores_count > 0) {
        label = `${row.stores_count} toko`;
        style = "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300";
        icon = "check_circle";
    }

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ${style}`}>
            <span className={`material-symbols-rounded text-[15px] ${statuses.includes("pending") ? "animate-spin" : ""}`}>{icon}</span>
            {!compact && label}
            {compact && row.stores_count}
        </span>
    );
}

function MatchStatus({ row }) {
    if (row.same_sku_across_stores) {
        return (
            <span className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                <span className="material-symbols-rounded text-[15px]">join_inner</span>
                Sama di {row.stores_count} toko
            </span>
        );
    }

    return (
        <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
            {row.stores_count === 1 ? "1 toko cocok" : "Belum ditemukan"}
        </span>
    );
}

function ActionMenu({ row, onEdit, onDelete, onPush }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const close = (event) => {
            if (ref.current && !ref.current.contains(event.target)) setOpen(false);
        };
        document.addEventListener("mousedown", close);
        return () => document.removeEventListener("mousedown", close);
    }, []);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-700 dark:hover:text-white"
                aria-label="Pengaturan SKU master"
            >
                <span className="material-symbols-rounded">more_vert</span>
            </button>
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0, y: 4, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 4, scale: 0.98 }}
                        className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800"
                    >
                        <button type="button" onClick={() => { setOpen(false); onEdit(row); }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">
                            <span className="material-symbols-rounded text-lg">edit</span>
                            Edit data
                        </button>
                        <button type="button" onClick={() => { setOpen(false); onPush(row); }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">
                            <span className="material-symbols-rounded text-lg">sync</span>
                            Sinkronkan stok
                        </button>
                        <button type="button" onClick={() => { setOpen(false); onDelete(row); }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                            <span className="material-symbols-rounded text-lg">delete</span>
                            Hapus SKU master
                        </button>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

function MasterSkuModal({ row, preset, onClose, onSaved }) {
    const [form, setForm] = useState(() => row ? {
        name: row.name || "",
        image: row.image || "",
        brand: row.brand || "",
        category: row.category || "",
        description: row.description || "",
        status: row.product_status || "active",
        sku: row.sku || "",
        variant_name: row.variant_name || "",
        barcode: row.barcode || "",
        stock: row.stock ?? 0,
        hpp: row.hpp ?? 0,
        is_active: row.is_active ?? true,
    } : { ...EMPTY_FORM, ...preset });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    const update = (field, value) => setForm((current) => ({ ...current, [field]: value }));

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError("");

        try {
            const normalized = {
                ...form,
                image: form.image || null,
                brand: form.brand || null,
                category: form.category || null,
                description: form.description || null,
                barcode: form.barcode || null,
                variant_name: form.variant_name || null,
                stock: Number(form.stock || 0),
                hpp: Number(form.hpp || 0),
            };

            if (row) {
                await axios.put(`/api/master-products/${row.master_product_id}/variants/${row.id}`, normalized);
            } else {
                await axios.post("/api/master-products", {
                    name: normalized.name,
                    image: normalized.image,
                    brand: normalized.brand,
                    category: normalized.category,
                    description: normalized.description,
                    status: normalized.status,
                    variants: [{
                        sku: normalized.sku,
                        variant_name: normalized.variant_name,
                        barcode: normalized.barcode,
                        stock: normalized.stock,
                        hpp: normalized.hpp,
                        is_active: normalized.is_active,
                    }],
                });
            }

            onSaved();
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <motion.form
                initial={{ opacity: 0, y: 14, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                onSubmit={submit}
                className="flex max-h-[92dvh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800"
            >
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900 dark:text-white">{row ? "Edit SKU Master" : "Tambah SKU Master"}</h2>
                        <p className="text-sm text-slate-500 dark:text-slate-400">SKU yang sama akan terhubung ke listing marketplace secara otomatis.</p>
                    </div>
                    <button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Tutup">
                        <span className="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div className="overflow-y-auto px-5 py-5">
                    {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{error}</div>}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label className="sm:col-span-2">
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Nama master produk</span>
                            <input required value={form.name} onChange={(event) => update("name", event.target.value)} className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">SKU</span>
                            <input required value={form.sku} onChange={(event) => update("sku", event.target.value)} className="w-full rounded-lg border-slate-300 font-mono text-sm uppercase focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Nama varian</span>
                            <input value={form.variant_name} onChange={(event) => update("variant_name", event.target.value)} placeholder="Opsional" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Stok tersedia</span>
                            <input type="number" min="0" required value={form.stock} onChange={(event) => update("stock", event.target.value)} className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">HPP</span>
                            <input type="number" min="0" required value={form.hpp} onChange={(event) => update("hpp", event.target.value)} className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Barcode</span>
                            <input value={form.barcode} onChange={(event) => update("barcode", event.target.value)} placeholder="Opsional" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">URL gambar</span>
                            <input type="url" value={form.image} onChange={(event) => update("image", event.target.value)} placeholder="Opsional" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Brand</span>
                            <input value={form.brand} onChange={(event) => update("brand", event.target.value)} placeholder="Opsional" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Kategori</span>
                            <input value={form.category} onChange={(event) => update("category", event.target.value)} placeholder="Opsional" className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white" />
                        </label>
                    </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-800">
                    <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700">Batal</button>
                    <button type="submit" disabled={saving} className="inline-flex min-w-28 items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2 text-sm font-semibold text-white hover:bg-[#243558] disabled:opacity-60 dark:bg-blue-600">
                        <span className={`material-symbols-rounded text-lg ${saving ? "animate-spin" : ""}`}>{saving ? "progress_activity" : "save"}</span>
                        {saving ? "Menyimpan" : "Simpan"}
                    </button>
                </div>
            </motion.form>
        </div>
    );
}

function LoadingRows() {
    return (
        <div className="animate-pulse divide-y divide-slate-100 dark:divide-slate-700">
            {[1, 2, 3, 4].map((item) => <div key={item} className="flex items-center gap-4 px-5 py-5"><div className="h-11 w-11 rounded-lg bg-slate-200 dark:bg-slate-700" /><div className="h-4 flex-1 rounded bg-slate-100 dark:bg-slate-700" /></div>)}
        </div>
    );
}

export default function MasterProductPanel({ search = "" }) {
    const [rows, setRows] = useState([]);
    const [detected, setDetected] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [modal, setModal] = useState({ open: false, row: null, preset: null });
    const [busyId, setBusyId] = useState(null);

    const fetchRows = useCallback(async (silent = false) => {
        if (!silent) setLoading(true);
        try {
            const response = await axios.get("/api/master-products", { params: { search, page, per_page: 20 } });
            setRows(response.data.data || []);
            setMeta(response.data.meta || {});
            setError("");
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            if (!silent) setLoading(false);
        }
    }, [page, search]);

    const fetchDetected = useCallback(async () => {
        try {
            const response = await axios.get("/api/sku-sync/detect");
            setDetected(response.data || []);
        } catch {
            setDetected([]);
        }
    }, []);

    useEffect(() => setPage(1), [search]);
    useEffect(() => {
        const timeout = setTimeout(() => fetchRows(), 250);
        return () => clearTimeout(timeout);
    }, [fetchRows]);
    useEffect(() => { fetchDetected(); }, [fetchDetected]);
    useEffect(() => {
        const interval = window.setInterval(() => fetchRows(true), 10000);
        return () => window.clearInterval(interval);
    }, [fetchRows]);

    const refresh = async () => {
        setModal({ open: false, row: null, preset: null });
        await Promise.all([fetchRows(), fetchDetected()]);
    };

    const remove = async (row) => {
        if (!window.confirm(`Hapus SKU master ${row.sku}? Produk marketplace tidak akan dihapus.`)) return;
        setBusyId(row.id);
        try {
            await axios.delete(`/api/master-products/${row.master_product_id}/variants/${row.id}`);
            await refresh();
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setBusyId(null);
        }
    };

    const push = async (row) => {
        setBusyId(row.id);
        try {
            const response = await axios.post(`/api/master-products/${row.master_product_id}/variants/${row.id}/push`);
            setError(response.data.queued_count > 0 ? "" : response.data.message);
            await fetchRows(true);
        } catch (requestError) {
            setError(errorMessage(requestError));
        } finally {
            setBusyId(null);
        }
    };

    const createFromDetection = (item) => {
        const first = item.items?.[0] || {};
        setModal({
            open: true,
            row: null,
            preset: {
                name: first.product_name || "",
                sku: item.sku,
                variant_name: first.variant_name || "",
                stock: first.stock || 0,
            },
        });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 className="text-base font-bold text-slate-900 dark:text-white">Master Produk</h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">{meta.total || 0} SKU dikelola</p>
                </div>
                <button type="button" onClick={() => setModal({ open: true, row: null, preset: null })} className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700">
                    <span className="material-symbols-rounded text-lg">add</span>
                    Tambah Master Produk
                </button>
            </div>

            {detected.length > 0 && (
                <div className="border-y border-amber-200 bg-amber-50/70 px-4 py-3 dark:border-amber-500/20 dark:bg-amber-500/5">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex items-start gap-3">
                            <span className="material-symbols-rounded mt-0.5 text-amber-600">difference</span>
                            <div>
                                <p className="text-sm font-bold text-amber-900 dark:text-amber-200">{detected.length} SKU sama ditemukan di beberapa toko</p>
                                <p className="text-xs text-amber-700 dark:text-amber-300">Buat master produk untuk mulai mengelola stoknya.</p>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {detected.slice(0, 3).map((item) => (
                                <button key={item.sku} type="button" onClick={() => createFromDetection(item)} className="rounded-lg border border-amber-300 bg-white px-3 py-1.5 font-mono text-xs font-semibold text-amber-800 hover:border-amber-400 dark:border-amber-500/30 dark:bg-slate-800 dark:text-amber-200">
                                    {item.sku} ({item.stores_count})
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {error && <div className="flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300"><span>{error}</span><button type="button" onClick={() => setError("")} className="material-symbols-rounded">close</button></div>}

            <div className="overflow-visible rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                {loading ? <LoadingRows /> : rows.length === 0 ? (
                    <div className="px-6 py-16 text-center">
                        <span className="material-symbols-rounded text-4xl text-slate-300">inventory_2</span>
                        <h3 className="mt-3 font-bold text-slate-900 dark:text-white">{search ? "SKU tidak ditemukan" : "Belum ada master produk"}</h3>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{search ? "Periksa kembali kata pencarian." : "Tambahkan produk pertama atau gunakan rekomendasi SKU yang terdeteksi."}</p>
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto md:block">
                            <table className="w-full min-w-[1040px] table-fixed text-left">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                    <tr><th className="w-[27%] px-5 py-3">Produk</th><th className="w-[15%] px-4 py-3">SKU</th><th className="w-[9%] px-4 py-3 text-right">Stok</th><th className="w-[13%] px-4 py-3 text-right">HPP</th><th className="w-[15%] px-4 py-3">Sinkronisasi</th><th className="w-[16%] px-4 py-3">Deteksi SKU</th><th className="w-[5%] px-3 py-3" /></tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                    {rows.map((row) => (
                                        <tr key={row.id} className="hover:bg-slate-50/70 dark:hover:bg-slate-700/30">
                                            <td className="px-5 py-4"><a href={`#/products/master/${row.master_product_id}`} className="flex min-w-0 items-center gap-3"><ProductImage row={row} /><div className="min-w-0"><p className="truncate font-bold text-slate-900 hover:text-[#304674] dark:text-white">{row.name}</p><p className="truncate text-xs text-slate-500">{row.variant_name || row.category || "Produk utama"}</p></div></a></td>
                                            <td className="px-4 py-4"><span className="block truncate font-mono text-sm font-semibold text-slate-800 dark:text-slate-100">{row.sku}</span>{row.barcode && <span className="block truncate text-xs text-slate-400">{row.barcode}</span>}</td>
                                            <td className="px-4 py-4 text-right text-sm font-bold text-slate-900 dark:text-white">{row.stock.toLocaleString("id-ID")}</td>
                                            <td className="px-4 py-4 text-right"><button type="button" onClick={() => setModal({ open: true, row, preset: null })} className="inline-flex items-center gap-1 text-sm font-semibold text-slate-800 hover:text-[#304674] dark:text-slate-100"><span>{formatRp(row.hpp)}</span><span className="material-symbols-rounded text-[15px] text-slate-400">edit</span></button></td>
                                            <td className="px-4 py-4"><SyncStatus row={row} /></td>
                                            <td className="px-4 py-4"><MatchStatus row={row} /></td>
                                            <td className="px-3 py-4"><div className={busyId === row.id ? "pointer-events-none opacity-50" : ""}><ActionMenu row={row} onEdit={(item) => setModal({ open: true, row: item, preset: null })} onDelete={remove} onPush={push} /></div></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="space-y-3 bg-slate-100/80 p-3 md:hidden dark:bg-slate-900/50">
                            {rows.map((row) => (
                                <article
                                    key={row.id}
                                    className={`relative rounded-lg border border-slate-300 bg-white p-4 shadow-[0_1px_3px_rgba(15,23,42,0.10)] dark:border-slate-700 dark:bg-slate-800 ${busyId === row.id ? "pointer-events-none opacity-60" : ""}`}
                                >
                                    <div className="flex items-start gap-4">
                                        <a href={`#/products/master/${row.master_product_id}`} className="shrink-0">
                                            <ProductImage row={row} size="lg" />
                                        </a>
                                        <div className="min-w-0 flex-1">
                                            <a href={`#/products/master/${row.master_product_id}`} className="line-clamp-2 text-sm font-bold leading-tight text-slate-900 dark:text-white">
                                                {row.name}
                                            </a>
                                            <p className="mt-1 truncate font-mono text-xs font-semibold text-slate-500 dark:text-slate-400">
                                                {row.sku}
                                            </p>
                                            <p className="mt-0.5 truncate text-xs text-slate-400 dark:text-slate-500">
                                                {row.variant_name || row.category || "Produk utama"}
                                            </p>
                                        </div>
                                        <ActionMenu row={row} onEdit={(item) => setModal({ open: true, row: item, preset: null })} onDelete={remove} onPush={push} />
                                    </div>

                                    <div className="mt-4 grid grid-cols-2 gap-3 border-t border-dashed border-slate-200 pt-3 dark:border-slate-700">
                                        <div className="min-w-0">
                                            <p className="text-[10px] font-semibold uppercase text-slate-400 dark:text-slate-500">Stok tersedia</p>
                                            <p className="mt-1 text-sm font-bold text-slate-900 dark:text-white">{row.stock.toLocaleString("id-ID")}</p>
                                        </div>
                                        <div className="min-w-0 text-right">
                                            <p className="text-[10px] font-semibold uppercase text-slate-400 dark:text-slate-500">HPP</p>
                                            <button type="button" onClick={() => setModal({ open: true, row, preset: null })} className="mt-1 inline-flex max-w-full items-center gap-1 text-sm font-bold text-slate-900 dark:text-white">
                                                <span className="truncate">{formatRp(row.hpp)}</span>
                                                <span className="material-symbols-rounded shrink-0 text-[15px] text-slate-400">edit</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-700/70">
                                        <SyncStatus row={row} />
                                        <MatchStatus row={row} />
                                    </div>
                                </article>
                            ))}
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 dark:border-slate-700"><p className="text-sm text-slate-500">{meta.from || 0}-{meta.to || 0} dari {meta.total || 0}</p><div className="flex items-center gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-30 dark:border-slate-600"><span className="material-symbols-rounded">chevron_left</span></button><span className="min-w-14 text-center text-sm font-semibold">{meta.current_page || 1} / {meta.last_page || 1}</span><button type="button" disabled={page >= (meta.last_page || 1)} onClick={() => setPage((current) => current + 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-30 dark:border-slate-600"><span className="material-symbols-rounded">chevron_right</span></button></div></div>
                    </>
                )}
            </div>

            <AnimatePresence>{modal.open && <MasterSkuModal row={modal.row} preset={modal.preset} onClose={() => setModal({ open: false, row: null, preset: null })} onSaved={refresh} />}</AnimatePresence>
        </div>
    );
}
