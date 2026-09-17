import React, { useState, useEffect, useCallback } from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import { motion, AnimatePresence } from "framer-motion";

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

const STATUS_CONFIG_SHOPEE = {
    UNPAID: { label: "Belum Bayar" },
    READY_TO_SHIP: { label: "Perlu Dikirim" },
    PROCESSED: { label: "Telah Diproses" },
    SHIPPED: { label: "Dikirim" },
    TO_CONFIRM_RECEIVE: { label: "Perlu Diproses" },
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
    const [showRaw, setShowRaw] = useState(false);

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
                        
                        {/* Cancellation Information Card */}
                        {['CANCEL', 'CANCELLED', 'IN_CANCEL'].includes(order.order_status) && (
                            <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                                <div className="flex items-center justify-between pb-4 mb-4 border-b border-gray-100 dark:border-slate-700">
                                    <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                        <span className="material-symbols-rounded text-red-500">cancel</span>
                                        Informasi Pembatalan
                                    </h2>
                                    {order.normalized_cancel_category && (
                                        <span className="text-xs font-semibold px-3 py-1 rounded-full bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300">
                                            {order.normalized_cancel_category === 'SELLER_LATE_SHIPMENT' && 'Terlambat Dikirim Penjual'}
                                            {order.normalized_cancel_category === 'BUYER_SIDE' && 'Dari Sisi Buyer / Pembayaran'}
                                            {order.normalized_cancel_category === 'UNKNOWN' && 'Pembatalan Lainnya'}
                                        </span>
                                    )}
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="bg-gray-50/70 dark:bg-slate-900/40 p-4 rounded-xl border border-gray-100 dark:border-slate-700/50">
                                        <p className="text-xs text-gray-400 dark:text-slate-500 font-medium mb-1">Alasan Pembatalan</p>
                                        <p className="text-sm font-semibold text-gray-800 dark:text-slate-200 break-words">
                                            {order.cancel_reason || order.buyer_cancel_reason || 'Tidak ada rincian alasan'}
                                        </p>
                                    </div>

                                    <div className="bg-gray-50/70 dark:bg-slate-900/40 p-4 rounded-xl border border-gray-100 dark:border-slate-700/50">
                                        <p className="text-xs text-gray-400 dark:text-slate-500 font-medium mb-1">Inisiator Pembatalan</p>
                                        <p className="text-sm font-semibold text-gray-800 dark:text-slate-200 font-mono">
                                            {order.cancel_source || 'SYSTEM'}
                                        </p>
                                    </div>
                                </div>

                                {order.buyer_cancel_reason && order.cancel_reason && order.buyer_cancel_reason !== order.cancel_reason && (
                                    <div className="mt-3 bg-gray-50/70 dark:bg-slate-900/40 p-4 rounded-xl border border-gray-100 dark:border-slate-700/50">
                                        <p className="text-xs text-gray-400 dark:text-slate-500 font-medium mb-1">Catatan Tambahan Pembeli</p>
                                        <p className="text-sm text-gray-700 dark:text-slate-300 break-words">{order.buyer_cancel_reason}</p>
                                    </div>
                                )}
                            </div>
                        )}
                        
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

                        {/* 8. Return / Refund Section */}
                        <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                            <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                                <span className="material-symbols-rounded text-rose-500">assignment_return</span>
                                Riwayat Pengembalian (Return/Refund)
                            </h2>
                            
                            {order.returns && order.returns.length > 0 ? (
                                <div className="space-y-4">
                                    {order.returns.map((ret) => (
                                        <div key={ret.id} className="bg-rose-50/30 dark:bg-rose-900/10 rounded-xl p-5 border border-rose-100 dark:border-rose-900/30">
                                            <div className="flex flex-wrap justify-between gap-4 mb-4 pb-4 border-b border-rose-100 dark:border-rose-900/30">
                                                <div>
                                                    <p className="text-xs text-gray-500 font-mono mb-1 break-all">ID: {ret.external_return_id}</p>
                                                    <div className="flex gap-2 items-center flex-wrap">
                                                        <ReturnStatusBadge status={ret.normalized_status} platformStatus={ret.platform_status || ret.return_status} />
                                                        <span className="text-xs text-gray-500 bg-white dark:bg-slate-800 px-2 py-1 rounded border border-gray-200 dark:border-slate-600">Platform: {ret.platform_status || ret.return_status}</span>
                                                        {ret.return_type && (
                                                            <span className="text-xs text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 px-2 py-1 rounded border border-indigo-100 dark:border-indigo-800">
                                                                Type: {ret.return_type}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="text-right min-w-[120px]">
                                                    <p className="text-xs text-gray-500">Refund Amount</p>
                                                    <p className="font-bold text-rose-600 dark:text-rose-400 text-lg">{formatRp(ret.refund_amount)}</p>
                                                </div>
                                            </div>
                                            
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                                <div>
                                                    <p className="text-xs font-semibold text-gray-500 uppercase mb-1">Alasan Pengembalian</p>
                                                    <p className="text-sm text-gray-800 dark:text-slate-200 font-medium break-words [overflow-wrap:anywhere]">
                                                        {ret.text_reason || ret.return_reason || "Tidak ada alasan"}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-xs font-semibold text-gray-500 uppercase mb-1">Waktu (Platform)</p>
                                                    <p className="text-xs text-gray-600 dark:text-slate-400">Diajukan: {ret.created_at_platform || '-'}</p>
                                                    <p className="text-xs text-gray-600 dark:text-slate-400">Diupdate: {ret.updated_at_platform || '-'}</p>
                                                </div>
                                            </div>

                                            <div className="bg-white dark:bg-slate-800 rounded-lg p-3 border border-rose-50 dark:border-slate-700">
                                                <p className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Item yang dikembalikan:</p>
                                                <ul className="space-y-2">
                                                    {ret.items.map((item, idx) => (
                                                        <li key={idx} className="flex justify-between items-center text-sm border-b border-gray-50 dark:border-slate-700 pb-2 last:border-0 last:pb-0">
                                                            <span className="text-gray-700 dark:text-slate-300 line-clamp-1 flex-1 pr-4 font-medium break-words">{item.product_name || "Item"}</span>
                                                            <span className="font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/30 px-2 py-1 rounded text-xs whitespace-nowrap">Qty: {item.quantity}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8 bg-gray-50 dark:bg-slate-900/50 rounded-xl border border-dashed border-gray-200 dark:border-slate-700">
                                    <span className="material-symbols-rounded text-4xl text-gray-300 dark:text-slate-600 mb-2">task_alt</span>
                                    <p className="text-gray-500 dark:text-slate-400 font-medium">Tidak ada return / refund</p>
                                </div>
                            )}
                        </div>
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

                        {/* Logistics Information */}
                        {order.packages && order.packages.length > 0 && (
                            <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                                <h2 className="text-sm font-bold text-gray-900 dark:text-white mb-3 uppercase tracking-wider flex items-center gap-2 border-b border-gray-100 dark:border-slate-700 pb-2">
                                    <span className="material-symbols-rounded text-gray-400 text-lg">inventory</span>
                                    Status Logistik & Paket
                                </h2>
                                <div className="space-y-4">
                                    {order.packages.map((pkg, idx) => (
                                        <div key={idx} className="bg-slate-50 dark:bg-slate-900/50 p-3 rounded-xl border border-slate-100 dark:border-slate-700">
                                            <div className="flex justify-between items-center mb-2">
                                                <span className="text-xs font-bold text-gray-500 uppercase break-all pr-2">Paket: {pkg.package_id || (idx + 1)}</span>
                                                <span className={`text-xs font-bold px-2 py-1 rounded-md whitespace-nowrap ${
                                                    pkg.normalized_logistics_status === 'DELIVERY_FAILED' 
                                                    ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' 
                                                    : pkg.normalized_logistics_status === 'DELIVERED'
                                                    ? 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400'
                                                    : 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400'
                                                }`}>
                                                    {pkg.normalized_logistics_status === 'DELIVERY_FAILED' ? 'PENGIRIMAN GAGAL' : (pkg.normalized_logistics_status || 'UNKNOWN')}
                                                </span>
                                            </div>
                                            <div className="text-sm">
                                                <p className="text-gray-600 dark:text-slate-400 break-words"><span className="font-semibold text-gray-700 dark:text-slate-300">Resi (Tracking):</span> {pkg.tracking_number || '-'}</p>
                                                <p className="text-gray-600 dark:text-slate-400 mt-1 break-words"><span className="font-semibold text-gray-700 dark:text-slate-300">Detail Status:</span> {pkg.logistics_status || '-'}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* 5, 6, 7. Summary, Fees & Settlement */}
                        <div className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <h2 className="text-sm font-bold text-gray-900 dark:text-white mb-3 uppercase tracking-wider flex items-center gap-2 border-b border-gray-100 dark:border-slate-700 pb-2">
                                <span className="material-symbols-rounded text-gray-400 text-lg">receipt_long</span>
                                Rincian Keuangan
                            </h2>
                            
                            <div className="space-y-3 text-sm">
                                <div className="flex justify-between text-gray-600 dark:text-slate-400">
                                    <span>Total Pembayaran Pembeli</span>
                                    <span className="font-medium text-gray-800 dark:text-slate-200">{formatRp(order.order_selling_price)}</span>
                                </div>
                                
                                {order.fee_details && (
                                    <div className="pt-2 border-t border-dashed border-gray-200 dark:border-slate-700">
                                        <p className="text-xs font-bold text-gray-500 uppercase mb-2">Potongan & Fee Platform</p>
                                        <div className="bg-gray-50 dark:bg-slate-900/50 p-3 rounded-lg max-h-48 overflow-y-auto custom-scrollbar">
                                            <pre className="text-[10px] text-gray-600 dark:text-slate-400 font-mono whitespace-pre-wrap break-all">
                                                {typeof order.fee_details === 'string' ? order.fee_details : JSON.stringify(order.fee_details, null, 2)}
                                            </pre>
                                        </div>
                                    </div>
                                )}

                                <div className="pt-3 mt-3 border-t-2 border-gray-100 dark:border-slate-700">
                                    <div className="flex justify-between font-bold text-gray-900 dark:text-white text-base">
                                        <span>Estimasi Penghasilan Bersih</span>
                                        <span className="text-[#304674] dark:text-blue-400">{formatRp(order.escrow_amount)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* 9. Technical / Raw Data */}
                        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                            <button 
                                onClick={() => setShowRaw(!showRaw)}
                                className="w-full flex items-center justify-between p-4 text-sm font-bold text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
                            >
                                <span className="flex items-center gap-2">
                                    <span className="material-symbols-rounded text-lg">code</span>
                                    Developer Mode
                                </span>
                                <span className={`material-symbols-rounded transition-transform ${showRaw ? 'rotate-180' : ''}`}>expand_more</span>
                            </button>
                            <AnimatePresence>
                                {showRaw && (
                                    <motion.div 
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: "auto", opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        className="border-t border-gray-100 dark:border-slate-700"
                                    >
                                        <div className="p-4 bg-slate-900 text-slate-300 text-xs font-mono max-h-96 overflow-y-auto">
                                            {order.raw_data ? (
                                                <pre className="whitespace-pre-wrap break-all">
                                                    {JSON.stringify(order.raw_data, null, 2)}
                                                </pre>
                                            ) : (
                                                <p className="italic text-slate-500">Raw API data not available in database.</p>
                                            )}
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>

                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
