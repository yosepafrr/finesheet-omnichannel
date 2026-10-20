import { AnimatePresence, motion } from "framer-motion";
import { AlertTriangle, CheckCircle2, LoaderCircle, PackagePlus, X } from "lucide-react";
import React, { useEffect, useMemo } from "react";
import { createPortal } from "react-dom";

function productEntries(product) {
    const entries = product?.variants?.length ? product.variants : [product];

    return entries.filter((entry) => {
        const sku = String(entry.model_sku ?? entry.product_sku ?? "").trim();
        return sku !== "" && sku !== "0";
    });
}

export default function MarketplaceConversionModal({
    isOpen,
    products = [],
    isSaving = false,
    error = "",
    onClose,
    onConfirm,
}) {
    const summary = useMemo(() => {
        const entries = products.flatMap(productEntries);
        const existing = entries.filter(
            (entry) => entry.master_product_variant_id,
        ).length;
        const withoutSku = products.filter(
            (product) => productEntries(product).length === 0,
        ).length;

        return {
            skuCount: entries.length,
            existing,
            withoutSku,
        };
    }, [products]);

    useEffect(() => {
        if (!isOpen) return undefined;
        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event) => {
            if (event.key === "Escape" && !isSaving) onClose();
        };
        document.body.style.overflow = "hidden";
        document.addEventListener("keydown", closeOnEscape);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [isOpen, isSaving, onClose]);

    if (typeof document === "undefined") return null;

    return createPortal(
        <AnimatePresence>
            {isOpen && (
                <div className="fixed inset-0 z-[10020] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm">
                    <motion.button
                        type="button"
                        aria-label="Tutup modal"
                        className="absolute inset-0 cursor-default"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={() => !isSaving && onClose()}
                    />
                    <motion.div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="marketplace-conversion-title"
                        initial={{ opacity: 0, y: 12, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 8, scale: 0.98 }}
                        className="relative z-10 w-full max-w-md overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800"
                    >
                        <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                            <div className="flex min-w-0 items-start gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300">
                                    <PackagePlus size={19} />
                                </span>
                                <div className="min-w-0">
                                    <h2
                                        id="marketplace-conversion-title"
                                        className="text-base font-bold text-slate-900 dark:text-white"
                                    >
                                        Konversi ke Produk Master
                                    </h2>
                                    <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        {products.length} produk marketplace terpilih
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                disabled={isSaving}
                                onClick={onClose}
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40 dark:hover:bg-slate-700 dark:hover:text-white"
                                aria-label="Tutup"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        <div className="space-y-4 px-5 py-5">
                            <div className="divide-y divide-slate-100 border-y border-slate-100 text-sm dark:divide-slate-700 dark:border-slate-700">
                                <div className="flex items-center justify-between py-3">
                                    <span className="text-slate-500 dark:text-slate-400">
                                        SKU valid
                                    </span>
                                    <span className="font-bold text-slate-900 dark:text-white">
                                        {summary.skuCount}
                                    </span>
                                </div>
                                {summary.existing > 0 && (
                                    <div className="flex items-center justify-between py-3">
                                        <span className="inline-flex items-center gap-2 text-slate-500 dark:text-slate-400">
                                            <CheckCircle2
                                                size={15}
                                                className="text-emerald-500"
                                            />
                                            Sudah menjadi master
                                        </span>
                                        <span className="font-bold text-slate-900 dark:text-white">
                                            {summary.existing}
                                        </span>
                                    </div>
                                )}
                            </div>

                            {summary.withoutSku > 0 && (
                                <div className="flex gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-5 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                                    <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                                    <span>
                                        {summary.withoutSku} produk tanpa SKU akan dilewati.
                                    </span>
                                </div>
                            )}

                            {error && (
                                <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">
                                    {error}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <button
                                type="button"
                                disabled={isSaving}
                                onClick={onClose}
                                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-white disabled:opacity-40 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                Batal
                            </button>
                            <button
                                type="button"
                                disabled={isSaving || summary.skuCount === 0}
                                onClick={onConfirm}
                                className="inline-flex items-center gap-2 rounded-lg bg-[#304674] px-4 py-2 text-sm font-semibold text-white hover:bg-[#243558] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-blue-600 dark:hover:bg-blue-700"
                            >
                                {isSaving ? (
                                    <LoaderCircle size={17} className="animate-spin" />
                                ) : (
                                    <PackagePlus size={17} />
                                )}
                                {isSaving ? "Mengonversi..." : "Konversi ke Master"}
                            </button>
                        </div>
                    </motion.div>
                </div>
            )}
        </AnimatePresence>,
        document.body,
    );
}
