import React, { useCallback, useEffect, useState } from "react";
import axios from "axios";
import { AnimatePresence, motion } from "framer-motion";

const EMPTY_VARIANT = {
    sku: "",
    variant_name: "",
    barcode: "",
    hpp: 0,
    stock: 0,
    is_active: true,
};

const EMPTY_FORM = {
    name: "",
    brand: "",
    category: "",
    description: "",
    image: "",
    status: "active",
    variants: [{ ...EMPTY_VARIANT }],
};

function formatRp(value) {
    return `Rp ${Number(value || 0).toLocaleString("id-ID")}`;
}

function getErrorMessage(error) {
    const errors = error.response?.data?.errors;
    if (errors) return Object.values(errors).flat()[0];

    return error.response?.data?.message || "Terjadi kesalahan. Silakan coba lagi.";
}

function StatusBadge({ status }) {
    const styles = {
        active: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400",
        draft: "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400",
        archived: "bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300",
    };
    const labels = { active: "Aktif", draft: "Draft", archived: "Diarsipkan" };

    return (
        <span className={`inline-flex rounded-md px-2 py-1 text-xs font-semibold ${styles[status] || styles.draft}`}>
            {labels[status] || status}
        </span>
    );
}

function ProductImage({ product }) {
    if (product.image) {
        return (
            <img
                src={product.image}
                alt=""
                className="h-11 w-11 shrink-0 rounded-lg border border-slate-200 object-cover dark:border-slate-700"
            />
        );
    }

    return (
        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-sm font-bold text-slate-500 dark:border-slate-700 dark:bg-slate-700 dark:text-slate-300">
            {product.name?.trim()?.charAt(0)?.toUpperCase() || "P"}
        </div>
    );
}

function MasterProductSkeleton() {
    return (
        <div className="animate-pulse divide-y divide-slate-100 dark:divide-slate-700">
            {[1, 2, 3, 4].map((row) => (
                <div key={row} className="flex items-center gap-4 px-5 py-5">
                    <div className="h-11 w-11 rounded-lg bg-slate-200 dark:bg-slate-700" />
                    <div className="flex-1 space-y-2">
                        <div className="h-4 w-48 rounded bg-slate-200 dark:bg-slate-700" />
                        <div className="h-3 w-28 rounded bg-slate-100 dark:bg-slate-700" />
                    </div>
                    <div className="h-6 w-20 rounded bg-slate-100 dark:bg-slate-700" />
                    <div className="h-6 w-16 rounded bg-slate-100 dark:bg-slate-700" />
                </div>
            ))}
        </div>
    );
}

function MasterProductModal({ product, onClose, onSaved }) {
    const [form, setForm] = useState(() => {
        if (!product) return structuredClone(EMPTY_FORM);

        return {
            name: product.name || "",
            brand: product.brand || "",
            category: product.category || "",
            description: product.description || "",
            image: product.image || "",
            status: product.status || "active",
            variants: product.variants.map((variant) => ({
                id: variant.id,
                sku: variant.sku || "",
                variant_name: variant.variant_name || "",
                barcode: variant.barcode || "",
                hpp: variant.hpp || 0,
                stock: variant.local_stock ?? variant.stock ?? 0,
                is_active: variant.is_active ?? true,
                sync_group_id: variant.sync_group_id,
            })),
        };
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    const updateField = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    const updateVariant = (index, field, value) => {
        setForm((current) => ({
            ...current,
            variants: current.variants.map((variant, variantIndex) =>
                variantIndex === index ? { ...variant, [field]: value } : variant,
            ),
        }));
    };

    const addVariant = () => {
        setForm((current) => ({
            ...current,
            variants: [...current.variants, { ...EMPTY_VARIANT }],
        }));
    };

    const removeVariant = (index) => {
        setForm((current) => ({
            ...current,
            variants: current.variants.filter((_, variantIndex) => variantIndex !== index),
        }));
    };

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError("");

        try {
            const payload = {
                ...form,
                brand: form.brand || null,
                category: form.category || null,
                description: form.description || null,
                image: form.image || null,
                variants: form.variants.map((variant) => ({
                    ...variant,
                    hpp: Number(variant.hpp || 0),
                    stock: Number(variant.stock || 0),
                })),
            };

            if (product) {
                await axios.put(`/api/master-products/${product.id}`, payload);
            } else {
                await axios.post("/api/master-products", payload);
            }

            onSaved();
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setSaving(false);
        }
    };

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6"
            onMouseDown={(event) => event.target === event.currentTarget && onClose()}
        >
            <motion.form
                initial={{ opacity: 0, y: 16, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                exit={{ opacity: 0, y: 12, scale: 0.98 }}
                transition={{ duration: 0.18 }}
                onSubmit={submit}
                className="flex max-h-[92dvh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800"
            >
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900 dark:text-white">
                            {product ? "Edit Produk Master" : "Tambah Produk Master"}
                        </h2>
                        <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                            Identitas produk dan SKU internal.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-700 dark:hover:text-white"
                        aria-label="Tutup"
                    >
                        <span className="material-symbols-rounded">close</span>
                    </button>
                </div>

                <div className="overflow-y-auto px-5 py-5">
                    {error && (
                        <div className="mb-5 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                            <span className="material-symbols-rounded text-lg">error</span>
                            <span>{error}</span>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label className="md:col-span-2">
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Nama produk</span>
                            <input
                                required
                                value={form.name}
                                onChange={(event) => updateField("name", event.target.value)}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                placeholder="Contoh: Kemeja Oxford Pria"
                            />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Brand</span>
                            <input
                                value={form.brand}
                                onChange={(event) => updateField("brand", event.target.value)}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                            />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Kategori</span>
                            <input
                                value={form.category}
                                onChange={(event) => updateField("category", event.target.value)}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                            />
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Status</span>
                            <select
                                value={form.status}
                                onChange={(event) => updateField("status", event.target.value)}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                            >
                                <option value="active">Aktif</option>
                                <option value="draft">Draft</option>
                                <option value="archived">Diarsipkan</option>
                            </select>
                        </label>
                        <label>
                            <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">URL gambar</span>
                            <input
                                type="url"
                                value={form.image}
                                onChange={(event) => updateField("image", event.target.value)}
                                className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                placeholder="https://..."
                            />
                        </label>
                    </div>

                    <div className="mt-7 flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-700">
                        <div>
                            <h3 className="font-bold text-slate-900 dark:text-white">Varian dan SKU</h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">SKU yang sama akan ditautkan ke grup sinkronisasi stok.</p>
                        </div>
                        <button
                            type="button"
                            onClick={addVariant}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                        >
                            <span className="material-symbols-rounded text-lg">add</span>
                            Tambah Varian
                        </button>
                    </div>

                    <div className="divide-y divide-slate-200 dark:divide-slate-700">
                        {form.variants.map((variant, index) => (
                            <div key={variant.id || `new-${index}`} className="py-4">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-sm font-bold text-slate-700 dark:text-slate-200">Varian {index + 1}</span>
                                    {form.variants.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => removeVariant(index)}
                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                                            aria-label="Hapus varian"
                                        >
                                            <span className="material-symbols-rounded text-lg">delete</span>
                                        </button>
                                    )}
                                </div>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                    <label className="lg:col-span-2">
                                        <span className="mb-1 block text-xs font-semibold text-slate-500">SKU</span>
                                        <input
                                            required
                                            value={variant.sku}
                                            onChange={(event) => updateVariant(index, "sku", event.target.value)}
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                        />
                                    </label>
                                    <label className="lg:col-span-2">
                                        <span className="mb-1 block text-xs font-semibold text-slate-500">Nama varian</span>
                                        <input
                                            value={variant.variant_name}
                                            onChange={(event) => updateVariant(index, "variant_name", event.target.value)}
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                            placeholder="Hitam / XXL"
                                        />
                                    </label>
                                    <label>
                                        <span className="mb-1 block text-xs font-semibold text-slate-500">Stok</span>
                                        <input
                                            type="number"
                                            min="0"
                                            required
                                            disabled={Boolean(variant.sync_group_id)}
                                            value={variant.stock}
                                            onChange={(event) => updateVariant(index, "stock", event.target.value)}
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-white dark:disabled:bg-slate-700"
                                        />
                                    </label>
                                    <label className="lg:col-span-2">
                                        <span className="mb-1 block text-xs font-semibold text-slate-500">Barcode</span>
                                        <input
                                            value={variant.barcode}
                                            onChange={(event) => updateVariant(index, "barcode", event.target.value)}
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                        />
                                    </label>
                                    <label className="lg:col-span-2">
                                        <span className="mb-1 block text-xs font-semibold text-slate-500">HPP</span>
                                        <input
                                            type="number"
                                            min="0"
                                            required
                                            value={variant.hpp}
                                            onChange={(event) => updateVariant(index, "hpp", event.target.value)}
                                            className="w-full rounded-lg border-slate-300 text-sm focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white"
                                        />
                                    </label>
                                    <label className="flex items-end pb-2">
                                        <input
                                            type="checkbox"
                                            checked={variant.is_active}
                                            onChange={(event) => updateVariant(index, "is_active", event.target.checked)}
                                            className="rounded border-slate-300 text-[#304674] focus:ring-[#304674]"
                                        />
                                        <span className="ml-2 text-sm font-medium text-slate-700 dark:text-slate-200">Aktif</span>
                                    </label>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-800">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-white dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                    >
                        Batal
                    </button>
                    <button
                        type="submit"
                        disabled={saving}
                        className="inline-flex min-w-28 items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2 text-sm font-semibold text-white hover:bg-[#243558] disabled:cursor-not-allowed disabled:opacity-60 dark:bg-blue-600 dark:hover:bg-blue-700"
                    >
                        <span className={`material-symbols-rounded text-lg ${saving ? "animate-spin" : ""}`}>
                            {saving ? "progress_activity" : "save"}
                        </span>
                        {saving ? "Menyimpan" : "Simpan"}
                    </button>
                </div>
            </motion.form>
        </motion.div>
    );
}

export default function MasterProductPanel({ search = "" }) {
    const [products, setProducts] = useState([]);
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [expanded, setExpanded] = useState({});
    const [modalProduct, setModalProduct] = useState(undefined);
    const [modalOpen, setModalOpen] = useState(false);
    const [deletingId, setDeletingId] = useState(null);

    const fetchProducts = useCallback(async () => {
        setLoading(true);
        setError("");

        try {
            const response = await axios.get("/api/master-products", {
                params: { search, page, per_page: 20 },
            });
            setProducts(response.data.data || []);
            setMeta(response.data.meta || {});
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setLoading(false);
        }
    }, [page, search]);

    useEffect(() => {
        setPage(1);
    }, [search]);

    useEffect(() => {
        const timeout = setTimeout(fetchProducts, 250);
        return () => clearTimeout(timeout);
    }, [fetchProducts]);

    const openCreate = () => {
        setModalProduct(null);
        setModalOpen(true);
    };

    const openEdit = (product) => {
        setModalProduct(product);
        setModalOpen(true);
    };

    const handleSaved = () => {
        setModalOpen(false);
        fetchProducts();
    };

    const removeProduct = async (product) => {
        if (!window.confirm(`Hapus produk master "${product.name}"?`)) return;

        setDeletingId(product.id);
        setError("");
        try {
            await axios.delete(`/api/master-products/${product.id}`);
            await fetchProducts();
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setDeletingId(null);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-base font-bold text-slate-900 dark:text-white">Katalog Master</h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        {meta.total || 0} produk internal
                    </p>
                </div>
                <button
                    type="button"
                    onClick={openCreate}
                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700"
                >
                    <span className="material-symbols-rounded text-lg">add</span>
                    Tambah Produk Master
                </button>
            </div>

            {error && (
                <div className="flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                    <span>{error}</span>
                    <button type="button" onClick={fetchProducts} className="font-bold hover:underline">Coba lagi</button>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800">
                {loading ? (
                    <MasterProductSkeleton />
                ) : products.length === 0 ? (
                    <div className="flex flex-col items-center px-6 py-16 text-center">
                        <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                            <span className="material-symbols-rounded text-2xl">inventory_2</span>
                        </div>
                        <h3 className="font-bold text-slate-900 dark:text-white">
                            {search ? "Produk master tidak ditemukan" : "Belum ada produk master"}
                        </h3>
                        <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                            {search ? "Coba gunakan nama atau SKU lain." : "Tambahkan produk internal pertama untuk mulai menata SKU lintas toko."}
                        </p>
                        {!search && (
                            <button
                                type="button"
                                onClick={openCreate}
                                className="mt-5 inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
                            >
                                <span className="material-symbols-rounded text-lg">add</span>
                                Tambah Produk
                            </button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[900px] table-fixed text-left">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                    <tr>
                                        <th className="w-[34%] px-5 py-3">Produk</th>
                                        <th className="w-[16%] px-4 py-3">Brand / Kategori</th>
                                        <th className="w-[10%] px-4 py-3 text-center">Varian</th>
                                        <th className="w-[12%] px-4 py-3 text-right">Stok</th>
                                        <th className="w-[12%] px-4 py-3 text-center">Listing</th>
                                        <th className="w-[10%] px-4 py-3">Status</th>
                                        <th className="w-[6%] px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                    {products.map((product) => (
                                        <React.Fragment key={product.id}>
                                            <tr className="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-700/30">
                                                <td className="px-5 py-4">
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpanded((current) => ({ ...current, [product.id]: !current[product.id] }))}
                                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-white"
                                                            aria-label="Lihat varian"
                                                        >
                                                            <span className={`material-symbols-rounded text-lg transition-transform ${expanded[product.id] ? "rotate-90" : ""}`}>chevron_right</span>
                                                        </button>
                                                        <ProductImage product={product} />
                                                        <div className="min-w-0">
                                                            <p className="truncate font-bold text-slate-900 dark:text-white">{product.name}</p>
                                                            <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                                                {product.variants.map((variant) => variant.sku).slice(0, 2).join(" · ")}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-4 text-sm">
                                                    <p className="truncate font-medium text-slate-700 dark:text-slate-200">{product.brand || "-"}</p>
                                                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{product.category || "Tanpa kategori"}</p>
                                                </td>
                                                <td className="px-4 py-4 text-center text-sm font-semibold text-slate-700 dark:text-slate-200">{product.variants_count}</td>
                                                <td className="px-4 py-4 text-right text-sm font-bold text-slate-900 dark:text-white">{product.total_stock.toLocaleString("id-ID")}</td>
                                                <td className="px-4 py-4 text-center">
                                                    <span className="inline-flex rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                                        {product.linked_listings_count}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4"><StatusBadge status={product.status} /></td>
                                                <td className="px-4 py-4">
                                                    <div className="flex justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => openEdit(product)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-[#304674] dark:hover:bg-slate-700 dark:hover:text-blue-400"
                                                            aria-label="Edit produk master"
                                                        >
                                                            <span className="material-symbols-rounded text-lg">edit</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={deletingId === product.id}
                                                            onClick={() => removeProduct(product)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 disabled:opacity-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                                            aria-label="Hapus produk master"
                                                        >
                                                            <span className="material-symbols-rounded text-lg">delete</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            {expanded[product.id] && (
                                                <tr className="bg-slate-50/80 dark:bg-slate-900/35">
                                                    <td colSpan="7" className="px-16 py-3">
                                                        <div className="divide-y divide-slate-200 dark:divide-slate-700">
                                                            {product.variants.map((variant) => (
                                                                <div key={variant.id} className="grid grid-cols-[minmax(180px,1.4fr)_minmax(130px,1fr)_110px_110px_minmax(170px,1fr)] items-center gap-4 py-3 text-sm">
                                                                    <div className="min-w-0">
                                                                        <p className="truncate font-semibold text-slate-800 dark:text-slate-100">{variant.variant_name || "Varian utama"}</p>
                                                                        <p className="truncate font-mono text-xs text-slate-500">{variant.sku}</p>
                                                                    </div>
                                                                    <span className="truncate text-slate-600 dark:text-slate-300">{variant.barcode || "Tanpa barcode"}</span>
                                                                    <span className="text-right font-semibold text-slate-800 dark:text-slate-100">{variant.stock.toLocaleString("id-ID")}</span>
                                                                    <span className="text-right text-slate-600 dark:text-slate-300">{formatRp(variant.hpp)}</span>
                                                                    <div className="flex flex-wrap justify-end gap-1.5">
                                                                        {variant.channels.length ? variant.channels.map((channel) => (
                                                                            <span key={channel.store_id} className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                                                {channel.store_name || channel.platform}
                                                                            </span>
                                                                        )) : (
                                                                            <span className="text-xs text-slate-400">Belum ditautkan</span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </React.Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                Menampilkan {meta.from || 0}-{meta.to || 0} dari {meta.total || 0} produk
                            </p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    disabled={page <= 1}
                                    onClick={() => setPage((current) => Math.max(1, current - 1))}
                                    className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 dark:border-slate-600 dark:hover:bg-slate-700"
                                    aria-label="Halaman sebelumnya"
                                >
                                    <span className="material-symbols-rounded">chevron_left</span>
                                </button>
                                <span className="min-w-16 text-center text-sm font-semibold text-slate-700 dark:text-slate-200">{meta.current_page || 1} / {meta.last_page || 1}</span>
                                <button
                                    type="button"
                                    disabled={page >= (meta.last_page || 1)}
                                    onClick={() => setPage((current) => current + 1)}
                                    className="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 dark:border-slate-600 dark:hover:bg-slate-700"
                                    aria-label="Halaman berikutnya"
                                >
                                    <span className="material-symbols-rounded">chevron_right</span>
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>

            <AnimatePresence>
                {modalOpen && (
                    <MasterProductModal
                        product={modalProduct}
                        onClose={() => setModalOpen(false)}
                        onSaved={handleSaved}
                    />
                )}
            </AnimatePresence>
        </div>
    );
}
