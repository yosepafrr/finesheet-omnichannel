import React, { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import { createPortal } from "react-dom";
import { Check, ChevronLeft, ChevronRight, Clock3, ImageOff, LoaderCircle, Pause, Play, Plus, RefreshCw, Search, Trash2, X } from "lucide-react";
import AppLayout from "../../views/components/layouts/AppLayout";
import FinesheetSelect from "../components/ui/FinesheetSelect";

const button = "inline-flex min-h-9 items-center justify-center gap-2 rounded-md border px-3 py-2 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50";
const secondary = `${button} border-gray-200 bg-white text-[#374673] hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100`;
const primary = `${button} border-[#374673] bg-[#374673] text-white hover:bg-[#293758]`;
const date = (value) => value ? new Date(value).toLocaleString("id-ID", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "Belum ada";
const errorText = (error) => Object.values(error.response?.data?.errors || {}).flat()[0] || error.response?.data?.message || "Tidak dapat menghubungi server.";

function ProductImage({ src }) {
    const [broken, setBroken] = useState(false);
    useEffect(() => setBroken(false), [src]);
    return <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-md border border-gray-100 bg-gray-50 dark:border-slate-600 dark:bg-slate-700">
        {src && !broken ? <img src={src} alt="" className="h-full w-full object-cover" onError={() => setBroken(true)} /> : <ImageOff size={17} className="text-gray-400" />}
    </div>;
}

function Countdown({ until, now }) {
    const seconds = Math.max(0, Math.ceil((new Date(until).getTime() - now) / 1000));
    if (!seconds) return <span className="text-gray-400">Menunggu pemeriksaan</span>;
    const time = [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60].map((n) => String(n).padStart(2, "0")).join(":");
    return <span className="inline-flex items-center gap-1.5 font-mono font-semibold tabular-nums text-[#374673] dark:text-blue-200"><Clock3 size={13} />{time}</span>;
}

function ProductPicker({ store, onClose, onSave }) {
    const [selected, setSelected] = useState(() => new Set(store.items.filter((i) => i.selected).map((i) => i.item_id)));
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const [catalog, setCatalog] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        const timer = setTimeout(() => axios.get(`/api/shopee-boost/stores/${store.id}/products`, { params: { search, page }, signal: controller.signal })
            .then(({ data }) => { setCatalog(data); setError(""); })
            .catch((e) => { if (!axios.isCancel(e)) setError(errorText(e)); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); }), 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [store.id, search, page]);
    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        const key = (e) => { if (e.key === "Escape" && !saving) onClose(); };
        document.addEventListener("keydown", key);
        return () => { document.body.style.overflow = previous; document.removeEventListener("keydown", key); };
    }, [onClose, saving]);
    const submit = async () => {
        setSaving(true); setError("");
        try { await onSave([...selected]); onClose(); } catch (e) { setError(errorText(e)); } finally { setSaving(false); }
    };
    return createPortal(<div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-3 backdrop-blur-sm" onClick={() => { if (!saving) onClose(); }}>
        <section role="dialog" aria-modal="true" aria-labelledby="boost-picker-title" className="flex max-h-[90dvh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-slate-800 dark:text-slate-100" onClick={(e) => e.stopPropagation()}>
            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 p-5 dark:border-slate-700">
                <div><h2 id="boost-picker-title" className="text-base font-bold">Pilih produk tetap</h2><p className="mt-1 text-xs text-gray-500">{store.name} / {selected.size} dari 5 produk dipilih</p></div>
                <button type="button" className={secondary} disabled={saving} aria-label="Tutup" onClick={onClose}><X size={17} /></button>
            </header>
            <div className="shrink-0 px-5 pt-4"><div className="relative"><Search size={16} className="absolute left-3 top-3 text-gray-400" /><input autoFocus aria-label="Cari produk" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Cari nama produk, SKU, atau ID..." className="w-full rounded-md border border-gray-200 bg-transparent py-2.5 pl-9 pr-3 text-sm focus:border-[#374673] focus:outline-none dark:border-slate-600" /></div></div>
            {error && <p role="alert" className="mx-5 mt-3 rounded-md bg-red-50 p-3 text-xs text-red-700">{error}</p>}
            <div className="min-h-0 flex-1 overflow-y-auto p-5" aria-busy={loading}>
                {loading ? <div className="flex h-40 items-center justify-center"><LoaderCircle className="animate-spin text-[#374673]" /></div> : !catalog?.data?.length ? <p className="py-12 text-center text-sm text-gray-500">Tidak ada produk aktif yang cocok.</p> : <div className="divide-y divide-gray-100 dark:divide-slate-700">{catalog.data.map((item) => <label key={item.item_id} className="flex cursor-pointer items-center gap-3 py-3">
                    <input type="checkbox" className="h-4 w-4 shrink-0 cursor-pointer accent-[#374673]" checked={selected.has(item.item_id)} disabled={saving || (!selected.has(item.item_id) && selected.size >= 5)} onChange={() => setSelected((old) => { const next = new Set(old); if (next.has(item.item_id)) next.delete(item.item_id); else if (next.size < 5) next.add(item.item_id); return next; })} />
                    <ProductImage src={item.image} /><div className="min-w-0"><p className="line-clamp-2 text-sm font-semibold">{item.name}</p><p className="mt-1 break-all text-xs text-gray-400">{item.sku || item.item_id}</p></div>
                </label>)}</div>}
            </div>
            <footer className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-gray-100 p-4 dark:border-slate-700">
                <div className="flex items-center gap-2"><button type="button" aria-label="Halaman sebelumnya" className={secondary} disabled={loading || page <= 1} onClick={() => setPage((p) => p - 1)}><ChevronLeft size={15} /></button><span className="text-xs">{page} / {catalog?.last_page || 1}</span><button type="button" aria-label="Halaman berikutnya" className={secondary} disabled={loading || page >= (catalog?.last_page || 1)} onClick={() => setPage((p) => p + 1)}><ChevronRight size={15} /></button></div>
                <button type="button" disabled={saving} onClick={submit} className={primary}>{saving ? <LoaderCircle size={15} className="animate-spin" /> : <Check size={15} />}Simpan pilihan</button>
            </footer>
        </section>
    </div>, document.body);
}

export default function ShopeeBoost() {
    const [stores, setStores] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(null);
    const [filter, setFilter] = useState("all");
    const [picker, setPicker] = useState(null);
    const [now, setNow] = useState(Date.now());
    const fetching = useRef(false);
    const load = useCallback(async () => {
        if (fetching.current) return;
        fetching.current = true;
        try { const { data } = await axios.get("/api/shopee-boost"); setStores(data.stores); setError(""); }
        catch (e) { setError(errorText(e)); }
        finally { setLoading(false); fetching.current = false; }
    }, []);
    useEffect(() => {
        load();
        const poll = setInterval(() => { if (!document.hidden) load(); }, 30000);
        const tick = setInterval(() => setNow(Date.now()), 1000);
        const focus = () => { if (!document.hidden) load(); };
        document.addEventListener("visibilitychange", focus);
        return () => { clearInterval(poll); clearInterval(tick); document.removeEventListener("visibilitychange", focus); };
    }, [load]);
    const save = async (store, ids, enabled) => {
        const { data } = await axios.put(`/api/shopee-boost/stores/${store.id}`, { item_ids: ids, enabled });
        setNotice(data.message); await load();
    };
    const action = async (store, callback) => {
        setBusy(store.id); setError(""); setNotice("");
        try { await callback(); } catch (e) { setError(errorText(e)); } finally { setBusy(null); }
    };
    const selectedIds = (store) => store.items.filter((i) => i.selected).map((i) => i.item_id);
    return <AppLayout isBlurred={!!picker}>
        <div className="space-y-5 text-gray-800 dark:text-slate-100">
            <header className="flex flex-wrap items-center justify-between gap-4"><div><h1 className="text-2xl font-bold">Naikkan Produk Shopee</h1><p className="mt-1 text-sm text-gray-500">{stores.length} toko / {stores.filter((s) => s.enabled).length} otomatisasi aktif</p></div><div className="w-full sm:w-64"><FinesheetSelect value={filter} onChange={setFilter} ariaLabel="Filter toko" icon="storefront" options={[{ value: "all", label: "Semua toko Shopee" }, ...stores.map((s) => ({ value: String(s.id), label: s.name }))]} /></div></header>
            {error && <div role="alert" className="flex items-center justify-between gap-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700"><span>{error}</span><button onClick={load} className={secondary}><RefreshCw size={14} />Coba lagi</button></div>}
            {notice && <div role="status" className="flex items-center justify-between gap-3 rounded-md border border-blue-100 bg-blue-50 p-3 text-xs text-[#374673]"><span>{notice}</span><button aria-label="Tutup pesan" onClick={() => setNotice("")}><X size={16} /></button></div>}
            {loading ? <div className="flex h-48 items-center justify-center gap-2 text-sm text-gray-500"><LoaderCircle size={20} className="animate-spin" />Memuat produk...</div> : !stores.length ? <div className="border-y border-gray-200 py-16 text-center"><p className="font-semibold">Belum ada toko Shopee terhubung</p><a href="#/stores" className={`${primary} mt-4`}><Plus size={15} />Hubungkan toko</a></div> : stores.filter((s) => filter === "all" || String(s.id) === filter).map((store) => <section key={store.id} className="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-4 dark:border-slate-700 sm:px-5"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><span className="rounded bg-orange-50 px-2 py-1 text-[10px] font-bold text-orange-600">SHOPEE</span><h2 className="break-words text-base font-bold">{store.name}</h2><span className={`rounded px-2 py-1 text-[10px] font-semibold ${store.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>{store.enabled ? "Otomatis aktif" : "Dijeda"}</span></div><p className="mt-2 text-xs text-gray-400">Terakhir diperiksa: {date(store.checked_at)}</p></div>
                    <div className="flex flex-wrap gap-2"><button className={secondary} disabled={busy === store.id} onClick={() => action(store, async () => { const { data } = await axios.post(`/api/shopee-boost/stores/${store.id}/refresh`); setNotice(data.message); })}><RefreshCw size={14} className={busy === store.id ? "animate-spin" : ""} />Periksa status</button><button className={secondary} disabled={busy === store.id} onClick={() => setPicker(store)}><Plus size={14} />Pilih produk</button><button className={store.enabled ? secondary : primary} disabled={busy === store.id || (!store.enabled && !store.selected_count)} onClick={() => action(store, () => save(store, selectedIds(store), !store.enabled))}>{store.enabled ? <Pause size={14} /> : <Play size={14} />}{store.enabled ? "Jeda" : "Aktifkan"}</button></div>
                </header>
                <div className="flex flex-wrap gap-x-6 gap-y-2 bg-gray-50/70 px-4 py-3 text-xs dark:bg-slate-900/40 sm:px-5"><span>Slot aktif Shopee <strong>{store.checked_at ? `${store.active_count} / 5` : "Belum diperiksa"}</strong></span><span>Produk tetap <strong>{store.selected_count} / 5</strong></span><span className="text-gray-400">Pemeriksaan berikutnya: {store.enabled ? date(store.next_check_at) : "Dijeda"}</span></div>
                {store.error && <p role="alert" className="border-b border-red-100 bg-red-50 px-5 py-3 text-xs text-red-700">{store.error}</p>}
                {!store.items.length ? <div className="px-5 py-10 text-center text-sm text-gray-400">Belum ada produk dalam daftar.</div> : <div className="divide-y divide-gray-100 dark:divide-slate-700">{store.items.map((item) => {
                    const active = item.active_until && new Date(item.active_until).getTime() > now;
                    const label = active ? "Sedang dinaikkan" : item.error ? "Perlu diperiksa" : store.enabled && item.selected ? "Menunggu slot" : "Dijeda";
                    return <div key={item.item_id} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:flex-wrap sm:items-center sm:px-5">
                        <div className="flex min-w-0 flex-1 items-center gap-3"><ProductImage src={item.image} /><div className="min-w-0"><p className="line-clamp-2 text-sm font-semibold">{item.name}</p><p className="mt-1 break-all text-xs text-gray-400">{item.sku || item.item_id}</p><span className="mt-1 inline-block text-[10px] text-gray-500">{item.selected ? "Produk tetap" : "Di luar daftar otomatis"}</span></div></div>
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-3 sm:justify-end"><div className="min-w-36 text-xs"><span className={`inline-block rounded px-2 py-1 font-semibold ${active ? "bg-emerald-50 text-emerald-700" : item.error ? "bg-red-50 text-red-600" : "bg-gray-100 text-gray-500"}`}>{label}</span><div className="mt-2">{active ? <Countdown until={item.active_until} now={now} /> : <span className="text-gray-400">{item.selected ? "Putaran berikutnya" : "Tidak diulang"}</span>}</div></div><div className="w-40 text-xs text-gray-400"><p>Terakhir via Finesheet</p><p className="mt-1 text-gray-600 dark:text-slate-300">{date(item.last_boosted_at)}</p><p className="mt-1">{item.boost_count} kali berhasil</p></div>{item.selected && <button className={secondary} aria-label={`Hapus ${item.name} dari otomatisasi`} title="Hapus dari otomatisasi" disabled={busy === store.id} onClick={() => action(store, () => { const ids = selectedIds(store).filter((id) => id !== item.item_id); return save(store, ids, store.enabled && ids.length > 0); })}><Trash2 size={14} /></button>}</div>
                        {item.error && <p className="w-full text-xs text-red-600">{item.error}</p>}
                    </div>;
                })}</div>}
            </section>)}
        </div>
        {picker && <ProductPicker store={picker} onClose={() => setPicker(null)} onSave={(ids) => save(picker, ids, picker.enabled && ids.length > 0)} />}
    </AppLayout>;
}
