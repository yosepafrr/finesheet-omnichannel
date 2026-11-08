import React, { useEffect, useMemo, useState } from "react";
import axios from "axios";
import { AnimatePresence, motion } from "framer-motion";
import AppLayout from "../../views/components/layouts/AppLayout";

const DEFAULTS = {
    fulfillment_handover_method: "pickup",
    shipping_label_template: "platform",
    shopee_thermal_printing: false,
    print_shipping_label: true,
    print_packing_list: false,
    shipping_label_size: "a6",
    packing_list_size: "a6",
    printing_layout: "separate",
    printing_file_format: "pdf",
};

const SETTING_FIELDS = Object.keys(DEFAULTS);
const DOCUMENTS = [
    { key: "print_shipping_label", sizeKey: "shipping_label_size", label: "Label pengiriman", description: "Resi untuk ditempel pada paket.", icon: "local_shipping" },
    { key: "print_packing_list", sizeKey: "packing_list_size", label: "Daftar pengemasan", description: "Rincian barang untuk proses packing.", icon: "inventory_2" },
];

const HANDOVER_OPTIONS = [
    { value: "pickup", label: "Pickup kurir", description: "Kurir mengambil paket dari alamat toko.", icon: "local_shipping" },
    { value: "dropoff", label: "Antar ke gerai", description: "Paket diserahkan ke gerai atau drop point.", icon: "storefront" },
];

const LABEL_OPTIONS = [
    { value: "platform", label: "Template platform", description: "Gunakan label resmi Shopee atau TikTok Shop.", icon: "receipt_long" },
    { value: "finesheet", label: "Template Finesheet", description: "Label internal dengan rincian pengemasan.", icon: "description" },
];

const normalizeStore = (store) => ({
    ...store,
    ...DEFAULTS,
    ...Object.fromEntries(Object.entries(store).filter(([, value]) => value !== null && value !== undefined)),
    print_shipping_label: store.print_shipping_label ?? true,
    print_packing_list: store.print_packing_list ?? false,
    shopee_thermal_printing: store.shopee_thermal_printing ?? false,
    shipping_label_size: ["a5", "a6"].includes(store.shipping_label_size) ? store.shipping_label_size : "a6",
    packing_list_size: ["a5", "a6"].includes(store.packing_list_size) ? store.packing_list_size : "a6",
});

const settingsOf = (store) => Object.fromEntries(SETTING_FIELDS.map((key) => [key, store[key]]));
const errorMessage = (error, fallback) => error.response?.data?.message
    || Object.values(error.response?.data?.errors || {})?.[0]?.[0]
    || fallback;

function ChoiceGroup({ label, value, options, onChange, disabled }) {
    return (
        <fieldset className="min-w-0">
            <legend className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const selected = value === option.value;
                    return (
                        <button
                            key={option.value}
                            type="button"
                            disabled={disabled}
                            onClick={() => onChange(option.value)}
                            className={`flex min-h-[76px] items-start gap-3 rounded-lg border px-3.5 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-[#304674]/20 disabled:cursor-not-allowed disabled:opacity-60 ${selected
                                ? "border-[#304674] bg-[#304674]/5 dark:border-blue-400 dark:bg-blue-400/10"
                                : "border-slate-200 bg-white hover:border-[#304674]/40 dark:border-slate-700 dark:bg-slate-800"}`}
                            aria-pressed={selected}
                        >
                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${selected ? "bg-[#304674] text-white dark:bg-blue-500" : "bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300"}`}>
                                <span className="material-symbols-rounded text-[20px]">{option.icon}</span>
                            </span>
                            <span className="min-w-0">
                                <span className="flex items-center gap-1.5 text-sm font-bold text-slate-800 dark:text-white">{option.label}{selected && <span className="material-symbols-rounded text-[17px] text-[#304674] dark:text-blue-300">check_circle</span>}</span>
                                <span className="mt-1 block text-xs leading-relaxed text-slate-500 dark:text-slate-400">{option.description}</span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </fieldset>
    );
}

function TiktokPrintingSettings({ value, onChange, disabled, compact = false }) {
    const documentCount = DOCUMENTS.filter((item) => value[item.key]).length;
    const toggleDocument = (document, checked) => {
        onChange(document.key, checked);
        const otherDocument = DOCUMENTS.find((item) => item.key !== document.key);
        if (checked && value[otherDocument.key]) onChange(document.sizeKey, value[otherDocument.sizeKey]);
    };
    const changeSize = (document, size) => {
        onChange(document.sizeKey, size);
        if (DOCUMENTS.every((item) => value[item.key])) {
            const otherDocument = DOCUMENTS.find((item) => item.key !== document.key);
            onChange(otherDocument.sizeKey, size);
        }
    };
    return (
        <section className="min-w-0">
            <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Pengaturan pencetakan</h3>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Pilih minimal satu dokumen. Format keluaran: PDF.</p>
                </div>
                <span className="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">{documentCount} dokumen</span>
            </div>
            <div className={`grid gap-2 ${compact ? "" : "md:grid-cols-2"}`}>
                {DOCUMENTS.map((document) => {
                    const checked = Boolean(value[document.key]);
                    return (
                        <div key={document.key} className={`rounded-lg border p-3 transition ${checked ? "border-[#304674]/60 bg-[#304674]/[0.03] dark:border-blue-400/50 dark:bg-blue-400/5" : "border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-900/20"}`}>
                            <div className="flex items-start gap-3">
                                <button
                                    type="button"
                                    role="checkbox"
                                    aria-checked={checked}
                                    disabled={disabled}
                                    onClick={() => toggleDocument(document, !checked)}
                                    className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border transition ${checked ? "border-[#304674] bg-[#304674] text-white dark:border-blue-400 dark:bg-blue-500" : "border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-800"}`}
                                >
                                    {checked && <span className="material-symbols-rounded text-[15px]">check</span>}
                                </button>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-1.5 text-sm font-bold text-slate-800 dark:text-white"><span className="material-symbols-rounded text-[18px] text-slate-500">{document.icon}</span>{document.label}</div>
                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{document.description}</p>
                                </div>
                            </div>
                            <label className="mt-3 flex items-center justify-between gap-3 border-t border-slate-200/80 pt-2.5 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">
                                Ukuran kertas
                                <select disabled={disabled || !checked} value={value[document.sizeKey]} onChange={(event) => changeSize(document, event.target.value)} className="rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs font-bold text-slate-700 outline-none focus:border-[#304674] disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-white">
                                    <option value="a6">A6</option><option value="a5">A5</option>
                                </select>
                            </label>
                        </div>
                    );
                })}
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {value.shipping_label_template === "finesheet" ? <label className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tata letak
                    <select disabled={disabled} value={value.printing_layout} onChange={(event) => onChange("printing_layout", event.target.value)} className="mt-2 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold normal-case tracking-normal text-slate-700 outline-none focus:border-[#304674] dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                        <option value="separate">Setiap dokumen di halaman terpisah</option>
                        <option value="combined">Gabungkan label dan daftar pengemasan</option>
                    </select>
                </label> : <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-700 dark:bg-slate-900/20"><div className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tata letak</div><p className="mt-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Mengikuti format resmi TikTok Shop</p></div>}
                <div className="rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2.5 text-xs leading-relaxed text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-200">
                    Pada template platform, pilihan ini langsung meminta dokumen resmi TikTok Shop. Jika keduanya aktif, satu PDF memakai ukuran kertas yang sama.
                </div>
            </div>
        </section>
    );
}

function ShopeeThermalSettings({ value, onChange, disabled }) {
    const enabled = Boolean(value.shopee_thermal_printing);
    return (
        <section className="rounded-lg border border-slate-200 bg-slate-50/60 px-4 py-4 dark:border-slate-700 dark:bg-slate-900/20">
            <div className="flex items-center justify-between gap-5">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-white"><span className="material-symbols-rounded text-[20px] text-orange-500">print</span>Cetak Mode Thermal</div>
                    <p className="mt-1.5 max-w-3xl text-xs leading-relaxed text-slate-500 dark:text-slate-400">Aktifkan untuk memprioritaskan label A6 thermal pada semua jasa kirim Shopee. Jika jasa kirim tidak mendukungnya, tipe rekomendasi Shopee tetap digunakan.</p>
                </div>
                <button type="button" role="switch" aria-checked={enabled} disabled={disabled} onClick={() => onChange("shopee_thermal_printing", !enabled)} className={`relative h-7 w-12 shrink-0 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-orange-400/30 disabled:cursor-not-allowed disabled:opacity-60 ${enabled ? "bg-emerald-500" : "bg-slate-300 dark:bg-slate-600"}`}>
                    <span className={`absolute top-1 h-5 w-5 rounded-full bg-white shadow-sm transition-transform ${enabled ? "translate-x-6" : "translate-x-1"}`} />
                </button>
            </div>
        </section>
    );
}

function SettingsForm({ value, onChange, disabled, compact = false, platform = "Tiktokshop" }) {
    const showShopee = platform === "Shopee" || platform === "mixed";
    const showTiktok = platform !== "Shopee";
    return (
        <div className="space-y-6">
            <div className={`grid gap-6 ${compact ? "" : "lg:grid-cols-2"}`}>
                <ChoiceGroup label="Metode serah terima otomatis" value={value.fulfillment_handover_method} options={HANDOVER_OPTIONS} onChange={(next) => onChange("fulfillment_handover_method", next)} disabled={disabled} />
                <ChoiceGroup label="Template resi" value={value.shipping_label_template} options={LABEL_OPTIONS} onChange={(next) => onChange("shipping_label_template", next)} disabled={disabled} />
            </div>
            <div className="space-y-4 border-t border-slate-100 pt-5 dark:border-slate-700">
                {platform === "mixed" && <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/20 dark:text-amber-200">Toko terpilih mencakup Shopee dan TikTok Shop. Masing-masing akan memakai pengaturan pencetakan sesuai platformnya.</div>}
                {showShopee && <ShopeeThermalSettings value={value} onChange={onChange} disabled={disabled} />}
                {showTiktok && <TiktokPrintingSettings value={value} onChange={onChange} disabled={disabled} compact={compact} />}
            </div>
        </div>
    );
}

function StoreLogo({ platform }) {
    const isShopee = String(platform).toLowerCase() === "shopee";
    return <span className={`flex h-11 w-11 items-center justify-center rounded-lg border ${isShopee ? "border-orange-100 bg-orange-50 dark:border-orange-900/50 dark:bg-orange-950/30" : "border-slate-200 bg-slate-950 dark:border-slate-700"}`}><img src={isShopee ? "/Marketplace-logo/shopee.png" : "/Marketplace-logo/tts.png"} alt="" className="h-7 w-7 object-contain" /></span>;
}

export default function FulfillmentSettings() {
    const [stores, setStores] = useState([]);
    const [original, setOriginal] = useState({});
    const [selectedIds, setSelectedIds] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState({});
    const [notice, setNotice] = useState(null);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [bulkDraft, setBulkDraft] = useState(DEFAULTS);
    const [bulkSaving, setBulkSaving] = useState(false);

    useEffect(() => {
        let active = true;
        axios.get("/api/fulfillment-settings")
            .then(({ data }) => {
                if (!active) return;
                const normalized = (data.stores || []).map(normalizeStore);
                setStores(normalized);
                setOriginal(Object.fromEntries(normalized.map((store) => [store.id, settingsOf(store)])));
            })
            .catch((error) => setNotice({ type: "error", message: errorMessage(error, "Pengaturan fulfillment gagal dimuat.") }))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, []);

    const dirtyIds = useMemo(() => stores.filter((store) => JSON.stringify(settingsOf(store)) !== JSON.stringify(original[store.id])).map((store) => store.id), [stores, original]);
    const allSelected = stores.length > 0 && selectedIds.length === stores.length;
    const selectedPlatform = useMemo(() => {
        const platforms = [...new Set(stores.filter((store) => selectedIds.includes(store.id)).map((store) => store.platform))];
        return platforms.length === 1 ? platforms[0] : "mixed";
    }, [stores, selectedIds]);
    const updateStore = (storeId, key, value) => { setStores((current) => current.map((store) => store.id === storeId ? { ...store, [key]: value } : store)); setNotice(null); };
    const toggleSelected = (storeId) => setSelectedIds((current) => current.includes(storeId) ? current.filter((id) => id !== storeId) : [...current, storeId]);

    const saveStore = async (store) => {
        setSaving((current) => ({ ...current, [store.id]: true }));
        try {
            const { data } = await axios.put(`/api/fulfillment-settings/stores/${store.id}`, settingsOf(store));
            const saved = normalizeStore(data.store);
            setStores((current) => current.map((item) => item.id === store.id ? saved : item));
            setOriginal((current) => ({ ...current, [store.id]: settingsOf(saved) }));
            setNotice({ type: "success", message: data.message });
        } catch (error) {
            setNotice({ type: "error", message: errorMessage(error, `Pengaturan ${store.store_name} gagal disimpan.`) });
        } finally { setSaving((current) => ({ ...current, [store.id]: false })); }
    };

    const saveAll = async () => {
        for (const store of stores.filter((item) => dirtyIds.includes(item.id))) await saveStore(store);
    };

    const openBulk = () => {
        const source = stores.find((store) => selectedIds.includes(store.id));
        setBulkDraft(source ? settingsOf(source) : { ...DEFAULTS });
        setBulkOpen(true);
        setNotice(null);
    };

    const saveBulk = async () => {
        setBulkSaving(true);
        try {
            const { data } = await axios.put("/api/fulfillment-settings/stores", { store_ids: selectedIds, ...bulkDraft });
            const savedById = Object.fromEntries((data.stores || []).map((store) => [store.id, normalizeStore(store)]));
            setStores((current) => current.map((store) => savedById[store.id] || store));
            setOriginal((current) => ({ ...current, ...Object.fromEntries(Object.values(savedById).map((store) => [store.id, settingsOf(store)])) }));
            setBulkOpen(false);
            setNotice({ type: "success", message: data.message });
        } catch (error) { setNotice({ type: "error", message: errorMessage(error, "Edit massal gagal disimpan.") }); }
        finally { setBulkSaving(false); }
    };

    return (
        <AppLayout>
            <div className="mx-auto w-full max-w-7xl pb-16">
                <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div><div className="flex items-center gap-2 text-sm font-semibold text-[#304674] dark:text-blue-300"><span className="material-symbols-rounded text-[19px]">package_2</span>Operasional pesanan</div><h1 className="mt-1 text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Fulfillment Settings</h1><p className="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Atur serah terima, template resi, dan dokumen pencetakan untuk setiap toko.</p></div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" disabled={!selectedIds.length} onClick={openBulk} className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[#304674]/30 bg-white px-4 py-2.5 text-sm font-bold text-[#304674] transition hover:bg-[#304674]/5 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-slate-800 dark:text-blue-300"><span className="material-symbols-rounded text-[19px]">edit_note</span>Edit massal{selectedIds.length ? ` (${selectedIds.length})` : ""}</button>
                        <button type="button" disabled={!dirtyIds.length || Object.values(saving).some(Boolean)} onClick={saveAll} className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#263961] disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400 dark:disabled:bg-slate-700"><span className="material-symbols-rounded text-[19px]">save</span>Simpan perubahan{dirtyIds.length ? ` (${dirtyIds.length})` : ""}</button>
                    </div>
                </div>

                <AnimatePresence>{notice && <motion.div initial={{ opacity: 0, y: -6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className={`mb-5 flex items-center justify-between rounded-lg border px-4 py-3 text-sm ${notice.type === "error" ? "border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200" : "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"}`}><span className="font-medium">{notice.message}</span><button type="button" onClick={() => setNotice(null)}><span className="material-symbols-rounded text-[18px]">close</span></button></motion.div>}</AnimatePresence>

                {!loading && stores.length > 0 && <div className="mb-3 flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-800"><label className="flex cursor-pointer items-center gap-3 text-sm font-bold text-slate-700 dark:text-slate-200"><input type="checkbox" checked={allSelected} onChange={() => setSelectedIds(allSelected ? [] : stores.map((store) => store.id))} className="h-4 w-4 rounded border-slate-300 text-[#304674] focus:ring-[#304674]" />Pilih semua toko</label><span className="text-xs font-medium text-slate-500">{selectedIds.length} dari {stores.length} dipilih</span></div>}

                {loading ? <div className="space-y-3">{[0, 1].map((item) => <div key={item} className="h-72 animate-pulse rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800" />)}</div>
                    : stores.length === 0 ? <div className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-800"><span className="material-symbols-rounded text-4xl text-slate-300">store_off</span><h2 className="mt-3 font-bold text-slate-800 dark:text-white">Belum ada toko terhubung</h2></div>
                        : <div className="space-y-4">{stores.map((store, index) => {
                            const dirty = dirtyIds.includes(store.id); const selected = selectedIds.includes(store.id);
                            return <motion.section key={store.id} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2, delay: index * 0.03 }} className={`overflow-hidden rounded-lg border bg-white shadow-sm dark:bg-slate-800 ${selected ? "border-[#304674]/60 ring-2 ring-[#304674]/10 dark:border-blue-400/60" : "border-slate-200 dark:border-slate-700"}`}>
                                <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                    <div className="flex min-w-0 items-center gap-3"><input type="checkbox" checked={selected} onChange={() => toggleSelected(store.id)} aria-label={`Pilih ${store.store_name}`} className="h-4 w-4 rounded border-slate-300 text-[#304674] focus:ring-[#304674]" /><StoreLogo platform={store.platform} /><div className="min-w-0"><h2 className="truncate font-bold text-slate-900 dark:text-white">{store.store_name}</h2><p className="mt-0.5 text-xs font-medium text-slate-500">{store.platform === "Tiktokshop" ? "TikTok Shop" : store.platform}</p></div>{dirty && <span className="rounded bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Belum disimpan</span>}</div>
                                    <button type="button" disabled={!dirty || saving[store.id]} onClick={() => saveStore(store)} className="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-slate-200 px-3.5 py-2 text-xs font-bold text-[#304674] transition hover:bg-[#304674]/5 disabled:cursor-not-allowed disabled:text-slate-300 dark:border-slate-600 dark:text-blue-300"><span className={`material-symbols-rounded text-[18px] ${saving[store.id] ? "animate-spin" : ""}`}>{saving[store.id] ? "progress_activity" : "save"}</span>Simpan toko</button>
                                </div>
                                <div className="p-4 sm:p-5"><SettingsForm value={store} onChange={(key, value) => updateStore(store.id, key, value)} disabled={saving[store.id]} platform={store.platform} /></div>
                            </motion.section>;
                        })}</div>}

                <div className="mt-5 flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50/70 px-4 py-3 text-xs leading-relaxed text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-200"><span className="material-symbols-rounded mt-0.5 text-[18px]">info</span><p>TikTok Shop menyediakan label dan daftar pengemasan melalui dokumen paket. Untuk Shopee, mode thermal hanya dipilih bila tipe tersebut tersedia; jika tidak, Finesheet mengikuti tipe rekomendasi Shopee.</p></div>
            </div>

            <AnimatePresence>{bulkOpen && <motion.div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onMouseDown={(event) => event.target === event.currentTarget && !bulkSaving && setBulkOpen(false)}>
                <motion.div initial={{ opacity: 0, scale: 0.98, y: 12 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.98 }} className="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-2xl dark:bg-slate-800">
                    <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700"><div><h2 className="text-lg font-bold text-slate-900 dark:text-white">Edit massal fulfillment</h2><p className="mt-1 text-xs text-slate-500">Pengaturan ini akan diterapkan ke {selectedIds.length} toko terpilih.</p></div><button type="button" disabled={bulkSaving} onClick={() => setBulkOpen(false)} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700"><span className="material-symbols-rounded">close</span></button></div>
                    <div className="max-h-[calc(92vh-140px)] overflow-y-auto p-5"><SettingsForm value={bulkDraft} onChange={(key, value) => setBulkDraft((current) => ({ ...current, [key]: value }))} disabled={bulkSaving} compact platform={selectedPlatform} /></div>
                    <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-700"><button type="button" disabled={bulkSaving} onClick={() => setBulkOpen(false)} className="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600 dark:border-slate-600 dark:text-slate-300">Batal</button><button type="button" disabled={bulkSaving} onClick={saveBulk} className="inline-flex items-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60"><span className={`material-symbols-rounded text-[18px] ${bulkSaving ? "animate-spin" : ""}`}>{bulkSaving ? "progress_activity" : "done_all"}</span>Terapkan dan simpan</button></div>
                </motion.div>
            </motion.div>}</AnimatePresence>
        </AppLayout>
    );
}
