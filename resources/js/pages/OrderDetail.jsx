import React, { useState, useEffect, useCallback } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
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

function formatPercentage(value) {
    return Number(value || 0).toLocaleString("id-ID", {
        maximumFractionDigits: 2,
    }) + "%";
}

function logisticsStatusLabel(status) {
    if (status === "DELIVERY_FAILED") return "PENGANTARAN GAGAL";
    if (status === "DELIVERED") return "TERKIRIM";
    if (status === "IN_TRANSIT") return "DALAM PENGIRIMAN";

    return status || "BELUM DIKETAHUI";
}

const STATUS_CONFIG_SHOPEE = {
    UNPAID: { label: "Belum Bayar" },
    READY_TO_SHIP: { label: "Perlu Dikirim" },
    PROCESSED: { label: "Telah Diproses" },
    SHIPPED: { label: "Dikirim" },
    TO_CONFIRM_RECEIVE: { label: "Menunggu Konfirmasi Pembeli" },
    COMPLETED: { label: "Selesai" },
    CANCELLED: { label: "Batal" },
    IN_CANCEL: { label: "Pengajuan Batal" },
    TO_RETURN: { label: "Pengembalian", isReturn: true },
};

const STATUS_CONFIG_TIKTOK = {
    UNPAID: { label: "Belum Bayar" },
    ON_HOLD: { label: "Ditahan" },
    AWAITING_SHIPMENT: { label: "Menunggu pengiriman" },
    AWAITING_COLLECTION: { label: "Menunggu pengambilan" },
    IN_TRANSIT: { label: "Sedang Transit" },
    DELIVERED: { label: "Terkirim" },
    COMPLETED: { label: "Selesai" },
    CANCEL: { label: "Batal" },
    CANCELLED: { label: "Batal" },
};

function StatusBadge({ status, platform }) {
    let label = status || "Unknown";
    let isReturn = false;

    if (platform === "Tiktokshop" && STATUS_CONFIG_TIKTOK[status]) {
        label = STATUS_CONFIG_TIKTOK[status].label;
    } else if (platform === "Shopee" && STATUS_CONFIG_SHOPEE[status]) {
        label = STATUS_CONFIG_SHOPEE[status].label;
        isReturn = STATUS_CONFIG_SHOPEE[status].isReturn || false;
    } else if (STATUS_CONFIG_TIKTOK[status]) {
        label = STATUS_CONFIG_TIKTOK[status].label;
    } else if (STATUS_CONFIG_SHOPEE[status]) {
        label = STATUS_CONFIG_SHOPEE[status].label;
        isReturn = STATUS_CONFIG_SHOPEE[status].isReturn || false;
    }

    if (isReturn || status === "TO_RETURN") {
        return (
            <span className="text-xs font-bold text-red-600 dark:text-red-400">
                {label}
            </span>
        );
    }

    return (
        <span className="text-xs font-bold text-gray-700 dark:text-gray-400">
            {label}
        </span>
    );
}

const RETURN_LABELS = {
    PENDING: "Pengajuan Return/Refund",
    WAITING_FOR_BUYER: "Menunggu Pembeli Mengirim Barang",
    SHIPPED_BACK: "Barang Sedang Dikembalikan",
    PROCESSING_REFUND: "Proses Refund",
    REFUND_COMPLETED: "Return/Refund Selesai",
    REJECTED: "Return/Refund Ditolak",
    CANCELLED: "Return/Refund Dibatalkan",
    DISPUTED: "Return Disengketakan",
    APPROVED: "Return/Refund Disetujui",
    ITEM_RETURNED: "Barang Dikembalikan",
    COMPLETED: "Return/Refund Selesai",
    UNSUPPORTED: "Return/Refund Selesai",
};

function ReturnStatusBadge({ status, platformStatus }) {
    const label = RETURN_LABELS[status] || platformStatus || status || "Pengembalian";
    return (
        <span className="text-xs font-bold text-red-600 dark:text-red-400">
            {label}
        </span>
    );
}

export default function OrderDetail({ routeParams }) {
    const { id } = routeParams;
    const [order, setOrder] = useState(null);
    const [loading, setLoading] = useState(true);
    const [isLogisticsExpanded, setIsLogisticsExpanded] = useState(false);

    const fetchOrderDetail = useCallback((silent = false) => {
        if (!silent) setLoading(true);
        axios
            .get(`/api/orders/${id}`)
            .then((res) => {
                setOrder(res.data.order);
            })
            .catch((err) => {
                console.error(err);
            })
            .finally(() => {
                if (!silent) setLoading(false);
            });
    }, [id]);

    useEffect(() => {
        fetchOrderDetail();
    }, [fetchOrderDetail]);

    useEffect(() => {
        setIsLogisticsExpanded(false);
    }, [id]);

    // Real-time listener for order and return updates
    useEffect(() => {
        const handleOrderUpdate = (e) => {
            const updatedId = e.detail?.id;
            const updatedSn = e.detail?.order_sn;
            if (updatedId == id || (order && order.order_sn == updatedSn)) {
                console.log("OrderDetail: Order updated in real-time, refetching silently...", e.detail);
                fetchOrderDetail(true);
            }
        };

        window.addEventListener('order-updated', handleOrderUpdate);
        window.addEventListener('order-created', handleOrderUpdate);
        return () => {
            window.removeEventListener('order-updated', handleOrderUpdate);
            window.removeEventListener('order-created', handleOrderUpdate);
        };
    }, [id, order, fetchOrderDetail]);

    if (loading) {
        return (
            <AppLayout>
                <div className="w-full space-y-6 pb-20 animate-pulse">
                    {/* Header Skeleton */}
                    <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div className="space-y-3">
                                <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-24"></div>
                                <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded w-48"></div>
                                <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-32"></div>
                            </div>
                            <div className="space-y-3 flex flex-col items-end">
                                <div className="h-8 bg-slate-200 dark:bg-slate-700 rounded-full w-24"></div>
                                <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-20"></div>
                            </div>
                        </div>
                    </div>

                    {/* Products Skeleton */}
                    <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden p-6 space-y-4">
                        <div className="h-5 bg-slate-200 dark:bg-slate-700 rounded w-32 mb-4"></div>
                        {[1, 2].map((i) => (
                            <div key={i} className="flex gap-4">
                                <div className="w-20 h-20 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
                                <div className="flex-1 space-y-2 py-1">
                                    <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-3/4"></div>
                                    <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-1/4"></div>
                                    <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-1/4 mt-2"></div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Address & Grid Skeleton */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 space-y-4">
                            <div className="h-5 bg-slate-200 dark:bg-slate-700 rounded w-40"></div>
                            <div className="space-y-2">
                                <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-1/3"></div>
                                <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-2/3"></div>
                                <div className="h-3 bg-slate-200 dark:bg-slate-700 rounded w-1/2"></div>
                            </div>
                        </div>
                        <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 space-y-4">
                            <div className="h-5 bg-slate-200 dark:bg-slate-700 rounded w-32"></div>
                            <div className="space-y-3">
                                <div className="flex justify-between"><div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-24"></div><div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-32"></div></div>
                                <div className="flex justify-between"><div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-20"></div><div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-28"></div></div>
                                <div className="flex justify-between pt-2 border-t border-slate-100 dark:border-slate-700"><div className="h-5 bg-slate-200 dark:bg-slate-700 rounded w-24"></div><div className="h-5 bg-slate-200 dark:bg-slate-700 rounded w-32"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    if (!order) {
        return (
            <AppLayout>
                <div className="text-center py-20">
                    <p className="text-xl text-gray-500">Order not found</p>
                    <a href="#/orders" className="text-blue-500 hover:underline mt-4 inline-block">Back to Orders</a>
                </div>
            </AppLayout>
        );
    }

    const returns = order.returns || [];
    const packages = order.packages || [];
    const failedPackages = packages.filter(
        (pkg) => pkg.normalized_logistics_status === "DELIVERY_FAILED"
    );
    const hasReturnStatus = ["TO_RETURN", "RETURNED"].includes(order.order_status);
    const showReturnHistory = returns.length > 0 || failedPackages.length > 0 || hasReturnStatus;
    const logisticsEventCount = packages.reduce((total, pkg) => total + (pkg.history?.length || 0), 0);
    const hasLongLogisticsHistory = packages.some((pkg) => (pkg.history?.length || 0) > 3);
    const financialBreakdown = order.financial_breakdown || {
        gross_amount: order.order_selling_price,
        escrow_amount: order.escrow_amount,
        components: [],
        is_affiliate: false,
        affiliate_percentage: null,
        source: null,
        is_estimated: false,
        sync_pending: false,
    };

    return (
        <AppLayout>
            <div className="w-full space-y-6 pb-20">
                {/* 1. Order Header */}
                <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-3 mb-2 flex-wrap">
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (window.history.length > 1) {
                                            window.history.back();
                                        } else {
                                            window.location.hash = "#/orders";
                                        }
                                    }}
                                    className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200 transition-colors cursor-pointer flex items-center justify-center"
                                    title="Kembali ke Daftar Pesanan"
                                >
                                    <span className="material-symbols-rounded">arrow_back</span>
                                </button>
                                <h1 className="text-2xl font-bold text-gray-900 dark:text-white font-mono break-all">
                                    {order.order_sn}
                                </h1>
                                <StatusBadge status={order.order_status} platform={order.platform} />
                                {order.returns && order.returns.length > 0 && (
                                    <ReturnStatusBadge 
                                        status={order.returns[0].normalized_status} 
                                        platformStatus={order.returns[0].platform_status} 
                                    />
                                )}
                                <span className="px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300">
                                    {order.platform}
                                </span>
                                {financialBreakdown.is_affiliate && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800">
                                        <span className="material-symbols-rounded text-sm">campaign</span>
                                        Pesanan Affiliate
                                        {financialBreakdown.affiliate_percentage !== null && (
                                            <span>{formatPercentage(financialBreakdown.affiliate_percentage)}</span>
                                        )}
                                    </span>
                                )}
                            </div>
                            <div className="ml-9 text-sm text-gray-500 dark:text-slate-400 flex flex-col sm:flex-row sm:gap-6">
                                <p><span className="font-semibold">Store:</span> {order.store_name}</p>
                                <p><span className="font-semibold">Created:</span> {order.order_time || order.created_at}</p>
                                <p><span className="font-semibold">Updated:</span> {order.updated_at}</p>
                            </div>
                        </div>
                        <div className="text-left md:text-right ml-9 md:ml-0">
                            <p className="text-sm text-gray-500 dark:text-slate-400">Total Pembayaran</p>
                            <p className="text-3xl font-bold text-[#304674] dark:text-blue-400">
                                {formatRp(order.order_selling_price)}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    {/* LEFT COLUMN: Main Information */}
                    <div className="lg:col-span-2 space-y-6 min-w-0">
                        
                        {/* 2. Products / Order Items */}
                        <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                            <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                                <span className="material-symbols-rounded text-gray-400">inventory_2</span>
                                Produk Pesanan
                            </h2>
                            <div className="space-y-4">
                                {order.products.map((product, idx) => (
                                    <div key={idx} className="flex gap-4 p-4 rounded-xl border border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/50">
                                        {product.variant_image || product.image ? (
                                            <img
                                                src={product.variant_image || product.image}
                                                className="w-20 h-20 rounded-lg object-cover shadow-sm flex-shrink-0"
                                                alt=""
                                            />
                                        ) : (
                                            <div className="w-20 h-20 rounded-lg bg-gray-200 dark:bg-slate-700 flex items-center justify-center flex-shrink-0">
                                                <span className="material-symbols-rounded text-gray-400">image</span>
                                            </div>
                                        )}
                                        <div className="flex-1 min-w-0">
                                            <div className="flex justify-between items-start gap-2">
                                                <div className="min-w-0">
                                                    <h3 className="font-semibold text-gray-800 dark:text-white line-clamp-2 break-words">{product.product_name}</h3>
                                                    <div className="flex items-center gap-3 mt-1 text-xs text-gray-500 dark:text-slate-400 flex-wrap">
                                                        <span className="break-words">Var: <span className="font-medium text-gray-700 dark:text-slate-300">{product.model_name}</span></span>
                                                        {product.sku && <span className="break-all">SKU: <span className="font-mono text-gray-700 dark:text-slate-300">{product.sku}</span></span>}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex justify-between items-end mt-3 border-t border-gray-200 dark:border-slate-700 pt-2 flex-wrap gap-2">
                                                <div className="text-sm">
                                                    <span className="text-gray-500">Harga: </span>
                                                    <span className="font-semibold text-gray-700 dark:text-slate-300">{formatRp(product.price)}</span>
                                                    <span className="mx-2 text-gray-300">|</span>
                                                    <span className="text-gray-500">Qty: </span>
                                                    <span className="font-semibold text-gray-700 dark:text-slate-300">{product.quantity_purchased}</span>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs text-gray-500">Subtotal</p>
                                                    <p className="font-bold text-[#304674] dark:text-blue-400">
                                                        {formatRp(product.subtotal)}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Financial Summary */}
                        <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <div className="mb-3 flex items-center justify-between gap-2 border-b border-gray-100 pb-2 dark:border-slate-700">
                                <h2 className="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-gray-900 dark:text-white">
                                    <span className="material-symbols-rounded text-lg text-gray-400">receipt_long</span>
                                    Rincian Keuangan
                                </h2>
                                {financialBreakdown.is_affiliate && (
                                    <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                        Affiliate {formatPercentage(financialBreakdown.affiliate_percentage)}
                                    </span>
                                )}
                            </div>

                            <div className="text-sm">
                                {financialBreakdown.sync_pending && (
                                    <div className="mb-2 flex items-start gap-2 rounded-lg border border-blue-100 bg-blue-50/70 px-3 py-2 text-xs text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-300">
                                        <span className="material-symbols-rounded animate-spin text-base">progress_activity</span>
                                        <span>Rincian potongan sedang diminta dari platform dan akan diperbarui otomatis.</span>
                                    </div>
                                )}
                                {!financialBreakdown.sync_pending && financialBreakdown.is_estimated && (
                                    <div className="mb-2 rounded-lg border border-amber-100 bg-amber-50/70 px-3 py-2 text-xs leading-relaxed text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">
                                        Nilai ini masih estimasi. TikTok menyediakan rincian biaya final setelah statement transaksi tersedia.
                                    </div>
                                )}
                                <div className="flex items-start justify-between gap-4 py-2 text-gray-700 dark:text-slate-300">
                                    <span className="font-semibold">Penghasilan kotor</span>
                                    <span className="font-semibold tabular-nums">{formatRp(financialBreakdown.gross_amount)}</span>
                                </div>

                                {financialBreakdown.components?.map((component) => (
                                    <div key={component.key} className="flex items-start justify-between gap-3 py-2 text-gray-600 dark:text-slate-400">
                                        <div className="min-w-0">
                                            <span className="break-words">{component.label}</span>
                                            {component.is_affiliate && component.percentage !== null && (
                                                <span className="ml-2 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                                    {formatPercentage(component.percentage)}
                                                </span>
                                            )}
                                        </div>
                                        <span className={`shrink-0 font-medium tabular-nums ${
                                            component.operator === '-'
                                                ? 'text-rose-600 dark:text-rose-400'
                                                : 'text-emerald-600 dark:text-emerald-400'
                                        }`}>
                                            {component.operator} {formatRp(component.amount)}
                                        </span>
                                    </div>
                                ))}

                                <div className="mt-2 border-t-2 border-gray-200 pt-3 dark:border-slate-600">
                                    <div className="flex items-center justify-between gap-4 rounded-lg bg-blue-50 px-3 py-3 dark:bg-blue-900/20">
                                        <div>
                                            <p className="font-bold text-gray-900 dark:text-white">Escrow</p>
                                            <p className="text-[11px] text-gray-500 dark:text-slate-400">Penghasilan bersih dari platform</p>
                                        </div>
                                        <span className="text-base font-bold tabular-nums text-[#304674] dark:text-blue-300">
                                            {formatRp(financialBreakdown.escrow_amount)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Logistics Information */}
                        {packages.length > 0 && (
                            <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <div className="mb-4 flex items-center justify-between gap-3 border-b border-gray-100 pb-3 dark:border-slate-700">
                                    <div className="min-w-0">
                                        <h2 className="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-gray-900 dark:text-white">
                                            <span className="material-symbols-rounded text-lg text-gray-400">inventory</span>
                                            Riwayat Logistik & Paket
                                        </h2>
                                        <p className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                            {logisticsEventCount} aktivitas dari {packages.length} paket
                                        </p>
                                    </div>
                                    {hasLongLogisticsHistory && (
                                        <button
                                            type="button"
                                            onClick={() => setIsLogisticsExpanded((current) => !current)}
                                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:border-[#304674]/40 hover:bg-blue-50 hover:text-[#304674] dark:border-slate-600 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-blue-300"
                                            aria-expanded={isLogisticsExpanded}
                                            title={isLogisticsExpanded ? "Ringkas riwayat logistik" : "Tampilkan seluruh riwayat logistik"}
                                        >
                                            <span className="material-symbols-rounded text-xl">
                                                {isLogisticsExpanded ? "unfold_less" : "unfold_more"}
                                            </span>
                                        </button>
                                    )}
                                </div>

                                <div className="space-y-4">
                                    {packages.map((pkg, idx) => {
                                        const history = pkg.history || [];
                                        const visibleHistory = isLogisticsExpanded ? history : history.slice(0, 3);
                                        const hiddenCount = Math.max(0, history.length - visibleHistory.length);

                                        return (
                                            <div key={pkg.package_id || idx} className="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/50">
                                                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="text-xs font-bold uppercase text-gray-500 break-all">Paket: {pkg.package_id || (idx + 1)}</p>
                                                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400 break-all">
                                                            Resi: <span className="font-mono font-semibold text-gray-700 dark:text-slate-300">{pkg.tracking_number || '-'}</span>
                                                        </p>
                                                    </div>
                                                    <span className={`whitespace-nowrap rounded-md px-2 py-1 text-xs font-bold ${
                                                        pkg.normalized_logistics_status === 'DELIVERY_FAILED'
                                                            ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400'
                                                            : pkg.normalized_logistics_status === 'DELIVERED'
                                                            ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400'
                                                            : 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400'
                                                    }`}>
                                                        {logisticsStatusLabel(pkg.normalized_logistics_status)}
                                                    </span>
                                                </div>

                                                {visibleHistory.length > 0 ? (
                                                    <>
                                                        <ol className="relative ml-1 border-l border-slate-200 dark:border-slate-700">
                                                            {visibleHistory.map((event, eventIdx) => (
                                                                <li key={`${event.timestamp}-${event.action_code}-${eventIdx}`} className="relative ml-5 pb-5 last:pb-0">
                                                                    <span className={`absolute -left-[25px] top-1 h-2.5 w-2.5 rounded-full ring-4 ring-slate-50 dark:ring-slate-900 ${
                                                                        event.is_failed_delivery
                                                                            ? 'bg-red-500'
                                                                            : eventIdx === 0
                                                                            ? 'bg-[#304674] dark:bg-blue-400'
                                                                            : 'bg-slate-300 dark:bg-slate-600'
                                                                    }`}></span>
                                                                    <p className={`text-sm leading-relaxed break-words ${
                                                                        event.is_failed_delivery
                                                                            ? 'font-semibold text-red-700 dark:text-red-300'
                                                                            : 'text-gray-700 dark:text-slate-300'
                                                                    }`}>
                                                                        {event.description}
                                                                    </p>
                                                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-400 dark:text-slate-500">
                                                                        <span>{formatDateTime(event.occurred_at)}</span>
                                                                        {event.action_code && <span className="font-mono">#{event.action_code}</span>}
                                                                    </div>
                                                                </li>
                                                            ))}
                                                        </ol>
                                                        {hiddenCount > 0 && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setIsLogisticsExpanded(true)}
                                                                className="mt-4 flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-slate-200 px-3 py-2 text-xs font-semibold text-[#304674] transition-colors hover:border-[#304674]/40 hover:bg-blue-50 dark:border-slate-700 dark:text-blue-300 dark:hover:bg-slate-800"
                                                            >
                                                                Tampilkan {hiddenCount} aktivitas lainnya
                                                                <span className="material-symbols-rounded text-base">expand_more</span>
                                                            </button>
                                                        )}
                                                    </>
                                                ) : (
                                                    <div className="rounded-lg border border-dashed border-slate-200 p-3 dark:border-slate-700">
                                                        <p className="text-xs font-medium text-gray-700 dark:text-slate-300 break-words">{pkg.logistics_status || 'Riwayat tracking belum tersedia'}</p>
                                                        <p className="mt-1 text-[11px] text-gray-400 dark:text-slate-500">Status terakhir dari platform</p>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                    </div>

                    {/* RIGHT COLUMN: Sidebar Info */}
                    <div className="space-y-6 min-w-0">
                        
                        {/* 3. Customer Information */}
                        <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <h2 className="text-sm font-bold text-gray-900 dark:text-white mb-3 uppercase tracking-wider flex items-center gap-2 border-b border-gray-100 dark:border-slate-700 pb-2">
                                <span className="material-symbols-rounded text-gray-400 text-lg">person</span>
                                Info Pembeli
                            </h2>
                            {order.customer_info ? (
                                <div className="space-y-2 text-sm">
                                    <div>
                                        <p className="text-gray-500 text-xs">Nama</p>
                                        <p className="font-medium text-gray-800 dark:text-slate-200 break-words [overflow-wrap:anywhere]">{order.customer_info.name || '-'}</p>
                                    </div>
                                    <div>
                                        <p className="text-gray-500 text-xs">Telepon</p>
                                        <p className="font-medium text-gray-800 dark:text-slate-200 break-all">{order.customer_info.phone || '-'}</p>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-gray-400 italic">Data pembeli belum tersedia</p>
                            )}
                        </div>

                        {/* 4. Shipping Information */}
                        <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <h2 className="text-sm font-bold text-gray-900 dark:text-white mb-3 uppercase tracking-wider flex items-center gap-2 border-b border-gray-100 dark:border-slate-700 pb-2">
                                <span className="material-symbols-rounded text-gray-400 text-lg">local_shipping</span>
                                Info Pengiriman
                            </h2>
                            {order.shipping_info ? (
                                <div className="space-y-3 text-sm">
                                    <div className="flex justify-between items-center flex-wrap gap-2 bg-blue-50 dark:bg-blue-900/10 p-2.5 rounded-xl border border-blue-100 dark:border-blue-900/30">
                                        <span className="font-semibold text-blue-800 dark:text-blue-300 break-words">{order.shipping_info.provider || 'Kurir'}</span>
                                        <span className="font-mono text-xs font-bold text-gray-600 dark:text-slate-400 break-all">{order.shipping_info.tracking_number || '-'}</span>
                                    </div>
                                    <div>
                                        <p className="text-gray-500 text-xs mb-1">Alamat Pengiriman</p>
                                        <p className="text-gray-700 dark:text-slate-300 leading-relaxed bg-gray-50 dark:bg-slate-900/50 p-2.5 rounded-lg border border-gray-100 dark:border-slate-700 break-words break-all [overflow-wrap:anywhere] whitespace-pre-wrap">
                                            {order.shipping_info.address || '-'}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-gray-400 italic">Data pengiriman belum tersedia</p>
                            )}
                        </div>

                        {/* Cancellation Information */}
                        {['CANCEL', 'CANCELLED', 'IN_CANCEL'].includes(order.order_status) && (
                            <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <div className="mb-3 border-b border-gray-100 pb-3 dark:border-slate-700">
                                    <h2 className="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-gray-900 dark:text-white">
                                        <span className="material-symbols-rounded text-lg text-red-500">cancel</span>
                                        Informasi Pembatalan
                                    </h2>
                                    {order.normalized_cancel_category && (
                                        <span className="mt-2 inline-flex rounded-md bg-gray-100 px-2 py-1 text-[11px] font-semibold text-gray-700 dark:bg-slate-700 dark:text-slate-300">
                                            {order.normalized_cancel_category === 'SELLER_LATE_SHIPMENT' && 'Terlambat Dikirim Penjual'}
                                            {order.normalized_cancel_category === 'BUYER_SIDE' && 'Dari Sisi Buyer / Pembayaran'}
                                            {order.normalized_cancel_category === 'UNKNOWN' && 'Pembatalan Lainnya'}
                                        </span>
                                    )}
                                </div>

                                <div className="space-y-3 text-sm">
                                    <div className="rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-slate-700/50 dark:bg-slate-900/40">
                                        <p className="mb-1 text-xs font-medium text-gray-400 dark:text-slate-500">Alasan Pembatalan</p>
                                        <p className="font-semibold text-gray-800 dark:text-slate-200 break-words">
                                            {order.cancel_reason || order.buyer_cancel_reason || 'Tidak ada rincian alasan'}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-slate-700/50 dark:bg-slate-900/40">
                                        <p className="mb-1 text-xs font-medium text-gray-400 dark:text-slate-500">Inisiator Pembatalan</p>
                                        <p className="font-mono font-semibold text-gray-800 dark:text-slate-200">{order.cancel_source || 'SYSTEM'}</p>
                                    </div>
                                    {order.buyer_cancel_reason && order.cancel_reason && order.buyer_cancel_reason !== order.cancel_reason && (
                                        <div className="rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-slate-700/50 dark:bg-slate-900/40">
                                            <p className="mb-1 text-xs font-medium text-gray-400 dark:text-slate-500">Catatan Tambahan Pembeli</p>
                                            <p className="text-gray-700 dark:text-slate-300 break-words">{order.buyer_cancel_reason}</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Return / Refund and Failed Delivery */}
                        {showReturnHistory && (
                            <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <h2 className="mb-4 flex items-center gap-2 border-b border-gray-100 pb-3 text-sm font-bold uppercase tracking-wider text-gray-900 dark:border-slate-700 dark:text-white">
                                    <span className="material-symbols-rounded text-lg text-rose-500">assignment_return</span>
                                    Return & Pengantaran Gagal
                                </h2>

                                <div className="space-y-4">
                                    {failedPackages.map((pkg, idx) => {
                                        const failureEvent = (pkg.history || []).find((event) => event.is_failed_delivery);

                                        return (
                                            <div key={`failed-${pkg.package_id || idx}`} className="rounded-lg border border-red-100 bg-red-50/50 p-3 dark:border-red-900/30 dark:bg-red-900/10">
                                                <div className="mb-2 flex flex-wrap items-center gap-2">
                                                    <span className="text-xs font-bold text-red-700 dark:text-red-300">Pengantaran Gagal</span>
                                                    <span className="rounded border border-red-100 bg-white px-1.5 py-0.5 text-[10px] text-gray-500 dark:border-red-900/30 dark:bg-slate-800">
                                                        Paket {pkg.package_id || idx + 1}
                                                    </span>
                                                </div>
                                                <p className="text-sm font-medium text-gray-800 dark:text-slate-200 break-words">
                                                    {failureEvent?.description || pkg.logistics_status || "Paket tidak berhasil diantarkan"}
                                                </p>
                                                <div className="mt-2 space-y-1 text-xs text-gray-500 dark:text-slate-400">
                                                    <p>Resi: <span className="font-mono break-all">{pkg.tracking_number || "-"}</span></p>
                                                    <p>{formatDateTime(pkg.failed_at || failureEvent?.occurred_at)}</p>
                                                </div>
                                            </div>
                                        );
                                    })}

                                    {returns.map((ret) => (
                                        <div key={ret.id} className="rounded-lg border border-rose-100 bg-rose-50/30 p-3 dark:border-rose-900/30 dark:bg-rose-900/10">
                                            <p className="mb-1 text-[10px] font-mono text-gray-500 break-all">ID: {ret.external_return_id}</p>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <ReturnStatusBadge status={ret.normalized_status} platformStatus={ret.platform_status || ret.return_status} />
                                                {ret.return_type && (
                                                    <span className="rounded border border-indigo-100 bg-indigo-50 px-1.5 py-0.5 text-[10px] text-indigo-600 dark:border-indigo-800 dark:bg-indigo-900/30">{ret.return_type}</span>
                                                )}
                                            </div>
                                            <div className="mt-3 border-t border-rose-100 pt-3 dark:border-rose-900/30">
                                                <p className="text-xs font-semibold uppercase text-gray-500">Alasan</p>
                                                <p className="mt-1 text-sm font-medium text-gray-800 dark:text-slate-200 break-words [overflow-wrap:anywhere]">
                                                    {ret.text_reason || ret.return_reason || "Tidak ada alasan"}
                                                </p>
                                            </div>
                                            <div className="mt-3 flex items-end justify-between gap-3">
                                                <div className="text-[11px] text-gray-500 dark:text-slate-400">
                                                    <p>Diajukan: {ret.created_at_platform || '-'}</p>
                                                    <p>Diperbarui: {ret.updated_at_platform || '-'}</p>
                                                </div>
                                                <div className="shrink-0 text-right">
                                                    <p className="text-[10px] text-gray-500">Refund</p>
                                                    <p className="font-bold text-rose-600 dark:text-rose-400">{formatRp(ret.refund_amount)}</p>
                                                </div>
                                            </div>
                                            {ret.items?.length > 0 && (
                                                <ul className="mt-3 space-y-2 border-t border-rose-100 pt-3 dark:border-rose-900/30">
                                                    {ret.items.map((item, idx) => (
                                                        <li key={idx} className="flex items-start justify-between gap-2 text-xs">
                                                            <span className="line-clamp-2 min-w-0 flex-1 font-medium text-gray-700 dark:text-slate-300">{item.product_name || "Item"}</span>
                                                            <span className="shrink-0 font-bold text-rose-600 dark:text-rose-400">x{item.quantity}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    ))}

                                    {hasReturnStatus && returns.length === 0 && failedPackages.length === 0 && (
                                        <div className="flex items-start gap-3 rounded-lg border border-rose-100 bg-rose-50/30 p-3 dark:border-rose-900/30 dark:bg-rose-900/10">
                                            <span className="material-symbols-rounded text-rose-500">assignment_return</span>
                                            <div>
                                                <p className="text-sm font-semibold text-gray-800 dark:text-slate-200">Pengembalian sedang diproses</p>
                                                <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">Detail dari platform belum tersedia.</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
