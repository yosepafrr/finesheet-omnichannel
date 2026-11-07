import React, { useEffect, useMemo, useState } from "react";
import axios from "axios";
import { AnimatePresence, motion } from "framer-motion";
import AppLayout from "../../views/components/layouts/AppLayout";

const HANDOVER_OPTIONS = [
    {
        value: "pickup",
        label: "Pickup kurir",
        description: "Kurir mengambil paket dari alamat toko.",
        icon: "local_shipping",
    },
    {
        value: "dropoff",
        label: "Antar ke gerai",
        description: "Paket diserahkan ke gerai atau drop point.",
        icon: "storefront",
    },
];

const LABEL_OPTIONS = [
    {
        value: "platform",
        label: "Template platform",
        description: "Gunakan dokumen resmi dari Shopee atau TikTok Shop.",
        icon: "receipt_long",
    },
    {
        value: "finesheet",
        label: "Template Finesheet",
        description: "Resi A6 ringkas dengan rincian paket dan catatan video unboxing.",
        icon: "description",
    },
];

function ChoiceGroup({ label, value, options, onChange, disabled }) {
    return (
        <fieldset className="min-w-0">
            <legend className="mb-2 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                {label}
            </legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const selected = value === option.value;
                    return (
                        <button
                            key={option.value}
                            type="button"
                            disabled={disabled}
                            onClick={() => onChange(option.value)}
                            className={`group flex min-h-[78px] items-start gap-3 rounded-lg border px-3.5 py-3 text-left transition duration-200 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 disabled:cursor-not-allowed disabled:opacity-60 ${selected
                                ? "border-[#304674] bg-[#304674]/5 shadow-sm dark:border-blue-400 dark:bg-blue-400/10"
                                : "border-slate-200 bg-white hover:-translate-y-0.5 hover:border-[#304674]/35 hover:shadow-sm dark:border-slate-700 dark:bg-slate-800 dark:hover:border-blue-400/40"
                            }`}
                            aria-pressed={selected}
                        >
                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors ${selected
                                ? "bg-[#304674] text-white dark:bg-blue-500"
                                : "bg-slate-100 text-slate-500 group-hover:bg-[#304674]/10 group-hover:text-[#304674] dark:bg-slate-700 dark:text-slate-300"
                            }`}>
                                <span className="material-symbols-rounded text-[20px]">{option.icon}</span>
                            </span>
                            <span className="min-w-0">
                                <span className="flex items-center gap-2 text-sm font-bold text-slate-800 dark:text-white">
                                    {option.label}
                                    {selected && <span className="material-symbols-rounded text-[17px] text-[#304674] dark:text-blue-300">check_circle</span>}
                                </span>
                                <span className="mt-1 block text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                                    {option.description}
                                </span>
                            </span>
                        </button>
                    );
                })}
            </div>
        </fieldset>
    );
}

function StoreLogo({ platform }) {
    const isShopee = String(platform).toLowerCase() === "shopee";
    return (
        <span className={`flex h-11 w-11 items-center justify-center rounded-lg border ${isShopee
            ? "border-orange-100 bg-orange-50 dark:border-orange-900/50 dark:bg-orange-950/30"
            : "border-slate-200 bg-slate-950 dark:border-slate-700"
        }`}>
            <img
                src={isShopee ? "/Marketplace-logo/shopee.png" : "/Marketplace-logo/tts.png"}
                alt=""
                className="h-7 w-7 object-contain"
            />
        </span>
    );
}

export default function FulfillmentSettings() {
    const [stores, setStores] = useState([]);
    const [original, setOriginal] = useState({});
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState({});
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        let active = true;
        axios.get("/api/fulfillment-settings")
            .then(({ data }) => {
                if (!active) return;
                const normalized = (data.stores || []).map((store) => ({
                    ...store,
                    fulfillment_handover_method: store.fulfillment_handover_method || "pickup",
                    shipping_label_template: store.shipping_label_template || "platform",
                }));
                setStores(normalized);
                setOriginal(Object.fromEntries(normalized.map((store) => [store.id, {
                    fulfillment_handover_method: store.fulfillment_handover_method,
                    shipping_label_template: store.shipping_label_template,
                }])));
            })
            .catch((error) => setNotice({
                type: "error",
                message: error.response?.data?.message || "Pengaturan fulfillment gagal dimuat.",
            }))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, []);

    const dirtyIds = useMemo(() => stores
        .filter((store) => {
            const saved = original[store.id];
            return !saved
                || saved.fulfillment_handover_method !== store.fulfillment_handover_method
                || saved.shipping_label_template !== store.shipping_label_template;
        })
        .map((store) => store.id), [stores, original]);

    const updateStore = (storeId, key, value) => {
        setStores((current) => current.map((store) => store.id === storeId ? { ...store, [key]: value } : store));
        setNotice(null);
    };

    const saveStore = async (store) => {
        setSaving((current) => ({ ...current, [store.id]: true }));
        try {
            const payload = {
                fulfillment_handover_method: store.fulfillment_handover_method,
                shipping_label_template: store.shipping_label_template,
            };
            const { data } = await axios.put(`/api/fulfillment-settings/stores/${store.id}`, payload);
            setOriginal((current) => ({ ...current, [store.id]: payload }));
            setNotice({ type: "success", message: data.message });
        } catch (error) {
            setNotice({
                type: "error",
                message: error.response?.data?.message
                    || Object.values(error.response?.data?.errors || {})?.[0]?.[0]
                    || `Pengaturan ${store.store_name} gagal disimpan.`,
            });
        } finally {
            setSaving((current) => ({ ...current, [store.id]: false }));
        }
    };

    const saveAll = async () => {
        for (const store of stores.filter((item) => dirtyIds.includes(item.id))) {
            await saveStore(store);
        }
    };

    return (
        <AppLayout>
            <div className="mx-auto w-full max-w-7xl pb-16">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-semibold text-[#304674] dark:text-blue-300">
                            <span className="material-symbols-rounded text-[19px]">package_2</span>
                            Operasional pesanan
                        </div>
                        <h1 className="mt-1 text-2xl font-bold text-slate-900 dark:text-white sm:text-3xl">Fulfillment Settings</h1>
                        <p className="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                            Tentukan metode serah terima dan format resi yang otomatis digunakan untuk setiap toko.
                        </p>
                    </div>
                    <button
                        type="button"
                        disabled={!dirtyIds.length || Object.values(saving).some(Boolean)}
                        onClick={saveAll}
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#263961] hover:shadow-md disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400 disabled:shadow-none dark:disabled:bg-slate-700"
                    >
                        <span className="material-symbols-rounded text-[19px]">save</span>
                        Simpan semua perubahan{dirtyIds.length ? ` (${dirtyIds.length})` : ""}
                    </button>
                </div>

                <AnimatePresence>
                    {notice && (
                        <motion.div
                            initial={{ opacity: 0, y: -6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -6 }}
                            className={`mb-5 flex items-center justify-between rounded-lg border px-4 py-3 text-sm ${notice.type === "error"
                                ? "border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                                : "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200"
                            }`}
                        >
                            <span className="font-medium">{notice.message}</span>
                            <button type="button" onClick={() => setNotice(null)} className="rounded p-1 hover:bg-black/5" aria-label="Tutup notifikasi">
                                <span className="material-symbols-rounded text-[18px]">close</span>
                            </button>
                        </motion.div>
                    )}
                </AnimatePresence>

                {loading ? (
                    <div className="space-y-3">
                        {[0, 1].map((item) => <div key={item} className="h-64 animate-pulse rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800" />)}
                    </div>
                ) : stores.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-16 text-center dark:border-slate-700 dark:bg-slate-800">
                        <span className="material-symbols-rounded text-4xl text-slate-300">store_off</span>
                        <h2 className="mt-3 font-bold text-slate-800 dark:text-white">Belum ada toko terhubung</h2>
                        <p className="mt-1 text-sm text-slate-500">Hubungkan toko terlebih dahulu untuk mengatur fulfillment.</p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {stores.map((store, index) => {
                            const dirty = dirtyIds.includes(store.id);
                            return (
                                <motion.section
                                    key={store.id}
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.22, delay: index * 0.04 }}
                                    className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800"
                                >
                                    <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-4 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <StoreLogo platform={store.platform} />
                                            <div className="min-w-0">
                                                <h2 className="truncate font-bold text-slate-900 dark:text-white">{store.store_name}</h2>
                                                <p className="mt-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                                    {store.platform === "Tiktokshop" ? "TikTok Shop" : store.platform}
                                                </p>
                                            </div>
                                            {dirty && <span className="rounded bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Belum disimpan</span>}
                                        </div>
                                        <button
                                            type="button"
                                            disabled={!dirty || saving[store.id]}
                                            onClick={() => saveStore(store)}
                                            className="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-[#304674] transition hover:border-[#304674]/40 hover:bg-[#304674]/5 disabled:cursor-not-allowed disabled:text-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-blue-300 dark:disabled:text-slate-600"
                                        >
                                            <span className={`material-symbols-rounded text-[18px] ${saving[store.id] ? "animate-spin" : ""}`}>{saving[store.id] ? "progress_activity" : "save"}</span>
                                            Simpan toko
                                        </button>
                                    </div>
                                    <div className="grid gap-6 p-4 sm:p-5 lg:grid-cols-2">
                                        <ChoiceGroup
                                            label="Metode serah terima otomatis"
                                            value={store.fulfillment_handover_method}
                                            options={HANDOVER_OPTIONS}
                                            onChange={(value) => updateStore(store.id, "fulfillment_handover_method", value)}
                                            disabled={saving[store.id]}
                                        />
                                        <ChoiceGroup
                                            label="Template resi"
                                            value={store.shipping_label_template}
                                            options={LABEL_OPTIONS}
                                            onChange={(value) => updateStore(store.id, "shipping_label_template", value)}
                                            disabled={saving[store.id]}
                                        />
                                    </div>
                                </motion.section>
                            );
                        })}
                    </div>
                )}

                <div className="mt-5 flex items-start gap-3 rounded-lg border border-blue-100 bg-blue-50/70 px-4 py-3 text-xs leading-relaxed text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-200">
                    <span className="material-symbols-rounded mt-0.5 text-[18px]">info</span>
                    <p>Ketersediaan pickup atau drop-off tetap mengikuti layanan yang diberikan platform pada setiap pesanan. Template Finesheet dicetak dalam ukuran A6 dan tidak mengubah data resi di marketplace.</p>
                </div>
            </div>
        </AppLayout>
    );
}
