import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import { BarChart3, CalendarDays, Check, ChevronDown, ChevronRight, Link2, LoaderCircle, Plus, RefreshCw, Store, Trash2, TriangleAlert } from "lucide-react";
import AppLayout from "../../views/components/layouts/AppLayout";
import OnboardingTour from "@/components/OnboardingTour";
import ProfitBreakdownModal from "@/components/ProfitBreakdownModal";
import { useOnboarding } from "@/hooks/useOnboarding";

const PROFIT_TOUR_STEPS = [
    {
        selector: "#tour-escrow-filter",
        title: "Filter Status Pesanan",
        description: "Pilih status pesanan yang masuk ke perhitungan escrow dan HPP real-time.",
        position: "bottom",
    },
    {
        selector: "#tour-profit-summary > :first-child",
        title: "Ringkasan Dana",
        description: "Bandingkan total escrow dan HPP dari kumpulan pesanan yang sama.",
        position: "bottom",
    },
    {
        selector: "#tour-store-profit > :nth-child(2) > :first-child, #tour-store-profit",
        title: "Rincian Per Toko",
        description: "Pantau jumlah pesanan, escrow, HPP, dan profit tiap toko.",
        position: "top",
    },
    {
        selector: "#tour-profit-analytics",
        title: "Analisis Periode",
        description: "Lihat pergerakan HPP, escrow, dan profit untuk tanggal atau periode yang dipilih.",
        position: "top",
    },
];

const FALLBACK_POLLING_INTERVAL = 60000;
const REALTIME_REFRESH_DELAY = 1200;
const ANALYTICS_PERIODS = [
    { id: "today", label: "Hari Ini" },
    { id: "date", label: "Pilih Hari" },
    { id: "range", label: "Rentang" },
    { id: "last_3_days", label: "3 Hari" },
    { id: "last_7_days", label: "7 Hari" },
    { id: "last_14_days", label: "14 Hari" },
    { id: "last_30_days", label: "30 Hari" },
    { id: "last_60_days", label: "60 Hari" },
    { id: "last_90_days", label: "90 Hari" },
    { id: "month", label: "Per Bulan" },
    { id: "year", label: "Per Tahun" },
];

function localDateString(date = new Date()) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function formatRp(value) {
    return "Rp " + Number(value || 0).toLocaleString("id-ID");
}

function formatPeriodLabel(key, granularity) {
    const suffix = granularity === "month" ? "-01" : "";
    const date = new Date(`${key}${suffix}T00:00:00`);

    if (Number.isNaN(date.getTime())) return key;

    return new Intl.DateTimeFormat("id-ID", granularity === "month"
        ? { month: "long", year: "numeric" }
        : { day: "2-digit", month: "short", year: "numeric" }
    ).format(date);
}

function formatSelectedDate(value) {
    if (!value) return "Belum dipilih";

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("id-ID", {
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric",
    }).format(date);
}

function DatePickerField({ id, label, value, onChange, min, max }) {
    return (
        <label htmlFor={id} className="group block min-w-0">
            <span className="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">{label}</span>
            <span className="flex min-h-[68px] items-center gap-3 rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 shadow-sm transition-colors focus-within:border-[#304674] focus-within:ring-2 focus-within:ring-[#304674]/10 dark:border-slate-700 dark:bg-slate-800 dark:focus-within:border-blue-500 dark:focus-within:ring-blue-500/10">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300">
                    <CalendarDays aria-hidden="true" size={18} strokeWidth={1.8} />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200">
                        {formatSelectedDate(value)}
                    </span>
                    <input
                        id={id}
                        type="date"
                        value={value}
                        min={min}
                        max={max}
                        onChange={(event) => onChange(event.target.value)}
                        className="mt-0.5 block w-full cursor-pointer border-0 bg-transparent p-0 text-xs text-slate-500 shadow-none focus:ring-0 dark:text-slate-400"
                    />
                </span>
            </span>
        </label>
    );
}

function FilterDropdown({ id, value, onChange, options, label, icon: Icon, className = "" }) {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef(null);
    const selectedOption = options.find((option) => String(option.value) === String(value)) || options[0];

    useEffect(() => {
        const closeDropdown = (event) => {
            if (containerRef.current && !containerRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === "Escape") setIsOpen(false);
        };

        document.addEventListener("mousedown", closeDropdown);
        document.addEventListener("keydown", closeOnEscape);

        return () => {
            document.removeEventListener("mousedown", closeDropdown);
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, []);

    return (
        <div ref={containerRef} className={`relative ${className}`}>
            <button
                id={id}
                type="button"
                aria-label={`${label}: ${selectedOption?.label || "Pilih"}`}
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                onClick={() => setIsOpen((open) => !open)}
                className="flex h-9 w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-left text-xs text-slate-700 shadow-sm transition-colors hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:border-slate-600 dark:focus:ring-blue-500/30"
            >
                <Icon className="h-4 w-4 shrink-0 text-[#304674] dark:text-blue-400" aria-hidden="true" />
                <span className="flex min-w-0 flex-1 items-center gap-1.5">
                    <span className="shrink-0 font-medium text-slate-400 dark:text-slate-500">{label}:</span>
                    <span className="truncate font-semibold">{selectedOption?.label || "Pilih"}</span>
                </span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${isOpen ? "rotate-180" : ""}`} aria-hidden="true" />
            </button>

            {isOpen && (
                <div className="absolute right-0 z-50 mt-1.5 w-full min-w-56 origin-top overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                    <div role="listbox" aria-labelledby={id} className="max-h-64 overflow-y-auto p-1.5">
                        {options.map((option) => {
                            const isSelected = String(option.value) === String(value);

                            return (
                                <button
                                    key={String(option.value)}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => {
                                        onChange(option.value);
                                        setIsOpen(false);
                                    }}
                                    className={`mt-1 flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-xs transition-colors first:mt-0 ${isSelected
                                        ? "bg-[#304674] text-white dark:bg-blue-600"
                                        : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"
                                    }`}
                                >
                                    <span className="min-w-0 flex-1 truncate font-medium">{option.label}</span>
                                    {option.meta && (
                                        <span className={`rounded-md px-1.5 py-0.5 text-[9px] font-bold uppercase ${isSelected ? "bg-white/15 text-white" : "bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400"}`}>
                                            {option.meta}
                                        </span>
                                    )}
                                    <Check className={`h-4 w-4 shrink-0 ${isSelected ? "opacity-100" : "opacity-0"}`} aria-hidden="true" />
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

const PLATFORM_CONFIG = {
    Shopee: {
        bg: "bg-orange-50 dark:bg-orange-500/10",
        text: "text-orange-600 dark:text-orange-400",
        border: "border-orange-100 dark:border-orange-500/20",
        icon: <img src="/Marketplace-logo/shopee.png" alt="Shopee" className="h-5 w-5 object-contain" />,
    },
    Tokopedia: {
        bg: "bg-green-50 dark:bg-green-500/10",
        text: "text-green-600 dark:text-green-400",
        border: "border-green-100 dark:border-green-500/20",
        icon: "T",
    },
    Tiktokshop: {
        bg: "bg-gray-100 dark:bg-slate-700",
        text: "text-black dark:text-white",
        border: "border-gray-200 dark:border-slate-600",
        icon: <img src="/Marketplace-logo/tts.png" alt="TikTok Shop" className="h-5 w-5 object-contain" />,
    },
};

function SkeletonCards() {
    return (
        <div className="animate-pulse space-y-8">
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                {[1, 2].map((item) => (
                    <div key={item} className="h-40 rounded-2xl bg-slate-200 dark:bg-slate-700" />
                ))}
            </div>
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-4">
                {[1, 2, 3, 4].map((item) => (
                    <div key={item} className="h-52 rounded-xl bg-slate-200 dark:bg-slate-700" />
                ))}
            </div>
            <div className="h-80 rounded-xl bg-slate-200 dark:bg-slate-700" />
        </div>
    );
}

function MetricCard({ label, value, orders, icon, tone }) {
    const tones = {
        escrow: {
            icon: "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400",
            badge: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300",
        },
        hpp: {
            icon: "bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300",
            badge: "bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300",
        },
    };
    const theme = tones[tone];

    return (
        <div className="relative overflow-hidden rounded-2xl border border-gray-100 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div className={`absolute right-5 top-5 flex h-12 w-12 items-center justify-center rounded-xl ${theme.icon}`}>
                <span className="material-symbols-rounded text-2xl">{icon}</span>
            </div>
            <div className="relative pr-16">
                <p className="mb-1 text-xs font-bold uppercase text-gray-400 dark:text-slate-500">{label}</p>
                <h3 className="break-words text-2xl font-bold text-gray-800 dark:text-white sm:text-3xl">
                    {formatRp(value)}
                </h3>
                <span className={`mt-4 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ${theme.badge}`}>
                    <span className="material-symbols-rounded text-sm">receipt_long</span>
                    {Number(orders || 0).toLocaleString("id-ID")} pesanan
                </span>
            </div>
        </div>
    );
}

function formatSyncTime(value) {
    if (!value) return "Belum pernah disinkronkan";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
    }).format(date);
}

function TiktokAdsAccountManagement({ stores, onSynced }) {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [syncingTarget, setSyncingTarget] = useState(null);
    const [removingAccountId, setRemovingAccountId] = useState(null);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    const syncPollRef = useRef(null);

    useEffect(() => () => {
        if (syncPollRef.current) {
            window.clearInterval(syncPollRef.current);
        }
    }, []);

    const loadStatus = useCallback(async () => {
        const response = await axios.get("/api/tiktok-ads/status");
        setStatus(response.data);
        return response.data;
    }, []);

    useEffect(() => {
        let active = true;
        loadStatus()
            .catch((requestError) => {
                if (active) setError(requestError.response?.data?.message || "Status TikTok Ads belum dapat dimuat.");
            })
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, [loadStatus]);

    const updateStore = async (accountId, storeId) => {
        setError("");
        try {
            await axios.put(`/api/tiktok-ads/accounts/${accountId}/store`, {
                store_id: storeId ? Number(storeId) : null,
            });
            await loadStatus();
            setMessage("Pemetaan advertiser account diperbarui.");
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Pemetaan toko gagal diperbarui.");
        }
    };

    const removeAccount = async (account) => {
        if (!window.confirm(`Hapus advertiser ${account.advertiser_name || account.advertiser_id} dari Finesheet? Data otorisasi di TikTok tidak akan dicabut.`)) return;

        setRemovingAccountId(account.id);
        setError("");
        try {
            const response = await axios.delete(`/api/tiktok-ads/accounts/${account.id}`);
            await loadStatus();
            setMessage(response.data.message);
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Advertiser account gagal dihapus.");
        } finally {
            setRemovingAccountId(null);
        }
    };

    const sync = async (account = null) => {
        if (syncPollRef.current) {
            window.clearInterval(syncPollRef.current);
            syncPollRef.current = null;
        }

        const target = account?.id || "all";
        setSyncingTarget(target);
        setError("");
        setMessage("");
        const previousAccounts = new Map(
            (status?.accounts || []).map((item) => [item.id, {
                lastSyncedAt: item.last_synced_at,
                lastError: item.last_error,
            }]),
        );

        try {
            const payload = { days: 30 };
            if (account?.id) payload.account_id = account.id;
            const response = await axios.post("/api/tiktok-ads/sync", payload);
            setMessage(response.data.message);
            const queuedAccountIds = response.data.account_ids || (account?.id ? [account.id] : []);

            let attempts = 0;
            syncPollRef.current = window.setInterval(async () => {
                attempts += 1;
                try {
                    const nextStatus = await loadStatus();
                    const nextAccounts = nextStatus?.accounts || [];
                    const finished = queuedAccountIds.length > 0 && queuedAccountIds.every((accountId) => {
                        const nextAccount = nextAccounts.find((item) => item.id === accountId);
                        const previous = previousAccounts.get(accountId);
                        return Boolean(nextAccount && (
                            (nextAccount.last_synced_at && nextAccount.last_synced_at !== previous?.lastSyncedAt)
                            || (nextAccount.last_error && nextAccount.last_error !== previous?.lastError)
                        ));
                    });

                    if (finished || attempts >= 24) {
                        window.clearInterval(syncPollRef.current);
                        syncPollRef.current = null;
                        setSyncingTarget(null);
                        if (finished) onSynced?.();
                    }
                } catch {
                    if (attempts >= 24) {
                        window.clearInterval(syncPollRef.current);
                        syncPollRef.current = null;
                        setSyncingTarget(null);
                    }
                }
            }, 5000);
        } catch (requestError) {
            setError(requestError.response?.data?.message || "Sinkronisasi TikTok Ads gagal dimulai.");
            setSyncingTarget(null);
        }
    };

    const tiktokStoreOptions = [
        { value: "", label: "Belum dipetakan" },
        ...stores
            .filter((store) => String(store.platform).toLowerCase() === "tiktokshop")
            .map((store) => ({ value: String(store.id), label: store.store_name })),
    ];
    const accounts = status?.accounts || [];
    const mappedAccounts = accounts.filter((account) => account.store?.id);

    return (
        <section className="relative z-20 space-y-4 border-t border-slate-200 pt-7 dark:border-slate-700" aria-labelledby="tiktok-advertiser-management-title">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 id="tiktok-advertiser-management-title" className="font-bold text-gray-800 dark:text-white">Manajemen Akun TikTok Advertiser</h2>
                        <span className={`rounded-md px-2 py-1 text-[10px] font-bold ${status?.connected ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" : "bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300"}`}>
                            {status?.connected ? `${accounts.length} akun terhubung` : "Belum terhubung"}
                        </span>
                    </div>
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">Tambahkan advertiser, petakan ke toko TikTok Shop, lalu sinkronkan spend dan campaign.</p>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <a href="/connect/tiktok-ads" className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-xs font-bold text-[#304674] transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-blue-300 dark:hover:bg-slate-700">
                        {status?.connected ? <Plus size={15} aria-hidden="true" /> : <Link2 size={15} aria-hidden="true" />}
                        {status?.connected ? "Tambah akun" : "Hubungkan TikTok Ads"}
                    </a>
                    <button
                        type="button"
                        onClick={() => sync()}
                        disabled={!status?.connected || mappedAccounts.length === 0 || syncingTarget !== null}
                        className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-[#304674] px-3 text-xs font-bold text-white transition-colors hover:bg-[#26385e] disabled:cursor-not-allowed disabled:opacity-50 dark:bg-blue-600 dark:hover:bg-blue-500"
                    >
                        {syncingTarget === "all" ? <LoaderCircle size={15} className="animate-spin" aria-hidden="true" /> : <RefreshCw size={15} aria-hidden="true" />}
                        {syncingTarget === "all" ? "Menyinkronkan..." : "Sinkron semua"}
                    </button>
                </div>
            </div>

            <div className="overflow-visible rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
                <div className="border-b border-slate-100 bg-slate-50/70 px-4 py-2 text-[10px] leading-relaxed text-slate-500 dark:border-slate-700 dark:bg-slate-900/30 dark:text-slate-400">
                    Business Center ID bukan advertiser ID. Cocokkan ID akun dengan akun utama atau akun GMV Max pada TikTok Shop sebelum memetakannya.
                </div>
                {loading ? (
                    <div className="flex items-center gap-2 px-4 py-5 text-xs text-slate-500"><LoaderCircle size={15} className="animate-spin" /> Memuat akun advertiser...</div>
                ) : accounts.length > 0 ? (
                    <div className="divide-y divide-slate-100 dark:divide-slate-700">
                        {accounts.map((account) => {
                            const isSyncing = syncingTarget === account.id;
                            return (
                            <div key={account.id} className="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(0,1fr)_240px_auto] lg:items-center">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-xs font-bold text-slate-700 dark:text-slate-200">{account.advertiser_name || `Advertiser ${account.advertiser_id}`}</p>
                                        <span className={`rounded px-1.5 py-0.5 text-[9px] font-bold ${account.store?.id ? "bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300" : "bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300"}`}>
                                            {account.store?.id ? "Terpetakan" : "Belum dipetakan"}
                                        </span>
                                    </div>
                                    <p className="truncate text-[10px] text-slate-400">Advertiser ID {account.advertiser_id}{account.currency ? ` / ${account.currency}` : ""}</p>
                                    <p className="mt-1 text-[10px] text-slate-400">Sinkron terakhir {formatSyncTime(account.last_synced_at)}</p>
                                    {account.last_error && <p className="mt-1 line-clamp-2 text-[10px] font-medium text-red-600 dark:text-red-400">{account.last_error}</p>}
                                </div>
                                <FilterDropdown
                                    id={`tiktok-account-store-${account.id}`}
                                    label="Toko"
                                    value={account.store?.id ? String(account.store.id) : ""}
                                    onChange={(value) => updateStore(account.id, value)}
                                    options={tiktokStoreOptions}
                                    icon={Store}
                                    className="min-w-0"
                                />
                                <div className="flex items-center gap-2 lg:justify-end">
                                    <button
                                        type="button"
                                        title={account.store?.id ? "Sinkronkan advertiser ini" : "Petakan toko sebelum sinkronisasi"}
                                        aria-label={`Sinkronkan advertiser ${account.advertiser_name || account.advertiser_id}`}
                                        disabled={!account.store?.id || syncingTarget !== null}
                                        onClick={() => sync(account)}
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-[#304674] transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800 dark:text-blue-300 dark:hover:bg-slate-700"
                                    >
                                        {isSyncing ? <LoaderCircle size={15} className="animate-spin" /> : <RefreshCw size={15} />}
                                    </button>
                                    <button
                                        type="button"
                                        title="Hapus advertiser dari Finesheet"
                                        aria-label={`Hapus advertiser ${account.advertiser_name || account.advertiser_id}`}
                                        disabled={removingAccountId === account.id}
                                        onClick={() => removeAccount(account)}
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-red-100 bg-red-50 text-red-600 transition-colors hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-900/40 dark:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/20"
                                    >
                                        {removingAccountId === account.id ? <LoaderCircle size={15} className="animate-spin" /> : <Trash2 size={15} />}
                                    </button>
                                </div>
                            </div>
                            );
                        })}
                    </div>
                ) : (
                    <div className="flex flex-col items-start gap-3 px-4 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-slate-500 dark:text-slate-400">Belum ada advertiser account yang ditambahkan ke Finesheet.</p>
                        <a href="/connect/tiktok-ads" className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-[#304674] px-3 text-xs font-bold text-white hover:bg-[#26385e] dark:bg-blue-600 dark:hover:bg-blue-500">
                            <Plus size={15} /> Tambah akun
                        </a>
                    </div>
                )}

                {(message || error) && (
                    <div className={`border-t px-4 py-2 text-[11px] font-medium ${error ? "border-red-100 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300" : "border-blue-100 bg-blue-50 text-[#304674] dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-300"}`}>
                        {error || message}
                    </div>
                )}
            </div>
        </section>
    );
}

function TiktokAdsCampaignSummary({ analytics, selectedStore, refreshKey }) {
    const [campaigns, setCampaigns] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        let active = true;
        if (!analytics?.start_date || !analytics?.end_date) return undefined;

        setLoading(true);
        const params = { start: analytics.start_date, end: analytics.end_date };
        if (selectedStore) params.store_id = selectedStore;
        axios.get("/api/tiktok-ads/campaigns", { params })
            .then((response) => {
                if (!active) return;
                setCampaigns(response.data.campaigns || []);
                setError("");
            })
            .catch((requestError) => {
                if (active) setError(requestError.response?.data?.message || "Data campaign TikTok Ads belum dapat dimuat.");
            })
            .finally(() => active && setLoading(false));

        return () => { active = false; };
    }, [analytics?.end_date, analytics?.start_date, refreshKey, selectedStore]);

    return (
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
            <div className="mb-2 flex items-center justify-between gap-3">
                <p className="flex items-center gap-1.5 text-xs font-bold text-slate-700 dark:text-slate-200"><BarChart3 size={14} /> Campaign TikTok Ads teratas</p>
                <span className="text-[10px] text-slate-400">{analytics?.start_date} - {analytics?.end_date}</span>
            </div>
            {loading ? (
                <p className="flex items-center gap-2 py-2 text-[11px] text-slate-400"><LoaderCircle size={14} className="animate-spin" /> Memuat campaign...</p>
            ) : error ? (
                <p className="py-2 text-[11px] font-medium text-red-600 dark:text-red-400">{error}</p>
            ) : campaigns.length > 0 ? (
                <div className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    {campaigns.slice(0, 6).map((campaign) => (
                        <div key={campaign.campaign_id} className="flex items-center justify-between gap-3 text-xs">
                            <span className="min-w-0 truncate text-slate-600 dark:text-slate-300" title={campaign.campaign_name || campaign.campaign_id}>{campaign.campaign_name || campaign.campaign_id}</span>
                            <span className="shrink-0 font-bold text-orange-600 dark:text-orange-400">{formatRp(campaign.amount)}</span>
                        </div>
                    ))}
                </div>
            ) : <p className="py-2 text-[11px] leading-5 text-slate-400">Belum ada data campaign pada rentang ini.</p>}
        </div>
    );
}

export default function ProfitTracker() {
    const tour = useOnboarding("profit", PROFIT_TOUR_STEPS.length);
    const today = localDateString();
    const threeDaysAgo = localDateString(new Date(Date.now() - (2 * 24 * 60 * 60 * 1000)));
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState("");
    const fetchDataRef = useRef(null);
    const realtimeRefreshTimeoutRef = useRef(null);
    const requestSequenceRef = useRef(0);
    const [selectedStore, setSelectedStore] = useState("");
    const [escrowFilters, setEscrowFilters] = useState({
        include_perlu_dikirim: 1,
        include_dikirim: 1,
        include_return: 0,
    });
    const [analyticsFilter, setAnalyticsFilter] = useState({
        period: "last_3_days",
        date: today,
        start: threeDaysAgo,
        end: today,
        month: today.slice(0, 7),
        year: today.slice(0, 4),
    });
    const [selectedBreakdownPoint, setSelectedBreakdownPoint] = useState(null);
    const [tiktokAdsRefreshKey, setTiktokAdsRefreshKey] = useState(0);

    const fetchData = useCallback(async ({ silent = false } = {}) => {
        const requestSequence = ++requestSequenceRef.current;

        if (silent) {
            setRefreshing(true);
        }

        try {
            const params = {
                ...escrowFilters,
                analytics_period: analyticsFilter.period,
                include_analytics: 1,
            };

            if (analyticsFilter.period === "date") params.analytics_date = analyticsFilter.date;
            if (analyticsFilter.period === "range") {
                params.analytics_start = analyticsFilter.start;
                params.analytics_end = analyticsFilter.end;
            }
            if (analyticsFilter.period === "month") params.analytics_month = analyticsFilter.month;
            if (analyticsFilter.period === "year") params.analytics_year = analyticsFilter.year;
            if (selectedStore) params.store_id = selectedStore;

            const response = await axios.get("/api/profit-tracker", { params });

            if (requestSequence !== requestSequenceRef.current) return;

            setData(response.data);
            setError("");
        } catch (requestError) {
            if (requestSequence !== requestSequenceRef.current) return;

            console.error(requestError);
            setError(requestError.response?.data?.message || "Data profit belum dapat dimuat.");
        } finally {
            if (requestSequence === requestSequenceRef.current) {
                setLoading(false);
                setRefreshing(false);
            }
        }
    }, [analyticsFilter, escrowFilters, selectedStore]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    useEffect(() => {
        fetchDataRef.current = fetchData;
    }, [fetchData]);

    useEffect(() => {
        const handleOrderEvent = () => {
            if (realtimeRefreshTimeoutRef.current) {
                clearTimeout(realtimeRefreshTimeoutRef.current);
            }

            realtimeRefreshTimeoutRef.current = setTimeout(() => {
                realtimeRefreshTimeoutRef.current = null;
                fetchDataRef.current?.({ silent: true });
            }, REALTIME_REFRESH_DELAY);
        };

        window.addEventListener("order-created", handleOrderEvent);
        window.addEventListener("order-updated", handleOrderEvent);
        window.addEventListener("profit-analytics-updated", handleOrderEvent);

        return () => {
            if (realtimeRefreshTimeoutRef.current) {
                clearTimeout(realtimeRefreshTimeoutRef.current);
            }
            window.removeEventListener("order-created", handleOrderEvent);
            window.removeEventListener("order-updated", handleOrderEvent);
            window.removeEventListener("profit-analytics-updated", handleOrderEvent);
        };
    }, []);

    useEffect(() => {
        const refreshIfRealtimeUnavailable = () => {
            const connectionState = window.Echo?.connector?.pusher?.connection?.state;
            const realtimeConnected = connectionState === "connected";

            if (document.visibilityState === "visible" && !realtimeConnected) {
                fetchDataRef.current?.({ silent: true });
            }
        };

        const interval = setInterval(refreshIfRealtimeUnavailable, FALLBACK_POLLING_INTERVAL);
        document.addEventListener("visibilitychange", refreshIfRealtimeUnavailable);

        return () => {
            clearInterval(interval);
            document.removeEventListener("visibilitychange", refreshIfRealtimeUnavailable);
        };
    }, []);

    const toggleFilter = (key) => {
        setEscrowFilters((previous) => ({
            ...previous,
            [key]: previous[key] === 1 ? 0 : 1,
        }));
    };

    const updateAnalyticsFilter = (key, value) => {
        setAnalyticsFilter((previous) => ({ ...previous, [key]: value }));
    };

    const openBreakdown = (point) => {
        setSelectedBreakdownPoint({
            ...point,
            label: formatPeriodLabel(point.key, analytics?.granularity),
        });
    };

    const analytics = data?.analytics;
    const storeOptions = [
        { value: "", label: "Semua Toko" },
        ...(data?.store_options || []).map((store) => ({
            value: String(store.id),
            label: store.store_name,
            meta: store.platform,
        })),
    ];
    const periodOptions = ANALYTICS_PERIODS.map((period) => ({
        value: period.id,
        label: period.label,
    }));

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 px-1 py-2 transition-colors duration-300 dark:bg-slate-900 sm:px-2">
                <div className="animate-fade-in-up space-y-8 pb-10">
                    <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Profit & Cashflow Tracker</h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">Pantau escrow, HPP, dan profit seluruh toko dalam satu tampilan.</p>
                        </div>

                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <div className="flex min-h-11 items-center justify-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                <span className="relative flex h-2.5 w-2.5">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                    <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500" />
                                </span>
                                <span className="text-xs font-medium text-gray-600 dark:text-slate-300">
                                    {refreshing ? "Memperbarui data..." : "Real-time aktif"}
                                </span>
                            </div>
                        </div>
                    </div>

                    <section className="space-y-4" aria-labelledby="realtime-finance-title">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 id="realtime-finance-title" className="text-base font-bold text-gray-800 dark:text-white">Real-time Escrow and HPP Data</h2>
                                <p className="text-xs text-gray-500 dark:text-slate-400">Dana aktif berdasarkan status pesanan yang dipilih.</p>
                            </div>
                        </div>

                        <div id="tour-escrow-filter" className="flex flex-wrap items-center gap-2">
                            <span className="mr-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Filter Status Escrow:</span>
                            {[
                                ["include_perlu_dikirim", "Perlu Dikirim"],
                                ["include_dikirim", "Dikirim"],
                                ["include_return", "Return / Batal Setelah Dikirim"],
                            ].map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => toggleFilter(key)}
                                    className={`rounded-full border px-3 py-1.5 text-xs font-medium shadow-sm transition-colors ${escrowFilters[key] === 1
                                        ? "border-[#304674] bg-[#304674] text-white dark:border-blue-600 dark:bg-blue-600"
                                        : "border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                            <span className="ml-auto text-[10px] italic text-slate-400 dark:text-slate-500">Pesanan selesai tidak masuk saldo real-time.</span>
                        </div>

                        {loading ? (
                            <SkeletonCards />
                        ) : data && (
                            <div id="tour-profit-summary" className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <MetricCard
                                    label="Total Dana Escrow"
                                    value={data.total_escrow_amount}
                                    orders={data.total_order_count}
                                    icon="savings"
                                    tone="escrow"
                                />
                                <MetricCard
                                    label="Total Dana HPP"
                                    value={data.total_hpp_amount}
                                    orders={data.total_order_count}
                                    icon="inventory_2"
                                    tone="hpp"
                                />
                            </div>
                        )}
                    </section>

                    {!loading && error && (
                        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-300">
                            {error}
                        </div>
                    )}

                    {!loading && data && (
                        <>
                            <section id="tour-store-profit" className="space-y-4" aria-labelledby="store-breakdown-title">
                                <div className="flex items-center justify-between gap-4">
                                    <h2 id="store-breakdown-title" className="flex items-center gap-2 font-bold text-gray-800 dark:text-white">
                                        <span className="material-symbols-rounded text-[#304674] dark:text-blue-400">storefront</span>
                                        Rincian Per Toko
                                    </h2>
                                    <span className="text-right text-xs text-gray-500 dark:text-slate-400">Diurutkan berdasarkan nominal escrow</span>
                                </div>

                                <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-4">
                                    {data.stores?.map((store) => {
                                        const theme = PLATFORM_CONFIG[store.platform] || {
                                            bg: "bg-gray-50",
                                            text: "text-gray-600",
                                            border: "border-gray-100",
                                            icon: "?",
                                        };
                                        const visibleStatusMetrics = [
                                            escrowFilters.include_perlu_dikirim === 1 && { key: "perlu_dikirim", label: "Perlu Dikirim" },
                                            escrowFilters.include_dikirim === 1 && { key: "dikirim", label: "Dikirim" },
                                            escrowFilters.include_return === 1 && { key: "return_cancel", label: "Return / Batal" },
                                        ].filter(Boolean);

                                        return (
                                            <div key={store.id} className="flex h-full flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md dark:border-slate-700 dark:bg-slate-800">
                                                <div className="mb-4 flex items-start justify-between gap-3">
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border text-lg font-bold shadow-sm ${theme.bg} ${theme.text} ${theme.border}`}>
                                                            {theme.icon}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <h3 className="truncate text-sm font-bold text-gray-800 dark:text-white" title={store.store_name}>{store.store_name}</h3>
                                                            <p className="text-[10px] uppercase text-gray-400 dark:text-slate-500">{store.platform}</p>
                                                        </div>
                                                    </div>
                                                    <span className="shrink-0 rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                                        {store.included_order_count} pesanan
                                                    </span>
                                                </div>

                                                {visibleStatusMetrics.length > 0 && (
                                                    <div className="mb-4 grid divide-x divide-gray-100 border-y border-gray-100 py-3 dark:divide-slate-700 dark:border-slate-700" style={{ gridTemplateColumns: `repeat(${visibleStatusMetrics.length}, minmax(0, 1fr))` }}>
                                                        {visibleStatusMetrics.map((metric) => (
                                                            <div key={metric.key} className="min-w-0 px-2 first:pl-0 last:pr-0">
                                                                <p className="text-base font-bold leading-none text-gray-800 dark:text-white">{store.status_counts?.[metric.key] || 0}</p>
                                                                <p className="mt-1 text-[10px] font-medium leading-tight text-gray-500 dark:text-slate-400">{metric.label}</p>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}

                                                <div className="mt-auto space-y-2.5">
                                                    <div className="flex items-center justify-between gap-3">
                                                        <span className="text-[11px] font-medium text-gray-500 dark:text-slate-400">Escrow</span>
                                                        <span className="text-sm font-bold text-[#304674] dark:text-blue-400">{formatRp(store.escrow)}</span>
                                                    </div>
                                                    <div className="flex items-center justify-between gap-3">
                                                        <span className="text-[11px] font-medium text-gray-500 dark:text-slate-400">HPP</span>
                                                        <span className="text-sm font-bold text-gray-800 dark:text-white">{formatRp(store.hpp)}</span>
                                                    </div>
                                                    <div className="flex items-center justify-between gap-3 border-t border-dashed border-gray-200 pt-2.5 dark:border-slate-700">
                                                        <span className="text-[11px] font-semibold text-gray-600 dark:text-slate-300">Profit</span>
                                                        <span className={`text-sm font-bold ${Number(store.profit || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(store.profit)}</span>
                                                    </div>
                                                    <div className="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-700">
                                                        <div className="h-1.5 rounded-full bg-[#304674] transition-all duration-500 dark:bg-blue-500" style={{ width: `${store.percent}%` }} />
                                                    </div>
                                                    <p className="text-right text-[10px] text-gray-400 dark:text-slate-500">{store.percent}% dari total escrow</p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>

                            <TiktokAdsAccountManagement
                                stores={data.store_options || []}
                                onSynced={() => {
                                    setTiktokAdsRefreshKey((value) => value + 1);
                                    fetchData({ silent: true });
                                }}
                            />

                            <section id="tour-profit-analytics" className="space-y-5 border-t border-slate-200 pt-7 dark:border-slate-700" aria-labelledby="profit-analytics-title">
                                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 id="profit-analytics-title" className="font-bold text-gray-800 dark:text-white">Analisis Profit & Pengeluaran Iklan</h2>
                                            <span className="rounded-md bg-orange-50 px-2 py-1 text-[10px] font-bold uppercase text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">Shopee + TikTok Ads</span>
                                        </div>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">Profit bersih dihitung dari escrow dikurangi HPP dan pengeluaran iklan yang tersedia. Pesanan cancel/return ditampilkan terpisah sebagai informasi.</p>
                                    </div>

                                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                                        <FilterDropdown
                                            id="profit-store-filter"
                                            label="Toko"
                                            value={selectedStore}
                                            onChange={setSelectedStore}
                                            options={storeOptions}
                                            icon={Store}
                                            className="w-full sm:w-56"
                                        />
                                        <FilterDropdown
                                            id="profit-period-filter"
                                            label="Rentang"
                                            value={analyticsFilter.period}
                                            onChange={(value) => updateAnalyticsFilter("period", value)}
                                            options={periodOptions}
                                            icon={CalendarDays}
                                            className="w-full sm:w-48"
                                        />
                                    </div>
                                </div>

                                {analyticsFilter.period === "date" && (
                                    <div className="max-w-md rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40">
                                        <DatePickerField
                                            id="analytics-date"
                                            label="Tanggal analisis"
                                            value={analyticsFilter.date}
                                            onChange={(value) => updateAnalyticsFilter("date", value)}
                                            max={today}
                                        />
                                    </div>
                                )}

                                {analyticsFilter.period === "range" && (
                                    <div className="max-w-2xl rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40">
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_1fr] sm:items-end">
                                            <DatePickerField
                                                id="analytics-start-date"
                                                label="Dari tanggal"
                                                value={analyticsFilter.start}
                                                onChange={(value) => updateAnalyticsFilter("start", value)}
                                                max={analyticsFilter.end || today}
                                            />
                                            <span className="hidden pb-6 text-xs font-semibold text-slate-400 sm:block">sampai</span>
                                            <DatePickerField
                                                id="analytics-end-date"
                                                label="Sampai tanggal"
                                                value={analyticsFilter.end}
                                                onChange={(value) => updateAnalyticsFilter("end", value)}
                                                min={analyticsFilter.start}
                                                max={today}
                                            />
                                        </div>
                                    </div>
                                )}

                                {analyticsFilter.period === "month" && (
                                    <div className="max-w-xs">
                                        <label className="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300" htmlFor="analytics-month">Bulan</label>
                                        <input id="analytics-month" type="month" value={analyticsFilter.month} onChange={(event) => updateAnalyticsFilter("month", event.target.value)} className="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-700 focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" />
                                    </div>
                                )}

                                {analyticsFilter.period === "year" && (
                                    <div className="max-w-xs">
                                        <label className="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300" htmlFor="analytics-year">Tahun</label>
                                        <input id="analytics-year" type="number" min="2000" max="2100" step="1" value={analyticsFilter.year} onChange={(event) => updateAnalyticsFilter("year", event.target.value)} className="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-700 focus:border-[#304674] focus:ring-[#304674] dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200" />
                                    </div>
                                )}

                                {analytics && (
                                    <>
                                        <TiktokAdsCampaignSummary
                                            analytics={analytics}
                                            selectedStore={selectedStore}
                                            refreshKey={tiktokAdsRefreshKey}
                                        />

                                        {(!analytics.ad_spend?.available || analytics.ad_spend?.is_partial || !analytics.ad_spend?.coverage_complete) && (
                                            <div className="flex items-start gap-3 border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-amber-900 dark:border-amber-500 dark:bg-amber-500/10 dark:text-amber-200" role="status">
                                                <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                                <div className="min-w-0 text-xs leading-relaxed">
                                                    <p className="font-bold">Cakupan biaya iklan perlu diperiksa</p>
                                                    {!analytics.ad_spend?.available && <p>Belum ada data spend iklan pada periode ini.</p>}
                                                    {analytics.ad_spend?.available && !analytics.ad_spend?.coverage_complete && <p>Sebagian tanggal atau toko pada periode ini belum tersinkronisasi.</p>}
                                                    {(analytics.ad_spend?.platforms_pending || []).length > 0 && (
                                                        <p className="mt-1 font-semibold">Platform belum tercakup: {analytics.ad_spend.platforms_pending.join(", ")}.</p>
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        <div className="grid grid-cols-2 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 lg:grid-cols-4">
                                            {[
                                                ["Total HPP", formatRp(analytics.totals.hpp_amount), "text-gray-800 dark:text-white"],
                                                ["Total Escrow", formatRp(analytics.totals.escrow_amount), "text-[#304674] dark:text-blue-400"],
                                                ["Iklan Marketplace", formatRp(analytics.totals.ad_spend_amount), "text-orange-600 dark:text-orange-400"],
                                                ["Profit Setelah Iklan", formatRp(analytics.totals.profit_amount), Number(analytics.totals.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"],
                                            ].map(([label, value, tone], index) => (
                                                <div key={label} className={`min-w-0 px-4 py-4 ${index % 2 !== 0 ? "border-l" : ""} ${index >= 2 ? "border-t lg:border-t-0" : ""} lg:border-l lg:first:border-l-0 border-slate-100 dark:border-slate-700`}>
                                                    <p className="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500">{label}</p>
                                                    <p className={`mt-1 truncate text-sm font-bold sm:text-base ${tone}`} title={value}>{value}</p>
                                                </div>
                                            ))}
                                        </div>

                                        {Number(analytics.totals.potential_adjustment_count || 0) > 0 && (
                                            <div className="flex items-start gap-3 border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-amber-900 dark:border-amber-500 dark:bg-amber-500/10 dark:text-amber-200" role="status">
                                                <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                                <div className="min-w-0 text-xs leading-relaxed">
                                                    <p className="font-bold">
                                                        {analytics.totals.potential_adjustment_count.toLocaleString("id-ID")} pesanan cancel/return belum dimasukkan ke perhitungan.
                                                    </p>
                                                    <p>Klik periode yang memiliki penanda cancel/return untuk melihat daftar pesanannya.</p>
                                                </div>
                                            </div>
                                        )}

                                        <div className="hidden overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 md:block">
                                            <div className="max-h-[520px] overflow-auto">
                                                <table className="w-full text-left text-sm">
                                                    <thead className="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 text-[11px] uppercase text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                                                        <tr>
                                                            <th className="px-5 py-3 font-semibold">Periode</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Pesanan</th>
                                                            <th className="px-5 py-3 text-right font-semibold">HPP</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Escrow</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Iklan Marketplace</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Profit Bersih</th>
                                                            <th className="w-28 px-3 py-3 text-center font-semibold">Rincian</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-700/70">
                                                        {analytics.points.map((point) => (
                                                            <tr
                                                                key={point.key}
                                                                data-testid={`profit-period-${point.key}`}
                                                                onClick={() => openBreakdown(point)}
                                                                className="group cursor-pointer transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-700/30"
                                                            >
                                                                <td className="px-5 py-3 font-semibold text-slate-700 dark:text-slate-200">{formatPeriodLabel(point.key, analytics.granularity)}</td>
                                                                <td className="px-5 py-3 text-right text-slate-600 dark:text-slate-300">
                                                                    <span className="block">{point.order_count.toLocaleString("id-ID")}</span>
                                                                    {Number(point.potential_adjustment_count || 0) > 0 && (
                                                                        <span className="block text-[10px] font-semibold text-amber-600 underline decoration-amber-300 underline-offset-2 dark:text-amber-400">
                                                                            {point.potential_adjustment_count.toLocaleString("id-ID")} cancel/return
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="px-5 py-3 text-right font-medium text-slate-700 dark:text-slate-200">{formatRp(point.hpp_amount)}</td>
                                                                <td className="px-5 py-3 text-right font-medium text-[#304674] dark:text-blue-400">{formatRp(point.escrow_amount)}</td>
                                                                <td className="px-5 py-3 text-right font-medium text-orange-600 dark:text-orange-400">{formatRp(point.ad_spend_amount)}</td>
                                                                <td className={`px-5 py-3 text-right font-bold ${Number(point.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(point.profit_amount)}</td>
                                                                <td className="px-3 py-2.5 text-center">
                                                                    <button
                                                                        type="button"
                                                                        onClick={(event) => {
                                                                            event.stopPropagation();
                                                                            openBreakdown(point);
                                                                        }}
                                                                        className="inline-flex h-8 items-center gap-1 rounded-md px-2 text-[11px] font-semibold text-[#304674] transition-colors hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-[#304674]/30 dark:text-blue-300 dark:hover:bg-blue-500/10"
                                                                        aria-label={`Lihat rincian profit ${formatPeriodLabel(point.key, analytics.granularity)}`}
                                                                        title="Lihat rincian profit"
                                                                    >
                                                                        <span>Lihat</span>
                                                                        <ChevronRight size={14} aria-hidden="true" />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                    <tfoot className="sticky bottom-0 border-t border-slate-200 bg-slate-50 font-bold dark:border-slate-700 dark:bg-slate-900">
                                                        <tr>
                                                            <td className="px-5 py-3 text-slate-700 dark:text-slate-200">Total</td>
                                                            <td className="px-5 py-3 text-right text-slate-700 dark:text-slate-200">
                                                                <span className="block">{analytics.totals.order_count.toLocaleString("id-ID")}</span>
                                                                {Number(analytics.totals.potential_adjustment_count || 0) > 0 && (
                                                                    <span className="block text-[10px] text-amber-600 dark:text-amber-400">
                                                                        {analytics.totals.potential_adjustment_count.toLocaleString("id-ID")} cancel/return
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="px-5 py-3 text-right text-slate-800 dark:text-white">{formatRp(analytics.totals.hpp_amount)}</td>
                                                            <td className="px-5 py-3 text-right text-[#304674] dark:text-blue-400">{formatRp(analytics.totals.escrow_amount)}</td>
                                                            <td className="px-5 py-3 text-right text-orange-600 dark:text-orange-400">{formatRp(analytics.totals.ad_spend_amount)}</td>
                                                            <td className={`px-5 py-3 text-right ${Number(analytics.totals.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(analytics.totals.profit_amount)}</td>
                                                            <td className="px-3 py-3" />
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>

                                        <div className="space-y-3 md:hidden">
                                            {analytics.points.map((point) => (
                                                <button
                                                    key={point.key}
                                                    type="button"
                                                    onClick={() => openBreakdown(point)}
                                                    data-testid={`profit-period-mobile-${point.key}`}
                                                    aria-label={`Lihat rincian profit ${formatPeriodLabel(point.key, analytics.granularity)}`}
                                                    className="block w-full rounded-lg border border-slate-200 bg-white p-4 text-left transition-colors hover:border-slate-300 active:scale-[0.99] dark:border-slate-700 dark:bg-slate-800 dark:hover:border-slate-600"
                                                >
                                                    <div className="flex items-center justify-between gap-3 border-b border-slate-100 pb-3 dark:border-slate-700">
                                                        <p className="text-sm font-bold text-slate-800 dark:text-white">{formatPeriodLabel(point.key, analytics.granularity)}</p>
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <span className="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                                                {point.order_count} pesanan
                                                                {Number(point.potential_adjustment_count || 0) > 0 ? ` / ${point.potential_adjustment_count} cancel/return` : ""}
                                                            </span>
                                                            <ChevronRight size={15} className="text-slate-400" aria-hidden="true" />
                                                        </span>
                                                    </div>
                                                    <div className="mt-3 grid grid-cols-2 gap-3">
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">HPP</p><p className="truncate text-xs font-bold text-slate-700 dark:text-slate-200">{formatRp(point.hpp_amount)}</p></div>
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">Escrow</p><p className="truncate text-xs font-bold text-[#304674] dark:text-blue-400">{formatRp(point.escrow_amount)}</p></div>
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">Iklan Marketplace</p><p className="truncate text-xs font-bold text-orange-600 dark:text-orange-400">{formatRp(point.ad_spend_amount)}</p></div>
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">Profit Bersih</p><p className={`truncate text-xs font-bold ${Number(point.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(point.profit_amount)}</p></div>
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    </>
                                )}
                            </section>
                        </>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
            `}</style>
            <OnboardingTour
                steps={PROFIT_TOUR_STEPS}
                isOpen={tour.isOpen}
                currentStep={tour.currentStep}
                onNext={tour.next}
                onPrev={tour.prev}
                onSkip={tour.skip}
                onFinish={tour.finish}
                onStart={tour.start}
            />
            <ProfitBreakdownModal
                isOpen={Boolean(selectedBreakdownPoint)}
                point={selectedBreakdownPoint}
                granularity={analytics?.granularity || "day"}
                storeId={selectedStore}
                onClose={() => setSelectedBreakdownPoint(null)}
            />
        </AppLayout>
    );
}
