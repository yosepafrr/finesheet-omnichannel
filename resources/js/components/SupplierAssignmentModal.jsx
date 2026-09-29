import { AnimatePresence, motion } from "framer-motion";
import React, { useEffect, useState } from "react";
import { createPortal } from "react-dom";

export default function SupplierAssignmentModal({
    isOpen,
    title,
    itemCount = 1,
    currentSupplierId = "",
    suppliers = [],
    onClose,
    onSave,
}) {
    const [supplierId, setSupplierId] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        if (!isOpen) return;
        setSupplierId(currentSupplierId ? String(currentSupplierId) : "");
        setError("");
    }, [currentSupplierId, isOpen]);

    useEffect(() => {
        if (!isOpen) return undefined;
        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event) => {
            if (event.key === "Escape" && !saving) onClose();
        };
        document.body.style.overflow = "hidden";
        document.addEventListener("keydown", closeOnEscape);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [isOpen, onClose, saving]);

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        setError("");
        try {
            await onSave(supplierId ? Number(supplierId) : null);
        } catch (requestError) {
            const errors = requestError.response?.data?.errors;
            setError(
                (errors && Object.values(errors).flat()[0])
                || requestError.response?.data?.message
                || "Supplier gagal diperbarui.",
            );
        } finally {
            setSaving(false);
        }
    };

    return createPortal(
        <AnimatePresence>
            {isOpen && (
                <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm">
                    <motion.button
                        type="button"
                        aria-label="Tutup modal"
                        className="absolute inset-0 cursor-default"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={() => !saving && onClose()}
                    />
                    <motion.form
                        onSubmit={submit}
                        initial={{ opacity: 0, y: 12, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 8, scale: 0.98 }}
                        className="relative z-10 w-full max-w-md overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800"
                    >
                        <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                            <div className="flex min-w-0 items-start gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300">
                                    <span className="material-symbols-rounded">local_shipping</span>
                                </span>
                                <div className="min-w-0">
                                    <h2 className="truncate text-base font-bold text-slate-900 dark:text-white">{title || "Atur supplier"}</h2>
                                    <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Berlaku untuk {itemCount} produk terpilih.</p>
                                </div>
                            </div>
                            <button type="button" disabled={saving} onClick={onClose} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40 dark:hover:bg-slate-700 dark:hover:text-white" aria-label="Tutup">
                                <span className="material-symbols-rounded">close</span>
                            </button>
                        </div>

                        <div className="space-y-4 px-5 py-5">
                            {suppliers.length > 0 ? (
                                <label className="block">
                                    <span className="mb-1.5 block text-sm font-semibold text-slate-700 dark:text-slate-200">Supplier</span>
                                    <select value={supplierId} onChange={(event) => setSupplierId(event.target.value)} className="h-11 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-900 dark:text-white">
                                        <option value="">Belum ditentukan</option>
                                        {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                                    </select>
                                    <p className="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Pilih "Belum ditentukan" untuk melepas supplier dari produk ini.</p>
                                </label>
                            ) : (
                                <div className="border-y border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                                    Belum ada supplier. Tambahkan supplier terlebih dahulu di halaman Payable.
                                </div>
                            )}
                            {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{error}</p>}
                        </div>

                        <div className="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-900/40">
                            {suppliers.length === 0 && <a href="#/payable" onClick={onClose} className="mr-auto text-sm font-semibold text-[#304674] hover:underline dark:text-blue-300">Kelola supplier</a>}
                            <button type="button" disabled={saving} onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-white disabled:opacity-40 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Batal</button>
                            <button type="submit" disabled={saving || suppliers.length === 0} className="inline-flex items-center gap-2 rounded-lg bg-[#304674] px-4 py-2 text-sm font-semibold text-white hover:bg-[#243558] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-blue-600 dark:hover:bg-blue-700">
                                <span className={`material-symbols-rounded text-lg ${saving ? "animate-spin" : ""}`}>{saving ? "progress_activity" : "save"}</span>
                                {saving ? "Menyimpan..." : "Simpan"}
                            </button>
                        </div>
                    </motion.form>
                </div>
            )}
        </AnimatePresence>,
        document.body,
    );
}
