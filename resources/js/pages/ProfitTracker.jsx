import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import { CalendarDays } from "lucide-react";
import AppLayout from "../../views/components/layouts/AppLayout";
import OnboardingTour from "@/components/OnboardingTour";
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

export default function ProfitTracker() {
    const tour = useOnboarding("profit", PROFIT_TOUR_STEPS.length);
    const today = localDateString();
    const thirtyDaysAgo = localDateString(new Date(Date.now() - (29 * 24 * 60 * 60 * 1000)));
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState("");
    const fetchDataRef = useRef(null);
    const realtimeRefreshTimeoutRef = useRef(null);
    const requestSequenceRef = useRef(0);
    const [escrowFilters, setEscrowFilters] = useState({
        include_perlu_dikirim: 1,
        include_dikirim: 1,
        include_return: 0,
    });
    const [analyticsFilter, setAnalyticsFilter] = useState({
        period: "last_30_days",
        date: today,
        start: thirtyDaysAgo,
        end: today,
        month: today.slice(0, 7),
        year: today.slice(0, 4),
    });

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
    }, [analyticsFilter, escrowFilters]);

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

        return () => {
            if (realtimeRefreshTimeoutRef.current) {
                clearTimeout(realtimeRefreshTimeoutRef.current);
            }
            window.removeEventListener("order-created", handleOrderEvent);
            window.removeEventListener("order-updated", handleOrderEvent);
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

    const analytics = data?.analytics;

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 px-1 py-2 transition-colors duration-300 dark:bg-slate-900 sm:px-2">
                <div className="animate-fade-in-up space-y-8 pb-10">
                    <div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Profit & Cashflow Tracker</h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">Pantau escrow, HPP, dan profit seluruh toko dalam satu tampilan.</p>
                        </div>

                        <div className="flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-1.5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500" />
                            </span>
                            <span className="text-xs font-medium text-gray-600 dark:text-slate-300">
                                {refreshing ? "Memperbarui data..." : "Real-time aktif"}
                            </span>
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

                            <section id="tour-profit-analytics" className="space-y-5 border-t border-slate-200 pt-7 dark:border-slate-700" aria-labelledby="profit-analytics-title">
                                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 id="profit-analytics-title" className="font-bold text-gray-800 dark:text-white">Analisis Profit & Pengeluaran Iklan</h2>
                                            <span className="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-700 dark:text-slate-300">Iklan: Coming Soon</span>
                                        </div>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">Ringkasan berdasarkan waktu pesanan masuk.</p>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        {ANALYTICS_PERIODS.map((period) => (
                                            <button
                                                key={period.id}
                                                type="button"
                                                onClick={() => updateAnalyticsFilter("period", period.id)}
                                                className={`rounded-lg border px-3 py-2 text-xs font-semibold transition-colors ${analyticsFilter.period === period.id
                                                    ? "border-[#304674] bg-[#304674] text-white dark:border-blue-600 dark:bg-blue-600"
                                                    : "border-slate-200 bg-white text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-600"
                                                }`}
                                            >
                                                {period.label}
                                            </button>
                                        ))}
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
                                        <div className="grid grid-cols-2 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 lg:grid-cols-4">
                                            {[
                                                ["Total HPP", formatRp(analytics.totals.hpp_amount), "text-gray-800 dark:text-white"],
                                                ["Total Escrow", formatRp(analytics.totals.escrow_amount), "text-[#304674] dark:text-blue-400"],
                                                ["Total Profit", formatRp(analytics.totals.profit_amount), Number(analytics.totals.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"],
                                                ["Pengeluaran Iklan", "Coming Soon", "text-slate-400 dark:text-slate-500"],
                                            ].map(([label, value, tone], index) => (
                                                <div key={label} className={`min-w-0 px-4 py-4 ${index % 2 !== 0 ? "border-l" : ""} ${index >= 2 ? "border-t lg:border-t-0" : ""} lg:border-l lg:first:border-l-0 border-slate-100 dark:border-slate-700`}>
                                                    <p className="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500">{label}</p>
                                                    <p className={`mt-1 truncate text-sm font-bold sm:text-base ${tone}`} title={value}>{value}</p>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="hidden overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 md:block">
                                            <div className="max-h-[520px] overflow-auto">
                                                <table className="w-full text-left text-sm">
                                                    <thead className="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 text-[11px] uppercase text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
                                                        <tr>
                                                            <th className="px-5 py-3 font-semibold">Periode</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Pesanan</th>
                                                            <th className="px-5 py-3 text-right font-semibold">HPP</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Escrow</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Profit</th>
                                                            <th className="px-5 py-3 text-right font-semibold">Iklan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-700/70">
                                                        {analytics.points.map((point) => (
                                                            <tr key={point.key} className="hover:bg-slate-50/70 dark:hover:bg-slate-700/30">
                                                                <td className="px-5 py-3 font-semibold text-slate-700 dark:text-slate-200">{formatPeriodLabel(point.key, analytics.granularity)}</td>
                                                                <td className="px-5 py-3 text-right text-slate-600 dark:text-slate-300">{point.order_count.toLocaleString("id-ID")}</td>
                                                                <td className="px-5 py-3 text-right font-medium text-slate-700 dark:text-slate-200">{formatRp(point.hpp_amount)}</td>
                                                                <td className="px-5 py-3 text-right font-medium text-[#304674] dark:text-blue-400">{formatRp(point.escrow_amount)}</td>
                                                                <td className={`px-5 py-3 text-right font-bold ${Number(point.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(point.profit_amount)}</td>
                                                                <td className="px-5 py-3 text-right text-xs font-semibold text-slate-400 dark:text-slate-500">Coming Soon</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                    <tfoot className="sticky bottom-0 border-t border-slate-200 bg-slate-50 font-bold dark:border-slate-700 dark:bg-slate-900">
                                                        <tr>
                                                            <td className="px-5 py-3 text-slate-700 dark:text-slate-200">Total</td>
                                                            <td className="px-5 py-3 text-right text-slate-700 dark:text-slate-200">{analytics.totals.order_count.toLocaleString("id-ID")}</td>
                                                            <td className="px-5 py-3 text-right text-slate-800 dark:text-white">{formatRp(analytics.totals.hpp_amount)}</td>
                                                            <td className="px-5 py-3 text-right text-[#304674] dark:text-blue-400">{formatRp(analytics.totals.escrow_amount)}</td>
                                                            <td className={`px-5 py-3 text-right ${Number(analytics.totals.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(analytics.totals.profit_amount)}</td>
                                                            <td className="px-5 py-3 text-right text-slate-400 dark:text-slate-500">-</td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>

                                        <div className="space-y-3 md:hidden">
                                            {analytics.points.map((point) => (
                                                <div key={point.key} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800">
                                                    <div className="flex items-center justify-between gap-3 border-b border-slate-100 pb-3 dark:border-slate-700">
                                                        <p className="text-sm font-bold text-slate-800 dark:text-white">{formatPeriodLabel(point.key, analytics.granularity)}</p>
                                                        <span className="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">{point.order_count} pesanan</span>
                                                    </div>
                                                    <div className="mt-3 grid grid-cols-3 gap-2">
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">HPP</p><p className="truncate text-xs font-bold text-slate-700 dark:text-slate-200">{formatRp(point.hpp_amount)}</p></div>
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">Escrow</p><p className="truncate text-xs font-bold text-[#304674] dark:text-blue-400">{formatRp(point.escrow_amount)}</p></div>
                                                        <div className="min-w-0"><p className="text-[10px] uppercase text-slate-400">Profit</p><p className={`truncate text-xs font-bold ${Number(point.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>{formatRp(point.profit_amount)}</p></div>
                                                    </div>
                                                </div>
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
        </AppLayout>
    );
}
