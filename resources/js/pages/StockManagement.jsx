import axios from "axios";
import { AnimatePresence, motion } from "framer-motion";
import React, { useCallback, useEffect, useState } from "react";
import AppLayout from "../../views/components/layouts/AppLayout";
import FinesheetSelect from "../components/ui/FinesheetSelect";
import { useAccess } from "../contexts/AccessContext";

const ACTIONS = {
    stock_opname: {
        title: "Stock Opname",
        description: "Tetapkan jumlah stok fisik aktual setelah penghitungan.",
        label: "On Hand aktual",
        icon: "fact_check",
        tone: "blue",
    },
    stock_in: {
        title: "Tambah Stock",
        description: "Catat barang yang masuk ke stok fisik.",
        label: "Jumlah masuk",
        icon: "add_box",
        tone: "emerald",
    },
    stock_out: {
        title: "Kurangi Stock",
        description: "Catat pengurangan stok manual di luar pesanan marketplace.",
        label: "Jumlah keluar",
        icon: "indeterminate_check_box",
        tone: "rose",
    },
};

const number = (value) => Number(value || 0);
const formatNumber = (value) => number(value).toLocaleString("id-ID");

function ProductImage({ variant }) {
    if (variant.image) {
        return <img src={variant.image} alt="" className="h-14 w-14 rounded-lg border border-slate-200 object-cover dark:border-slate-700" />;
    }

    return (
        <div className="flex h-14 w-14 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-lg font-bold text-slate-500 dark:border-slate-700 dark:bg-slate-700 dark:text-slate-300">
            {variant.name?.charAt(0)?.toUpperCase() || "P"}
        </div>
    );
}

function AdjustmentModal({ action, variant, onClose, onSaved }) {
    const config = ACTIONS[action];
    const [quantity, setQuantity] = useState(action === "stock_opname" ? number(variant.on_hand) : 1);
    const [notes, setNotes] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        const closeOnEscape = (event) => event.key === "Escape" && onClose();
        document.addEventListener("keydown", closeOnEscape);
        return () => {
            document.body.style.overflow = previous;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [onClose]);

    const currentOnHand = number(variant.on_hand);
    const parsedQuantity = Math.max(0, Number.parseInt(quantity, 10) || 0);
    const projectedOnHand = action === "stock_opname"
        ? parsedQuantity
        : currentOnHand + (action === "stock_out" ? -parsedQuantity : parsedQuantity);
    const projectedAvailable = Math.max(0, projectedOnHand - number(variant.on_order) - number(variant.reserved_stock));
    const invalid = action === "stock_out" && projectedOnHand < 0;

    const submit = async (event) => {
        event.preventDefault();
        if (invalid) return;
        setSaving(true);
        setError("");
        try {
            const response = await axios.post(
                `/api/master-products/${variant.master_product_id}/variants/${variant.id}/inventory/adjustments`,
                { action, quantity: parsedQuantity, notes: notes.trim() || null },
            );
            onSaved(response.data);
        } catch (requestError) {
            setError(requestError.response?.data?.message
                || Object.values(requestError.response?.data?.errors || {})?.[0]?.[0]
                || "Perubahan stock tidak dapat disimpan.");
        } finally {
            setSaving(false);
        }
    };

    const changeQuantity = (delta) => setQuantity(Math.max(action === "stock_opname" ? 0 : 1, parsedQuantity + delta));
    const stockOpname = action === "stock_opname";

    return (
        <motion.div className="fixed inset-0 z-[10000] flex items-end justify-center bg-slate-950/45 p-0 backdrop-blur-sm sm:items-center sm:p-4" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <motion.form onSubmit={submit} className="w-full max-w-md overflow-hidden rounded-t-xl bg-white shadow-2xl sm:rounded-xl dark:bg-slate-800" initial={{ opacity: 0, y: 24, scale: 0.98 }} animate={{ opacity: 1, y: 0, scale: 1 }} exit={{ opacity: 0, y: 16, scale: 0.98 }}>
                <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300"><span className="material-symbols-rounded">{config.icon}</span></span>
                        <div className="min-w-0"><h2 className="font-bold text-slate-900 dark:text-white">{stockOpname ? "Atur Persediaan" : config.title}</h2><p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{variant.name}</p></div>
                    </div>
                    <button type="button" onClick={onClose} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Tutup"><span className="material-symbols-rounded">close</span></button>
                </div>

                <div className="space-y-4 px-5 py-5">
                    {error && <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">{error}</div>}
                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900/50">
                        <p className="text-[11px] font-semibold text-slate-500 dark:text-slate-400">{stockOpname ? "Stok fisik (On Hand)" : config.label}</p>
                        <div className="mt-2 grid grid-cols-[42px_1fr_42px] gap-2">
                            <button type="button" onClick={() => changeQuantity(-1)} className="flex h-11 items-center justify-center rounded-lg border border-slate-300 bg-white text-xl text-[#304674] hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800">−</button>
                            <input type="number" min={stockOpname ? 0 : 1} step="1" value={quantity} onChange={(event) => setQuantity(event.target.value)} autoFocus className="h-11 min-w-0 rounded-lg border-2 border-[#304674] bg-white px-3 text-center text-base font-extrabold text-slate-900 outline-none dark:bg-slate-800 dark:text-white" />
                            <button type="button" onClick={() => changeQuantity(1)} className="flex h-11 items-center justify-center rounded-lg border border-slate-300 bg-white text-xl text-[#304674] hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800">+</button>
                        </div>
                        <p className="mt-2 text-center text-[11px] text-slate-500">{stockOpname ? `${formatNumber(parsedQuantity)} unit tersimpan secara fisik` : config.description}</p>
                    </section>

                    {stockOpname && <div className="grid grid-cols-2 gap-3"><div className="rounded-lg border border-slate-200 px-3 py-3 dark:border-slate-700"><p className="text-[10px] font-bold uppercase text-slate-400">On Order</p><p className="mt-1 text-lg font-extrabold tabular-nums text-slate-900 dark:text-white">{formatNumber(variant.on_order)}</p><p className="mt-1 text-[10px] text-slate-400">Dialokasikan ke pesanan aktif</p></div><div className="rounded-lg border border-slate-200 px-3 py-3 dark:border-slate-700"><p className="text-[10px] font-bold uppercase text-slate-400">Reserved</p><p className="mt-1 text-lg font-extrabold tabular-nums text-slate-900 dark:text-white">{formatNumber(variant.reserved_stock)}</p><p className="mt-1 text-[10px] text-slate-400">Dicadangkan</p></div></div>}

                    <div className={`rounded-lg border px-4 py-3 ${invalid ? "border-red-200 bg-red-50" : "border-emerald-200 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10"}`}><div className="flex items-end justify-between gap-4"><div><p className={`text-[10px] font-bold uppercase ${invalid ? "text-red-600" : "text-emerald-700 dark:text-emerald-300"}`}>Available</p><p className="mt-1 text-[10px] text-slate-500">On Hand - On Order - Reserved</p></div><p className={`text-2xl font-extrabold tabular-nums ${invalid ? "text-red-600" : "text-emerald-700 dark:text-emerald-300"}`}>{formatNumber(projectedAvailable)}</p></div></div>
                    {invalid && <p className="text-xs font-semibold text-red-600">Jumlah keluar melebihi On Hand saat ini.</p>}
                    <label className="block"><span className="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Catatan penyesuaian <span className="font-normal text-slate-400">(opsional)</span></span><input maxLength="500" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder={stockOpname ? "Contoh: Hasil stock opname gudang" : "Contoh: Penyesuaian stok gudang"} className="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-[#304674] focus:ring-2 focus:ring-[#304674]/15 dark:border-slate-600 dark:bg-slate-900 dark:text-white" /></label>
                </div>

                <div className="flex gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-900/40"><button type="button" onClick={onClose} className="h-11 flex-1 rounded-lg border border-slate-300 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">Batal</button><button type="submit" disabled={saving || invalid || (!stockOpname && parsedQuantity < 1)} className="h-11 flex-1 rounded-lg bg-[#304674] text-sm font-bold text-white hover:bg-[#263b67] disabled:cursor-not-allowed disabled:opacity-50">{saving ? "Menyimpan..." : "Simpan"}</button></div>
            </motion.form>
        </motion.div>
    );
}

function SummaryCard({ icon, label, value, tone = "slate" }) {
    const tones = {
        blue: "bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300",
        emerald: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300",
        amber: "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300",
        rose: "bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300",
        slate: "bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300",
    };

    return (
        <div className="flex min-w-0 items-center justify-between border-b border-slate-200 bg-white px-4 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 dark:border-slate-700 dark:bg-slate-800">
            <div className="min-w-0">
                <p className="truncate text-[10px] font-bold uppercase text-slate-400">{label}</p>
                <p className="mt-1 text-xl font-extrabold tabular-nums text-slate-900 dark:text-white">{formatNumber(value)}</p>
            </div>
            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${tones[tone]}`}>
                <span className="material-symbols-rounded">{icon}</span>
            </span>
        </div>
    );
}

function StockStatus({ available }) {
    if (available <= 0) {
        return <span className="rounded-md bg-rose-50 px-2 py-1 text-[10px] font-bold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Out of stock</span>;
    }
    if (available <= 10) {
        return <span className="rounded-md bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Low stock</span>;
    }
    return <span className="rounded-md bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Available</span>;
}

function StockActions({ row, canManage, onAction, compact = false }) {
    if (row.is_bundle) {
        return <span className="text-[10px] font-semibold text-slate-400">Dihitung dari komponen</span>;
    }

    if (!canManage) {
        return <span className="text-[10px] font-semibold text-slate-400">Hanya lihat</span>;
    }

    const actions = [
        {
            key: "stock_opname",
            label: "Stock Opname",
            icon: "fact_check",
            className: "border-[#304674]/25 bg-[#304674]/[0.07] text-[#304674] hover:border-[#304674]/45 hover:bg-[#304674]/[0.12] dark:border-blue-400/30 dark:bg-blue-400/10 dark:text-blue-300 dark:hover:bg-blue-400/15",
        },
        {
            key: "stock_in",
            label: "Tambah",
            icon: "add_box",
            className: "border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-300 dark:hover:bg-emerald-400/15",
        },
        {
            key: "stock_out",
            label: "Kurangi",
            icon: "indeterminate_check_box",
            className: "border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100 dark:border-rose-400/30 dark:bg-rose-400/10 dark:text-rose-300 dark:hover:bg-rose-400/15",
        },
    ];

    return (
        <div className={`flex items-center ${compact ? "gap-2" : "justify-end gap-1.5"}`}>
            {actions.map((action) => (
                <button
                    key={action.key}
                    type="button"
                    onClick={() => onAction(row, action.key)}
                    title={action.label}
                    aria-label={`${action.label} ${row.sku}`}
                    className={`inline-flex h-9 items-center justify-center gap-1.5 rounded-md border px-3 text-[11px] font-bold shadow-sm transition duration-150 hover:-translate-y-0.5 hover:shadow-md active:translate-y-0 active:shadow-sm focus:outline-none focus:ring-2 focus:ring-[#304674]/20 ${action.className} ${compact ? "flex-1" : ""}`}
                >
                    <span className="material-symbols-rounded text-[18px]">{action.icon}</span>
                    <span className={compact ? "" : "hidden 2xl:inline"}>{action.label}</span>
                </button>
            ))}
        </div>
    );
}

export default function StockManagement({ routeParams = {} }) {
    const access = useAccess();
    const [rows, setRows] = useState([]);
    const [summary, setSummary] = useState({});
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 30, total: 0 });
    const [search, setSearch] = useState("");
    const [debouncedSearch, setDebouncedSearch] = useState("");
    const [status, setStatus] = useState("all");
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(30);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [adjustment, setAdjustment] = useState(null);
    const [focusedVariantId, setFocusedVariantId] = useState(null);
    const canManage = access.can("products.stock.manage");

    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300);
        return () => window.clearTimeout(timer);
    }, [search]);

    useEffect(() => setPage(1), [debouncedSearch, status, perPage]);

    const loadInventory = useCallback(async () => {
        setLoading(true);
        setError("");
        try {
            const response = await axios.get("/api/master-products/inventory", {
                params: { search: debouncedSearch || undefined, status, page, per_page: perPage },
            });
            setRows(response.data.data || []);
            setSummary(response.data.summary || {});
            setMeta(response.data.meta || {});
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Data stock tidak dapat dimuat.");
        } finally {
            setLoading(false);
        }
    }, [debouncedSearch, page, perPage, status]);

    useEffect(() => { loadInventory(); }, [loadInventory]);

    useEffect(() => {
        const params = new URLSearchParams((window.location.hash.split("?")[1] || ""));
        const productId = Number(routeParams.productId || params.get("product"));
        const variantId = Number(routeParams.focusVariantId || params.get("variant"));
        if (!productId || !variantId) return;
        let cancelled = false;
        const focusRow = async () => {
            let row = rows.find((item) => Number(item.id) === variantId && Number(item.master_product_id) === productId);
            if (!row) {
                try {
                    const response = await axios.get(`/api/master-products/${productId}/variants/${variantId}/inventory`);
                    row = response.data?.variant;
                    if (row && !cancelled) {
                        setRows((current) => current.some((item) => Number(item.id) === variantId) ? current : [row, ...current]);
                    }
                } catch {
                    return;
                }
            }
            if (cancelled || !row) return;
            setFocusedVariantId(variantId);
            window.setTimeout(() => document.getElementById(`stock-row-${variantId}`)?.scrollIntoView({ behavior: "smooth", block: "center" }), 120);
            window.setTimeout(() => setFocusedVariantId((current) => current === variantId ? null : current), 3500);
        };
        focusRow();
        return () => { cancelled = true; };
    }, [routeParams.focusVariantId, routeParams.productId, rows]);

    const handleUpdated = (updated, message) => {
        setRows((current) => current.map((row) => row.id === updated.id ? { ...row, ...updated } : row));
        setAdjustment(null);
        setNotice(message || "Stock berhasil diperbarui.");
        loadInventory();
    };

    const openAdjustment = (row, action) => {
        if (!canManage || row.is_bundle) return;
        setAdjustment({ row, action });
    };

    const filters = [
        { key: "all", label: "All stock", count: summary.total_skus },
        { key: "available", label: "Available", count: Math.max(0, number(summary.total_skus) - number(summary.low_stock) - number(summary.out_of_stock)) },
        { key: "low", label: "Low stock", count: summary.low_stock },
        { key: "out", label: "Out of stock", count: summary.out_of_stock },
    ];

    return (
        <AppLayout>
            <main className="min-h-screen bg-slate-50 px-4 py-5 sm:px-6 dark:bg-slate-900">
                <div className="mx-auto max-w-[1500px] space-y-5">
                    <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div><h1 className="text-2xl font-extrabold text-slate-900 dark:text-white">Stock Management</h1><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Pantau dan sesuaikan persediaan seluruh SKU master dari satu halaman.</p></div>
                        <div className="relative w-full lg:w-80"><span className="material-symbols-rounded pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-lg text-slate-400">search</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari produk atau SKU..." className="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-10 text-sm outline-none transition focus:border-[#304674] focus:ring-2 focus:ring-[#304674]/10 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />{search && <button type="button" onClick={() => setSearch("")} className="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Hapus pencarian"><span className="material-symbols-rounded text-lg">close</span></button>}</div>
                    </header>

                    {notice && <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"><span>{notice}</span><button type="button" onClick={() => setNotice("")} className="material-symbols-rounded">close</button></div>}
                    {error && <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">{error}</div>}

                    <section className="grid overflow-hidden rounded-lg border border-slate-200 shadow-sm sm:grid-cols-2 xl:grid-cols-4 dark:border-slate-700"><SummaryCard icon="inventory_2" label="Master SKU" value={summary.total_skus} tone="blue" /><SummaryCard icon="warehouse" label="Total Available" value={summary.total_available} tone="emerald" /><SummaryCard icon="inventory" label="Low Stock" value={summary.low_stock} tone="amber" /><SummaryCard icon="production_quantity_limits" label="Out of Stock" value={summary.out_of_stock} tone="rose" /></section>

                    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
                            <div className="flex gap-2 overflow-x-auto pb-1 sm:pb-0">{filters.map((filter) => <button key={filter.key} type="button" onClick={() => setStatus(filter.key)} className={`flex h-9 shrink-0 items-center gap-2 rounded-lg px-3 text-xs font-bold transition ${status === filter.key ? "bg-[#304674] text-white shadow-sm" : "border border-slate-200 bg-white text-slate-600 hover:border-[#304674]/30 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"}`}>{filter.label}<span className={`rounded px-1.5 py-0.5 text-[10px] ${status === filter.key ? "bg-white/15" : "bg-slate-100 dark:bg-slate-700"}`}>{formatNumber(filter.count)}</span></button>)}</div>
                            <div className="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                                <span>Tampilkan</span>
                                <FinesheetSelect
                                    value={perPage}
                                    onChange={(value) => setPerPage(Number(value))}
                                    options={[20, 30, 50, 100].map((value) => ({ value, label: String(value) }))}
                                    ariaLabel="Jumlah SKU per halaman"
                                    compact
                                    showIcon={false}
                                    showOptionIcons={false}
                                    menuMinWidth={96}
                                    className="w-[78px]"
                                    triggerClassName="h-9 min-h-9 gap-1 px-3"
                                    menuClassName="p-1"
                                />
                            </div>
                        </div>

                        <div className="hidden overflow-x-auto lg:block">
                            <table className="w-full min-w-[1280px] text-left">
                                <thead className="bg-slate-50 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-900/50">
                                    <tr>
                                        <th className="px-5 py-3">Product / Variant</th>
                                        <th className="px-4 py-3">SKU</th>
                                        <th className="px-4 py-3 text-right">Available</th>
                                        <th className="px-4 py-3 text-right">On Hand</th>
                                        <th className="px-4 py-3 text-right">On Order</th>
                                        <th className="px-4 py-3 text-right">Reserved</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-5 py-3 text-right">Stock Adjustments</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                    {loading ? Array.from({ length: 6 }, (_, index) => (
                                        <tr key={index} className="animate-pulse"><td colSpan="8" className="px-5 py-4"><div className="h-12 rounded bg-slate-100 dark:bg-slate-700" /></td></tr>
                                    )) : rows.map((row) => {
                                        const focused = focusedVariantId === Number(row.id);
                                        return (
                                            <tr id={`stock-row-${row.id}`} key={row.id} className={`group transition-colors duration-300 ${focused ? "bg-blue-50 ring-1 ring-inset ring-[#304674]/30 dark:bg-blue-500/10" : "hover:bg-slate-50/80 dark:hover:bg-slate-700/30"}`}>
                                                <td className="px-5 py-4"><div className="flex min-w-0 items-center gap-3"><ProductImage variant={row} /><div className="min-w-0"><p className="max-w-xs truncate text-sm font-bold text-slate-900 dark:text-white" title={row.name}>{row.name}</p><p className="mt-1 max-w-xs truncate text-xs text-slate-500">{row.variant_name || "Tanpa nama varian"}{row.supplier_name ? ` / ${row.supplier_name}` : ""}</p></div></div></td>
                                                <td className="px-4 py-4"><p className="font-mono text-xs font-bold text-slate-700 dark:text-slate-200">{String(row.sku || "").toUpperCase()}</p>{row.is_bundle && <span className="mt-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold text-amber-700">BUNDLE</span>}</td>
                                                <td className="px-4 py-4 text-right text-sm font-extrabold tabular-nums text-[#304674] dark:text-blue-300">{formatNumber(row.available_stock ?? row.stock)}</td>
                                                <td className="px-4 py-4 text-right text-sm font-semibold tabular-nums text-slate-700 dark:text-slate-200">{formatNumber(row.on_hand)}</td>
                                                <td className="px-4 py-4 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">{formatNumber(row.on_order)}</td>
                                                <td className="px-4 py-4 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">{formatNumber(row.reserved_stock)}</td>
                                                <td className="px-4 py-4"><StockStatus available={number(row.available_stock ?? row.stock)} /></td>
                                                <td className="px-5 py-4"><StockActions row={row} canManage={canManage} onAction={openAdjustment} /></td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="divide-y divide-slate-100 lg:hidden dark:divide-slate-700">
                            {loading ? Array.from({ length: 5 }, (_, index) => <div key={index} className="animate-pulse p-4"><div className="h-36 rounded bg-slate-100 dark:bg-slate-700" /></div>) : rows.map((row) => {
                                const focused = focusedVariantId === Number(row.id);
                                return (
                                    <article id={`stock-row-${row.id}`} key={row.id} className={`p-4 transition-colors duration-300 ${focused ? "bg-blue-50 ring-1 ring-inset ring-[#304674]/30 dark:bg-blue-500/10" : ""}`}>
                                        <div className="flex items-start gap-3"><ProductImage variant={row} /><div className="min-w-0 flex-1"><p className="truncate text-sm font-bold text-slate-900 dark:text-white">{row.name}</p><p className="mt-1 truncate text-xs text-slate-500">{row.variant_name || "Tanpa nama varian"}</p><p className="mt-2 font-mono text-[11px] font-bold text-[#304674] dark:text-blue-300">{String(row.sku || "").toUpperCase()}</p></div><StockStatus available={number(row.available_stock ?? row.stock)} /></div>
                                        <div className="mt-4 grid grid-cols-4 overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">{[["Available", row.available_stock ?? row.stock], ["On Hand", row.on_hand], ["On Order", row.on_order], ["Reserved", row.reserved_stock]].map(([label, value], index) => <div key={label} className={`px-2 py-3 text-center ${index ? "border-l border-slate-200 dark:border-slate-700" : ""}`}><p className="text-[9px] font-bold uppercase text-slate-400">{label}</p><p className={`mt-1 text-sm font-bold tabular-nums ${label === "Available" ? "text-[#304674] dark:text-blue-300" : "text-slate-700 dark:text-slate-200"}`}>{formatNumber(value)}</p></div>)}</div>
                                        <div className="mt-3"><StockActions row={row} canManage={canManage} onAction={openAdjustment} compact /></div>
                                    </article>
                                );
                            })}
                        </div>

                        {!loading && rows.length === 0 && <div className="px-5 py-16 text-center"><span className="material-symbols-rounded text-4xl text-slate-300">inventory_2</span><h3 className="mt-3 text-sm font-bold text-slate-700 dark:text-slate-200">Stock tidak ditemukan</h3><p className="mt-1 text-xs text-slate-400">Ubah pencarian atau filter status stock.</p></div>}

                        <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700"><span>Menampilkan {meta.from || 0}-{meta.to || 0} dari {formatNumber(meta.total)} SKU</span><div className="flex items-center gap-2"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => current - 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><span className="material-symbols-rounded">chevron_left</span></button><span className="min-w-16 text-center font-bold text-slate-700 dark:text-slate-200">{meta.current_page || 1} / {meta.last_page || 1}</span><button type="button" disabled={page >= (meta.last_page || 1) || loading} onClick={() => setPage((current) => current + 1)} className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 disabled:opacity-40 dark:border-slate-700"><span className="material-symbols-rounded">chevron_right</span></button></div></div>
                    </section>
                </div>
            </main>

            <AnimatePresence>
                {adjustment && (
                    <AdjustmentModal
                        action={adjustment.action}
                        variant={adjustment.row}
                        onClose={() => setAdjustment(null)}
                        onSaved={(response) => handleUpdated(response.variant, response.message)}
                    />
                )}
            </AnimatePresence>
        </AppLayout>
    );
}
