import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import axios from "axios";
import { X, Plus, Trash2, Search, Save, LoaderCircle, Package } from "lucide-react";
import FinesheetSelect from "@/components/ui/FinesheetSelect";

const payments = [{ value: "UNPAID", label: "Belum dibayar" }, { value: "PARTIAL", label: "Dibayar sebagian" }, { value: "PAID", label: "Lunas" }];
const statuses = [{ value: "PROCESSED", label: "Perlu dikirim" }, { value: "SHIPPED", label: "Dikirim" }, { value: "COMPLETED", label: "Selesai" }, { value: "CANCELLED", label: "Batal" }];
const rupiah = (n) => `Rp ${Number(n || 0).toLocaleString("id-ID")}`;
const fieldClass = "w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-[#304674] focus:ring-1 focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-800 dark:text-white";
const localNow = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };
const localDateTime = (value) => { const d = new Date(value); return Number.isNaN(d.getTime()) ? localNow() : new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); };

export default function ManualOrderModal({ order, onClose, onSaved }) {
    const [form, setForm] = useState(() => ({ request_key: crypto.randomUUID(), buyer_name: "", buyer_phone: "", source: "WhatsApp", order_time: localNow(), tracking_number: order?.manual_data?.tracking_number || "", shipping_provider: order?.manual_data?.shipping_provider || "", payment_status: order?.manual_data?.payment_status || "UNPAID", status: order?.order_status || "PROCESSED", financial_account_id: order?.manual_data?.financial_account_id || "", paid_amount: order?.manual_data?.paid_amount ?? "", payment_received_at: order?.manual_data?.payment_received_at ? localDateTime(order.manual_data.payment_received_at) : localNow(), discount: 0, shipping_fee: 0, notes: "", items: [] }));
    const [accounts, setAccounts] = useState([]);
    const [loadingAccounts, setLoadingAccounts] = useState(true);
    const [query, setQuery] = useState("");
    const [options, setOptions] = useState([]);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState("");
    const [saving, setSaving] = useState(false);
    const dialogRef = useRef(null);
    const savingRef = useRef(false);
    const change = (key, value) => setForm((old) => ({ ...old, [key]: value }));
    const changeItem = (id, key, value) => setForm((old) => ({ ...old, items: old.items.map((item) => item.variant_id === id ? { ...item, [key]: value } : item) }));
    const total = form.items.reduce((sum, item) => sum + Number(item.quantity) * Number(item.price), 0) - Number(form.discount) + Number(form.shipping_fee);

    useEffect(() => {
        const previous = document.body.style.overflow;
        const focused = document.activeElement;
        document.body.style.overflow = "hidden";
        dialogRef.current?.querySelector("input,button")?.focus();
        const keyboard = (event) => {
            if (event.key === "Escape" && !savingRef.current) onClose();
            if (event.key !== "Tab") return;
            const nodes = [...dialogRef.current.querySelectorAll("button:not(:disabled),input:not(:disabled),textarea:not(:disabled),[tabindex='0']")].filter((node) => node.offsetParent !== null);
            const first = nodes[0], last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        };
        document.addEventListener("keydown", keyboard);
        return () => { document.body.style.overflow = previous; document.removeEventListener("keydown", keyboard); focused?.focus(); };
    }, [onClose]);

    useEffect(() => {
        const controller = new AbortController();
        axios.get("/api/manual-orders/accounts", { signal: controller.signal })
            .then(({ data }) => setAccounts(data.accounts))
            .catch((e) => { if (!axios.isCancel(e)) setError("Daftar akun Posisi Kas belum dapat dimuat. Silakan buka kembali form."); })
            .finally(() => { if (!controller.signal.aborted) setLoadingAccounts(false); });
        return () => controller.abort();
    }, []);

    useEffect(() => {
        if (order) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setSearching(true);
            try {
                const { data } = await axios.get("/api/manual-orders/options", { params: { search: query }, signal: controller.signal });
                setOptions(data.variants);
            } catch (e) { if (!axios.isCancel(e)) setError("Katalog tidak dapat dimuat. Coba buka kembali form."); }
            finally { if (!controller.signal.aborted) setSearching(false); }
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [query, order]);

    async function submit(event) {
        event.preventDefault();
        if (savingRef.current) return;
        savingRef.current = true;
        setSaving(true); setError("");
        try {
            const payment = { payment_status: form.payment_status, financial_account_id: form.financial_account_id || null, paid_amount: form.paid_amount || null, payment_received_at: form.payment_status === "UNPAID" ? null : form.payment_received_at };
            if (order) await axios.patch(`/api/manual-orders/${order.id}`, { status: form.status, tracking_number: form.tracking_number || null, shipping_provider: form.shipping_provider || null, ...payment });
            else await axios.post("/api/manual-orders", { ...form, ...payment, items: form.items.map(({ variant_id, quantity, price }) => ({ variant_id, quantity, price })) });
            onSaved();
        } catch (e) { setError(Object.values(e.response?.data?.errors || {}).flat().join(" ") || e.response?.data?.message || "Pesanan gagal disimpan. Silakan coba kembali."); }
        finally { savingRef.current = false; setSaving(false); }
    }

    const allowedStatuses = statuses.filter(({ value }) => ({ PROCESSED: ["PROCESSED", "SHIPPED", "COMPLETED", "CANCELLED"], SHIPPED: ["SHIPPED", "COMPLETED"], COMPLETED: ["COMPLETED"], CANCELLED: ["CANCELLED"] }[order?.order_status] || []).includes(value));

    return createPortal(
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-3 backdrop-blur-sm" onClick={(e) => { if (e.target === e.currentTarget && !saving) onClose(); }}>
            <section ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="manual-order-title" className="flex max-h-[92dvh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-slate-800">
                <header className="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-slate-700">
                    <div><h2 id="manual-order-title" className="font-bold text-gray-900 dark:text-white">{order ? "Kelola pesanan manual" : "Tambah pesanan manual"}</h2><p className="mt-1 text-xs text-gray-500">{order?.order_sn || "WhatsApp, offline, dan penjualan langsung"}</p></div>
                    <button type="button" onClick={onClose} disabled={saving} className="rounded p-2 text-gray-500 hover:bg-gray-100" aria-label="Tutup"><X size={20} /></button>
                </header>
                <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <div data-testid="manual-order-scroll" className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <fieldset disabled={saving} className="min-w-0 space-y-5 p-5 disabled:opacity-70">
                        {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}
                        {order ? <>
                            <div className="border-b border-gray-100 pb-4 text-sm text-gray-700 dark:text-slate-200"><strong>{order.manual_data?.buyer_name}</strong><p>{order.manual_data?.source}</p>{order.products?.map((item, i) => <p key={i} className="mt-2">{item.quantity_purchased} x {item.product_name} / {item.model_name}</p>)}</div>
                            <label className="block space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Status pesanan</span><FinesheetSelect value={form.status} onChange={(value) => change("status", value)} options={allowedStatuses} ariaLabel="Status pesanan manual" /></label>
                            {form.status === "CANCELLED" && order.order_status !== "CANCELLED" && <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">Pembatalan akan melepas alokasi stok dan menyesuaikan tagihan supplier. Tindakan ini tidak dapat dibatalkan. Pengembalian uang pembeli tidak dilakukan otomatis.</p>}
                        </> : <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {[["buyer_name", "Nama pembeli", "text", true], ["buyer_phone", "Nomor telepon", "tel", false], ["source", "Sumber pesanan", "text", true], ["order_time", "Tanggal pesanan", "datetime-local", true]].map(([key, label, type, required]) => <label key={key} className="space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>{label}</span><input className={fieldClass} type={type} value={form[key]} required={required} maxLength={key === "buyer_phone" ? 40 : 150} onChange={(e) => change(key, e.target.value)} /></label>)}
                            </div>
                            <div>
                                <label htmlFor="manual-sku-search" className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-300">Produk master / bundle</label>
                                <div className="relative"><Search size={16} className="absolute left-3 top-3 text-gray-400" /><input id="manual-sku-search" className={`${fieldClass} pl-9`} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari nama produk atau SKU..." /></div>
                                <div className="mt-2 max-h-36 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:border-slate-600">
                                    {searching ? <p className="p-3 text-xs text-gray-500">Memuat katalog...</p> : options.filter((option) => !form.items.some((item) => item.variant_id === option.id)).map((option) => <button key={option.id} type="button" className="flex w-full items-center gap-3 p-3 text-left text-xs text-gray-700 hover:bg-blue-50 dark:text-slate-200 dark:hover:bg-slate-700" onClick={() => change("items", [...form.items, { ...option, variant_id: option.id, quantity: 1, price: 0 }])}><Package size={16} className="shrink-0 text-[#304674]" /><span className="min-w-0 flex-1"><strong className="block truncate">{option.name}</strong><span className="uppercase">{option.sku}</span>{option.is_bundle && <span className="ml-2 text-blue-600">Bundle</span>}</span><span>{option.available} Available</span><Plus size={16} /></button>)}
                                    {!searching && options.length === 0 && <p className="p-3 text-xs text-gray-500">Produk tidak ditemukan.</p>}
                                </div>
                            </div>
                            <div className="space-y-3">
                                {form.items.map((item) => <div key={item.variant_id} className="grid grid-cols-[1fr_80px_130px_32px] items-end gap-2 border-b border-gray-100 pb-3 max-sm:grid-cols-[1fr_1fr_32px] dark:border-slate-700">
                                    <div className="min-w-0 text-xs max-sm:col-span-3"><strong className="block truncate text-gray-800 dark:text-white">{item.name}</strong><span className="uppercase text-gray-500">{item.sku}</span></div>
                                    <label className="space-y-1 text-xs text-gray-500"><span>Jumlah</span><input type="number" min="1" max="100000" step="1" required className={fieldClass} value={item.quantity} onChange={(e) => changeItem(item.variant_id, "quantity", e.target.value)} /></label>
                                    <label className="space-y-1 text-xs text-gray-500"><span>Harga jual / unit</span><input type="number" min="0" max="999999999" step="0.01" required className={fieldClass} value={item.price} onChange={(e) => changeItem(item.variant_id, "price", e.target.value)} /></label>
                                    <button type="button" title="Hapus item" aria-label={`Hapus ${item.sku}`} className="mb-1 rounded p-2 text-red-500 hover:bg-red-50" onClick={() => change("items", form.items.filter((line) => line.variant_id !== item.variant_id))}><Trash2 size={16} /></button>
                                </div>)}
                            </div>
                            <div className="grid grid-cols-2 gap-4">{[["discount", "Diskon (Rp)"], ["shipping_fee", "Ongkir dibayar pembeli (Rp)"]].map(([key, label]) => <label key={key} className="space-y-1 text-xs text-gray-600 dark:text-slate-300"><span>{label}</span><input type="number" min="0" max="999999999" step="0.01" required className={fieldClass} value={form[key]} onChange={(e) => change(key, e.target.value)} /></label>)}</div>
                            <label className="block space-y-1 text-xs text-gray-600 dark:text-slate-300"><span>Catatan (opsional)</span><textarea maxLength={2000} className={fieldClass} value={form.notes} onChange={(e) => change("notes", e.target.value)} /></label>
                            <div className="flex justify-between border-t border-gray-200 pt-4 font-bold text-gray-800 dark:text-white"><span>Total pesanan</span><span className="shrink-0">{rupiah(total)}</span></div>
                        </>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Kurir (opsional)</span><input type="text" maxLength={100} className={fieldClass} value={form.shipping_provider} onChange={(e) => change("shipping_provider", e.target.value)} placeholder="Contoh: J&T Express" /></label>
                            <label className="space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Nomor resi (opsional)</span><input type="text" maxLength={100} className={fieldClass} value={form.tracking_number} onChange={(e) => change("tracking_number", e.target.value)} placeholder="Masukkan nomor resi" /></label>
                        </div>
                        <label className="block space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Status pembayaran</span><FinesheetSelect value={form.payment_status} onChange={(value) => change("payment_status", value)} options={payments} ariaLabel="Status pembayaran" /></label>
                        {form.payment_status === "PARTIAL" && <label className="block space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Nominal sudah diterima (Rp)</span><input type="number" min="0.01" max="999999999" step="0.01" required className={fieldClass} value={form.paid_amount} onChange={(e) => change("paid_amount", e.target.value)} /></label>}
                        <label className="block space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Akun penerimaan (Posisi Kas)</span><FinesheetSelect value={form.financial_account_id} disabled={loadingAccounts || saving} onChange={(value) => change("financial_account_id", value)} options={[{ value: "", label: loadingAccounts ? "Memuat akun..." : "Belum ditentukan" }, ...accounts.map((account) => ({ value: account.id, label: account.name })), ...(form.financial_account_id && !accounts.some((account) => String(account.id) === String(form.financial_account_id)) ? [{ value: form.financial_account_id, label: "Akun sebelumnya (tidak tersedia)" }] : [])]} ariaLabel="Akun penerimaan Posisi Kas" /></label>
                        {!loadingAccounts && accounts.length === 0 && <a href="/#/balance-sheet" target="_blank" rel="noopener noreferrer" className="inline-block text-xs font-medium text-[#304674] underline dark:text-blue-300">Tambah akun di Posisi Kas</a>}
                        {form.payment_status !== "UNPAID" && <label className="block space-y-1 text-xs font-medium text-gray-600 dark:text-slate-300"><span>Tanggal penerimaan pembayaran</span><input type="datetime-local" required className={fieldClass} value={form.payment_received_at} onChange={(e) => change("payment_received_at", e.target.value)} /></label>}
                        {order && <p className="text-xs text-amber-700 dark:text-amber-300">Perubahan pembayaran atau akun tujuan akan menyesuaikan saldo dan catatan penerimaan di Cashflow.</p>}
                    </fieldset>
                    </div>
                    <footer className="flex shrink-0 justify-end gap-2 border-t border-gray-200 bg-white px-5 py-4 dark:border-slate-700 dark:bg-slate-800"><button type="button" disabled={saving} onClick={onClose} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 dark:text-slate-200">Batal</button><button disabled={saving || (!order && form.items.length === 0)} className="flex items-center gap-2 rounded-lg bg-[#304674] px-4 py-2 text-sm font-semibold text-white hover:bg-[#243558] disabled:cursor-not-allowed disabled:opacity-50">{saving ? <LoaderCircle size={16} className="animate-spin" /> : <Save size={16} />} {saving ? "Menyimpan..." : "Simpan"}</button></footer>
                </form>
            </section>
        </div>, document.body,
    );
}
