import axios from "axios";
import {
    ArrowDownRight,
    ArrowUpRight,
    ChevronRight,
    LoaderCircle,
    Megaphone,
    PackageX,
    ReceiptText,
    RotateCcw,
    X,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { createPortal } from "react-dom";

function formatRp(value) {
    return "Rp " + Math.abs(Number(value || 0)).toLocaleString("id-ID");
}

function formatSignedRp(value) {
    const amount = Number(value || 0);
    if (amount === 0) return "Rp 0";

    return `${amount > 0 ? "+" : "-"} ${formatRp(amount)}`;
}

function formatDateTime(value) {
    if (!value) return "Waktu tidak tersedia";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(date);
}

function eventTheme(kind) {
    if (kind === "correction") {
        return {
            icon: PackageX,
            iconClass: "bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300",
            badgeClass: "border-red-200 bg-red-50 text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300",
        };
    }
    if (kind === "ad_spend") {
        return {
            icon: Megaphone,
            iconClass: "bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300",
            badgeClass: "border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300",
        };
    }

    return {
        icon: ReceiptText,
        iconClass: "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300",
        badgeClass: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300",
    };
}

function EventRow({ event, onOpenOrder }) {
    const theme = eventTheme(event.kind);
    const Icon = theme.icon;
    const canOpenOrder = Boolean(event.order_id);

    return (
        <div className="grid gap-3 border-b border-slate-100 px-4 py-4 last:border-b-0 dark:border-slate-700/70 md:grid-cols-[minmax(0,1fr)_110px_120px_125px_28px] md:items-center md:px-5">
            <div className="flex min-w-0 items-start gap-3">
                <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${theme.iconClass}`}>
                    <Icon size={17} strokeWidth={1.8} aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className={`rounded-md border px-1.5 py-0.5 text-[10px] font-bold ${theme.badgeClass}`}>
                            {event.label}
                        </span>
                        {event.affected_ratio !== null && event.affected_ratio !== undefined && (
                            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-700 dark:text-slate-300">
                                {Math.round(Number(event.affected_ratio) * 100)}% item
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        disabled={!canOpenOrder}
                        onClick={() => canOpenOrder && onOpenOrder(event.order_id)}
                        className="mt-1 block max-w-full truncate text-left text-sm font-bold text-slate-800 hover:text-[#304674] disabled:cursor-default disabled:hover:text-slate-800 dark:text-white dark:hover:text-blue-300 dark:disabled:hover:text-white"
                    >
                        {event.order_sn || event.store_name || event.label}
                    </button>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                        {[event.store_name, event.platform, event.item_summary?.product_name].filter(Boolean).join(" / ")}
                    </p>
                    <p className="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                        {formatDateTime(event.occurred_at)}
                    </p>
                    {event.reason && (
                        <p className="mt-1 line-clamp-2 text-[11px] leading-4 text-slate-500 dark:text-slate-400">
                            {event.reason}
                        </p>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-3 gap-3 md:contents">
                <div className="min-w-0 md:text-right">
                    <p className="text-[9px] font-semibold uppercase text-slate-400 md:hidden">HPP</p>
                    <p className={`truncate text-xs font-semibold ${Number(event.hpp_amount || 0) < 0 ? "text-emerald-600 dark:text-emerald-400" : "text-slate-700 dark:text-slate-200"}`}>
                        {event.kind === "ad_spend" ? "-" : formatSignedRp(event.hpp_amount)}
                    </p>
                </div>
                <div className="min-w-0 md:text-right">
                    <p className="text-[9px] font-semibold uppercase text-slate-400 md:hidden">Escrow</p>
                    <p className={`truncate text-xs font-semibold ${Number(event.escrow_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-[#304674] dark:text-blue-300"}`}>
                        {event.kind === "ad_spend" ? "-" : formatSignedRp(event.escrow_amount)}
                    </p>
                </div>
                <div className="min-w-0 md:text-right">
                    <p className="text-[9px] font-semibold uppercase text-slate-400 md:hidden">Dampak Profit</p>
                    <p className={`truncate text-xs font-bold ${Number(event.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"}`}>
                        {formatSignedRp(event.profit_amount)}
                    </p>
                </div>
            </div>

            {canOpenOrder ? (
                <button
                    type="button"
                    onClick={() => onOpenOrder(event.order_id)}
                    className="hidden h-7 w-7 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-[#304674] dark:hover:bg-slate-700 dark:hover:text-blue-300 md:flex"
                    aria-label={`Buka pesanan ${event.order_sn}`}
                >
                    <ChevronRight size={16} aria-hidden="true" />
                </button>
            ) : <span className="hidden md:block" />}
        </div>
    );
}

export default function ProfitBreakdownModal({ isOpen, point, granularity, storeId, onClose }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [activeFilter, setActiveFilter] = useState("all");

    useEffect(() => {
        if (!isOpen || !point?.key) return undefined;

        const controller = new AbortController();
        setLoading(true);
        setError("");
        setData(null);
        setActiveFilter("all");

        const params = {
            bucket: point.key,
            granularity,
        };
        if (storeId) params.store_id = storeId;

        axios.get("/api/profit-tracker/breakdown", {
            params,
            signal: controller.signal,
        }).then((response) => {
            setData(response.data);
        }).catch((requestError) => {
            if (requestError.code !== "ERR_CANCELED") {
                setError(requestError.response?.data?.message || "Rincian profit belum dapat dimuat.");
            }
        }).finally(() => {
            if (!controller.signal.aborted) setLoading(false);
        });

        return () => controller.abort();
    }, [granularity, isOpen, point?.key, storeId]);

    useEffect(() => {
        if (!isOpen) return undefined;
        const previousOverflow = document.body.style.overflow;
        const closeOnEscape = (event) => {
            if (event.key === "Escape") onClose();
        };
        document.body.style.overflow = "hidden";
        document.addEventListener("keydown", closeOnEscape);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener("keydown", closeOnEscape);
        };
    }, [isOpen, onClose]);

    const events = useMemo(() => {
        const source = data?.events || [];
        return activeFilter === "all"
            ? source
            : source.filter((event) => event.kind === activeFilter);
    }, [activeFilter, data?.events]);

    const filterOptions = [
        { id: "all", label: "Semua", count: data?.events?.length || 0 },
        { id: "sale", label: "Penambah", count: data?.totals?.sale_count || 0 },
        { id: "correction", label: "Koreksi", count: data?.totals?.correction_count || 0 },
        { id: "ad_spend", label: "Iklan", count: data?.totals?.ad_spend_count || 0 },
    ];

    const openOrder = (orderId) => {
        onClose();
        window.location.hash = `#/orders/${orderId}`;
    };

    if (!isOpen || typeof document === "undefined") return null;

    return createPortal(
        <div className="fixed inset-0 z-[10020] flex items-center justify-center bg-slate-950/45 p-3 backdrop-blur-sm sm:p-5">
            <button type="button" className="absolute inset-0 cursor-default" onClick={onClose} aria-label="Tutup rincian profit" />
            <div role="dialog" aria-modal="true" aria-labelledby="profit-breakdown-title" className="relative z-10 flex max-h-[92dvh] w-full max-w-5xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 dark:border-slate-700 sm:px-5">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[#304674] dark:bg-blue-500/10 dark:text-blue-300">
                            <ReceiptText size={19} aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            <h2 id="profit-breakdown-title" className="text-base font-bold text-slate-900 dark:text-white">
                                Rincian Profit
                            </h2>
                            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{point?.label}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-white" aria-label="Tutup">
                        <X size={18} aria-hidden="true" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {loading && (
                        <div className="flex min-h-72 items-center justify-center gap-2 text-sm font-semibold text-slate-500 dark:text-slate-400">
                            <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />
                            Memuat rincian profit...
                        </div>
                    )}

                    {!loading && error && (
                        <div className="flex min-h-72 flex-col items-center justify-center gap-3 px-6 text-center">
                            <span className="flex h-11 w-11 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300">
                                <RotateCcw size={19} aria-hidden="true" />
                            </span>
                            <p className="text-sm font-semibold text-red-700 dark:text-red-300">{error}</p>
                        </div>
                    )}

                    {!loading && !error && data && (
                        <>
                            <div className="grid grid-cols-2 border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40 lg:grid-cols-4">
                                {[
                                    ["HPP", data.totals.hpp_amount, "text-slate-800 dark:text-white"],
                                    ["Escrow", data.totals.escrow_amount, "text-[#304674] dark:text-blue-300"],
                                    ["Iklan", -Number(data.totals.ad_spend_amount || 0), "text-orange-600 dark:text-orange-400"],
                                    ["Profit Bersih", data.totals.profit_amount, Number(data.totals.profit_amount || 0) < 0 ? "text-red-600 dark:text-red-400" : "text-emerald-600 dark:text-emerald-400"],
                                ].map(([label, value, tone], index) => (
                                    <div key={label} className={`min-w-0 px-4 py-3.5 ${index % 2 ? "border-l" : ""} ${index > 1 ? "border-t lg:border-t-0" : ""} border-slate-200 dark:border-slate-700 lg:border-l lg:first:border-l-0`}>
                                        <p className="text-[10px] font-bold uppercase text-slate-400">{label}</p>
                                        <p className={`mt-1 truncate text-sm font-bold ${tone}`}>{formatSignedRp(value)}</p>
                                    </div>
                                ))}
                            </div>

                            <div className="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 py-3 dark:border-slate-700 sm:px-5">
                                {filterOptions.map((option) => (
                                    <button
                                        key={option.id}
                                        type="button"
                                        onClick={() => setActiveFilter(option.id)}
                                        className={`shrink-0 rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${activeFilter === option.id
                                            ? "bg-[#304674] text-white dark:bg-blue-600"
                                            : "bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:hover:bg-slate-600"
                                        }`}
                                    >
                                        {option.label} {option.count}
                                    </button>
                                ))}
                            </div>

                            <div className="hidden grid-cols-[minmax(0,1fr)_110px_120px_125px_28px] border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-[10px] font-bold uppercase text-slate-400 dark:border-slate-700 dark:bg-slate-900/30 md:grid">
                                <span>Event</span>
                                <span className="text-right">HPP</span>
                                <span className="text-right">Escrow</span>
                                <span className="text-right">Dampak Profit</span>
                                <span />
                            </div>

                            {events.length > 0 ? (
                                <div>
                                    {events.map((event) => (
                                        <EventRow key={event.id} event={event} onOpenOrder={openOrder} />
                                    ))}
                                </div>
                            ) : (
                                <div className="flex min-h-48 flex-col items-center justify-center px-6 text-center text-slate-400">
                                    {activeFilter === "sale" ? <ArrowUpRight size={22} aria-hidden="true" /> : <ArrowDownRight size={22} aria-hidden="true" />}
                                    <p className="mt-2 text-sm font-semibold">Tidak ada data pada kategori ini.</p>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}
