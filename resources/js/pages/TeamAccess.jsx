import React, { useEffect, useMemo, useState } from "react";
import axios from "axios";
import toast from "react-hot-toast";
import { createPortal } from "react-dom";
import { AnimatePresence, motion } from "framer-motion";
import { Boxes, Clock3, Eye, Landmark, PackageCheck, Pencil, Plus, Settings2, ShieldCheck, Trash2, UserCog, X } from "lucide-react";
import AppLayout from "../../views/components/layouts/AppLayout";
import { useAccess } from "../contexts/AccessContext";

const EMPTY_FORM = {
    name: "",
    preset: "order_operations",
    layout: "order_operations",
    permissions: [],
    is_active: true,
};

const LAYOUT_META = {
    order_operations: {
        label: "Fulfillment",
        description: "Kelola pesanan, atur pengiriman, dan cetak resi tanpa melihat HPP, escrow, atau profit.",
        icon: PackageCheck,
    },
    product_operations: {
        label: "Operasional produk",
        description: "Kelola katalog, stok, dan sinkronisasi produk marketplace.",
        icon: Boxes,
    },
    finance_operations: {
        label: "Finance",
        description: "Pantau profit serta kelola transaksi supplier dan cashflow tanpa akses operasional lain.",
        icon: Landmark,
    },
    read_only: {
        label: "Read only",
        description: "Akses pemantauan tanpa izin untuk mengubah data operasional.",
        icon: Eye,
    },
    custom: {
        label: "Custom",
        description: "Susun sendiri akses halaman dan tindakan untuk operator ini.",
        icon: Settings2,
    },
};

const getLayoutMeta = (layout) => LAYOUT_META[layout] || LAYOUT_META.custom;

export default function TeamAccess() {
    const access = useAccess();
    const [data, setData] = useState({ profiles: [], permissions: {}, presets: {}, activity: [] });
    const [loading, setLoading] = useState(true);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);
    const [saving, setSaving] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const response = await axios.get("/api/access-profiles");
            setData(response.data);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal memuat hak akses.");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, []);

    const permissionGroups = useMemo(() => Object.entries(data.permissions).reduce((groups, [key, permission]) => {
        groups[permission.group] = [...(groups[permission.group] || []), { key, ...permission }];
        return groups;
    }, {}), [data.permissions]);

    const openCreate = () => {
        const preset = data.presets.order_operations;
        setEditingId(null);
        setForm({ ...EMPTY_FORM, layout: preset?.layout || "order_operations", permissions: preset?.permissions || [] });
        setModalOpen(true);
    };

    const openEdit = (profile) => {
        setEditingId(profile.id);
        setForm({
            name: profile.name,
            preset: "custom",
            layout: profile.layout,
            permissions: profile.permissions || [],
            is_active: profile.is_active,
        });
        setModalOpen(true);
    };

    const selectPreset = (presetKey) => {
        const preset = data.presets[presetKey];
        setForm((current) => ({
            ...current,
            preset: presetKey,
            layout: preset?.layout || "custom",
            permissions: preset?.permissions || current.permissions,
        }));
    };

    const togglePermission = (permission) => {
        setForm((current) => ({
            ...current,
            preset: "custom",
            permissions: current.permissions.includes(permission)
                ? current.permissions.filter((item) => item !== permission)
                : [...current.permissions, permission],
        }));
    };

    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        try {
            const payload = {
                name: form.name,
                layout: form.layout,
                permissions: form.permissions,
                is_active: form.is_active,
            };
            if (editingId) await axios.put(`/api/access-profiles/${editingId}`, payload);
            else await axios.post("/api/access-profiles", payload);

            toast.success(editingId ? "Profil operator diperbarui." : "Profil operator dibuat.");
            setModalOpen(false);
            await Promise.all([load(), access.refresh()]);
        } catch (error) {
            const errors = error.response?.data?.errors;
            toast.error(errors ? Object.values(errors)[0]?.[0] : (error.response?.data?.message || "Gagal menyimpan profil."));
        } finally {
            setSaving(false);
        }
    };

    const remove = async (profile) => {
        if (!window.confirm(`Hapus profil ${profile.name}? Perangkat yang sedang memakainya akan diminta login ulang.`)) return;
        try {
            await axios.delete(`/api/access-profiles/${profile.id}`);
            toast.success("Profil operator dihapus.");
            await Promise.all([load(), access.refresh()]);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal menghapus profil.");
        }
    };

    return (
        <AppLayout isBlurred={modalOpen}>
            <div className="space-y-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Tim & Hak Akses</h1>
                        <p className="mt-1 text-sm text-slate-500">Atur mode operator yang dapat dipakai pada setiap perangkat.</p>
                    </div>
                    <button type="button" onClick={openCreate} disabled={loading} className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-wait disabled:opacity-55">
                        <Plus className="h-4 w-4" /> {loading ? "Memuat akses..." : "Tambah operator"}
                    </button>
                </header>

                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <div className="border-b border-slate-100 px-5 py-4 dark:border-slate-700">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-white"><UserCog className="h-4 w-4 text-[#304674]" />Profil operator</h2>
                    </div>
                    {loading ? (
                        <div className="space-y-3 p-5 animate-pulse"><div className="h-20 rounded bg-slate-100 dark:bg-slate-700" /><div className="h-20 rounded bg-slate-100 dark:bg-slate-700" /></div>
                    ) : data.profiles.length === 0 ? (
                        <div className="px-5 py-14 text-center"><ShieldCheck className="mx-auto h-8 w-8 text-slate-300" /><p className="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-200">Belum ada profil operator</p><p className="mt-1 text-xs text-slate-400">Semua perangkat tetap menggunakan mode Owner.</p></div>
                    ) : (
                        <div className="divide-y divide-slate-100 dark:divide-slate-700">
                            {data.profiles.map((profile) => (
                                <div key={profile.id} className="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2"><p className="font-bold text-slate-900 dark:text-white">{profile.name}</p><span className={`rounded px-2 py-0.5 text-[10px] font-bold ${profile.is_active ? "bg-emerald-50 text-emerald-700" : "bg-slate-100 text-slate-500"}`}>{profile.is_active ? "Aktif" : "Nonaktif"}</span></div>
                                        <p className="mt-1 text-xs text-slate-500">{profile.permissions.length} izin | Layout {getLayoutMeta(profile.layout).label}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => openEdit(profile)} title="Edit profil" className="flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:text-[#304674] dark:border-slate-600"><Pencil className="h-4 w-4" /></button>
                                        <button type="button" onClick={() => remove(profile)} title="Hapus profil" className="flex h-9 w-9 items-center justify-center rounded-md border border-rose-100 text-rose-500 hover:bg-rose-50 dark:border-rose-500/20"><Trash2 className="h-4 w-4" /></button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                    <div className="border-b border-slate-100 px-5 py-4 dark:border-slate-700"><h2 className="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-white"><Clock3 className="h-4 w-4 text-[#304674]" />Aktivitas akses terbaru</h2></div>
                    <div className="divide-y divide-slate-100 dark:divide-slate-700">
                        {data.activity.length === 0 && <p className="px-5 py-8 text-center text-xs text-slate-400">Belum ada aktivitas.</p>}
                        {data.activity.map((item) => <div key={item.id} className="flex items-center justify-between gap-4 px-5 py-3 text-xs"><div><p className="font-semibold text-slate-700 dark:text-slate-200">{item.action.replaceAll("_", " ")}</p><p className="mt-0.5 text-slate-400">{item.profile?.name || item.metadata?.profile_name || "Owner"}</p></div><time className="shrink-0 text-slate-400">{new Date(item.created_at).toLocaleString("id-ID")}</time></div>)}
                    </div>
                </section>
            </div>

            {typeof document !== "undefined" && createPortal(
                <AnimatePresence>
                    {modalOpen && <div className="fixed inset-0 z-[500] flex items-center justify-center p-4"><motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-slate-900/55 backdrop-blur-sm" onClick={() => setModalOpen(false)} /><motion.form initial={{ opacity: 0, scale: 0.97 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.97 }} onSubmit={submit} className="relative z-10 flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl dark:bg-slate-900">
                        <div className="flex items-start justify-between border-b border-slate-100 px-6 py-5 dark:border-slate-800"><div><h2 className="text-lg font-bold text-slate-900 dark:text-white">{editingId ? "Edit operator" : "Tambah operator"}</h2><p className="mt-1 text-xs text-slate-500">Tentukan tampilan dan tindakan yang diizinkan.</p></div><button type="button" onClick={() => setModalOpen(false)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
                        <div className="overflow-y-auto p-6">
                            <label className="text-xs font-bold text-slate-600 dark:text-slate-300">Nama operator</label>
                            <input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Contoh: Admin Gudang" className="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            <div className="mt-5 flex items-end justify-between gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-slate-600 dark:text-slate-300">Layout operator</label>
                                    <p className="mt-1 text-[11px] text-slate-400">Owner tetap menjadi mode utama. Pilih pengalaman kerja untuk operator ini.</p>
                                </div>
                            </div>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                {Object.entries(data.presets).map(([key, preset]) => {
                                    const meta = getLayoutMeta(preset.layout || key);
                                    const Icon = meta.icon;
                                    const selected = form.layout === (preset.layout || key) && (key !== "custom" || form.preset === "custom");

                                    return (
                                        <button
                                            type="button"
                                            key={key}
                                            onClick={() => selectPreset(key)}
                                            className={`flex min-h-24 items-start gap-3 rounded-lg border p-3.5 text-left transition ${selected ? "border-[#304674] bg-blue-50/70 shadow-sm dark:bg-blue-950/20" : "border-slate-200 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"}`}
                                        >
                                            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${selected ? "bg-[#304674] text-white" : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300"}`}>
                                                <Icon className="h-4 w-4" />
                                            </span>
                                            <span>
                                                <span className={`block text-sm font-bold ${selected ? "text-[#304674] dark:text-blue-300" : "text-slate-700 dark:text-slate-200"}`}>{meta.label}</span>
                                                <span className="mt-1 block text-[11px] leading-4 text-slate-500 dark:text-slate-400">{meta.description}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="mt-6 flex items-center justify-between border-b border-slate-100 pb-3 dark:border-slate-800">
                                <div>
                                    <p className="text-xs font-bold text-slate-600 dark:text-slate-300">Hak akses detail</p>
                                    <p className="mt-1 text-[11px] text-slate-400">Izin bawaan layout dapat disesuaikan sesuai kebutuhan.</p>
                                </div>
                                <span className="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500 dark:bg-slate-800 dark:text-slate-300">{form.permissions.length} izin</span>
                            </div>
                            <div className="mt-6 space-y-5">
                                {Object.entries(permissionGroups).map(([group, permissions]) => <fieldset key={group}><legend className="text-xs font-bold uppercase text-slate-400">{group}</legend><div className="mt-2 grid gap-2 sm:grid-cols-2">{permissions.map((permission) => <label key={permission.key} className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-100 p-3 dark:border-slate-800"><input type="checkbox" checked={form.permissions.includes(permission.key)} onChange={() => togglePermission(permission.key)} className="mt-0.5 rounded border-slate-300 text-[#304674] focus:ring-[#304674]" /><span><span className="block text-xs font-semibold text-slate-700 dark:text-slate-200">{permission.label}</span><span className="mt-0.5 block text-[10px] text-slate-400">{permission.key}</span></span></label>)}</div></fieldset>)}
                            </div>
                            <label className="mt-6 flex cursor-pointer items-center justify-between rounded-lg bg-slate-50 p-4 dark:bg-slate-800"><span><span className="block text-sm font-bold text-slate-700 dark:text-slate-200">Profil aktif</span><span className="text-xs text-slate-400">Profil nonaktif tidak dapat dipilih pada perangkat.</span></span><input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} className="rounded border-slate-300 text-[#304674] focus:ring-[#304674]" /></label>
                        </div>
                        <div className="flex gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4 dark:border-slate-800 dark:bg-slate-900"><button type="button" onClick={() => setModalOpen(false)} className="flex-1 rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Batal</button><button type="submit" disabled={saving} className="flex-1 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{saving ? "Menyimpan..." : "Simpan"}</button></div>
                    </motion.form></div>}
                </AnimatePresence>, document.body,
            )}
        </AppLayout>
    );
}
