import React, {
    useState,
    useEffect,
    useCallback,
    useRef,
    Fragment,
} from "react";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import { motion, AnimatePresence } from "framer-motion";
import OnboardingTour from "@/components/OnboardingTour";
import ScrollToTopButton from "@/components/ScrollToTopButton";
import { useOnboarding } from "@/hooks/useOnboarding";
import { OrderSyncStatus } from "@/components/OrderSyncStatus";
import { useOrderSyncStatus } from "@/hooks/useOrderSyncStatus";

const ORDER_TOUR_STEPS = [
    {
        selector: "#tour-filter-group",
        title: "Filter Pesanan",
        description: "Gunakan filter ini untuk melihat pesanan berdasarkan statusnya, seperti Perlu Dikirim, Dikirim, Selesai, atau Batal.",
        position: "bottom",
    },
    {
        selector: "#tour-order-table tbody tr:first-child, #tour-order-table .tour-order-mobile-card:first-child, #tour-order-table",
        title: "Daftar Pesanan",
        description: "Ini adalah daftar pesanan dari semua toko Anda. Anda bisa melihat status, produk, informasi logistik, dan nilai estimasi profit per pesanan.",
        position: "top",
    },
    {
        selector: null,
        title: "Detail Pesanan",
        description: "Klik pada salah satu baris pesanan untuk melihat halaman detail lengkap, termasuk informasi buyer dan riwayat pengiriman.",
        position: "bottom",
    },
];


function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
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

const FILTER_GROUPS = [
    { id: "semua", label: "Semua", statuses: [] },
    { id: "perlu_dikirim", label: "Perlu Dikirim", statuses: ["READY_TO_SHIP", "AWAITING_SHIPMENT", "AWAITING_COLLECTION", "PROCESSED"] },
    { id: "dikirim", label: "Dikirim", statuses: ["SHIPPED", "IN_TRANSIT", "DELIVERED", "TO_CONFIRM_RECEIVE"] },
    { id: "selesai", label: "Selesai", statuses: ["COMPLETED"] },
    { id: "gagal_kirim", label: "Pengantaran Gagal", statuses: [] },
    { id: "return", label: "Pengembalian/Refund", statuses: [] },
    { id: "batal", label: "Pembatalan", statuses: ["CANCEL", "CANCELLED", "IN_CANCEL"] },
];

const PLATFORM_FILTERS = [
    { id: "all", label: "Semua" },
    { id: "Shopee", label: "Shopee" },
    { id: "Tiktokshop", label: "TikTok Shop" },
];

const SHIPPING_PROCESS_FILTERS = [
    { id: "all", label: "Semua" },
    { id: "needs_processing", label: "Perlu Diproses" },
    { id: "processed", label: "Telah Diproses" },
];

const PLATFORM_CONFIG = {
    Shopee: {
        bg: "bg-orange-50 dark:bg-orange-500/10",
        text: "text-orange-600 dark:text-orange-400",
        icon: (
            <img
                src="/Marketplace-logo/shopee.png"
                alt="Shopee"
                className="w-5 h-5 object-contain"
            />
        ),
    },
    Tokopedia: {
        bg: "bg-green-50 dark:bg-green-500/10",
        text: "text-green-600 dark:text-green-400",
        icon: "T",
    },
    Tiktokshop: {
        bg: "bg-gray-100 dark:bg-slate-700",
        text: "text-black dark:text-white",
        icon: (
            <img
                src="/Marketplace-logo/tts.png"
                alt="Tiktokshop"
                className="w-5 h-5 object-contain"
            />
        ),
    },
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
        <span className="text-xs font-bold text-red-700 dark:text-red-400">
            {label}
        </span>
    );
}

function SkeletonRow() {
    return (
        <div className="animate-pulse flex items-center gap-4 p-5 border-b border-gray-50 dark:border-slate-700/50">
            <div className="w-8 h-8 bg-slate-200 dark:bg-slate-700 rounded"></div>
            <div className="flex-1 space-y-2">
                <div className="h-4 w-40 bg-slate-200 dark:bg-slate-700 rounded"></div>
                <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded"></div>
            </div>
            <div className="h-6 w-20 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
        </div>
    );
}

function LimitDropdown({ value, onChange }) {
    const [isOpen, setIsOpen] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target))
                setIsOpen(false);
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () =>
            document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    return (
        <div className="relative" ref={dropdownRef}>
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="group flex items-center gap-2 px-3 py-1.5 rounded-full hover:bg-gray-100 dark:hover:bg-slate-800 border border-transparent hover:border-slate-200 dark:hover:border-slate-700 transition-all focus:outline-none"
            >
                <span className="text-sm font-semibold text-slate-700 dark:text-slate-200 group-hover:text-[#304674] dark:group-hover:text-blue-400 transition-colors">
                    {value}
                </span>
                <span
                    className={`material-symbols-rounded text-slate-400 transition-transform duration-300 ${isOpen ? "rotate-180" : ""}`}
                >
                    expand_more
                </span>
            </button>
            <AnimatePresence>
                {isOpen && (
                    <motion.div
                        initial={{ opacity: 0, y: 10, scale: 0.95 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 10, scale: 0.95 }}
                        transition={{ duration: 0.2, ease: "easeOut" }}
                        className="absolute left-0 bottom-full mb-2 w-32 bg-white dark:bg-slate-900 rounded-2xl shadow-lg border border-slate-100 dark:border-slate-800 overflow-hidden origin-bottom-left z-50"
                    >
                        {[10, 30, 50, 100].map((num) => (
                            <button
                                key={num}
                                onClick={() => {
                                    onChange(num);
                                    setIsOpen(false);
                                }}
                                className={`w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-slate-800 transition-colors ${value === num ? "font-bold text-[#304674] dark:text-blue-400 bg-gray-50/50 dark:bg-slate-800/50" : "text-gray-600 dark:text-slate-300"}`}
                            >
                                {num} / halaman
                            </button>
                        ))}
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

const STORAGE_KEY = "finesheet_order_list_state";

function getNotificationNavigationState() {
    const [, queryString = ""] = window.location.hash.split("?");
    const params = new URLSearchParams(queryString);

    if (params.get("source") !== "notification" || !params.get("store_id")) {
        return null;
    }

    return {
        selectedStore: params.get("store_id"),
        selectedFilterId: params.get("filter") || "perlu_dikirim",
        selectedShippingProcess: params.get("shipping_process") || "needs_processing",
    };
}

function getSavedOrderListState() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (raw) {
            return JSON.parse(raw);
        }
    } catch (e) {
        console.error("Failed to read order list state from sessionStorage", e);
    }
    return null;
}

const formatDate = (dateString) => {
    if (!dateString) return '-';
    let dStr = dateString;
    if (typeof dStr === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dStr)) {
        dStr = dStr + 'T00:00:00+07:00';
    } else if (typeof dStr === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(dStr)) {
        dStr = dStr.replace(' ', 'T') + '+07:00';
    }
    const date = new Date(dStr);
    if (isNaN(date.getTime())) return dateString;

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }).format(date);
};

const isReturnOrCancel = (order) => {
    if (!order) return false;
    
    if (order.order_status) {
        const status = String(order.order_status).toUpperCase().trim();
        if (['CANCEL', 'CANCELLED', 'IN_CANCEL', 'TO_RETURN', 'RETURNED'].includes(status)) {
            return true;
        }
    }

    if (order.returns && order.returns.length > 0) {
        return true;
    }

    if (order.packages && order.packages.some(p => p.normalized_logistics_status === 'DELIVERY_FAILED')) {
        return true;
    }

    return false;
};

export default function OrderList() {
    const savedStateRef = useRef(getSavedOrderListState());
    const initialSaved = savedStateRef.current;
    const notificationNavigationRef = useRef(getNotificationNavigationState());
    const initialNavigation = notificationNavigationRef.current;
    const tour = useOnboarding("orders", ORDER_TOUR_STEPS.length);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selectedFilterId, setSelectedFilterId] = useState(
        () => initialNavigation?.selectedFilterId || initialSaved?.selectedFilterId || "perlu_dikirim"
    );
    const [selectedCancelCategory, setSelectedCancelCategory] = useState(
        () => initialSaved?.selectedCancelCategory || "all"
    );
    const [selectedStore, setSelectedStore] = useState(
        () => initialNavigation?.selectedStore || (initialSaved?.selectedStore ? String(initialSaved.selectedStore) : "")
    );
    const [selectedPlatform, setSelectedPlatform] = useState(
        () => initialSaved?.selectedPlatform || "all"
    );
    const [selectedShippingProcess, setSelectedShippingProcess] = useState(
        () => initialNavigation?.selectedShippingProcess || initialSaved?.selectedShippingProcess || "needs_processing"
    );
    const [searchQuery, setSearchQuery] = useState(
        () => initialNavigation ? "" : initialSaved?.searchQuery || ""
    );
    const [expandedOrders, setExpandedOrders] = useState(
        () => initialNavigation ? {} : initialSaved?.expandedOrders || {}
    );
    const storeDropdownRef = useRef(null);
    const [isStoreDropdownOpen, setIsStoreDropdownOpen] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [syncingStoreId, setSyncingStoreId] = useState(null);
    const [limitByStore, setLimitByStore] = useState(
        () => initialSaved?.limitByStore || {}
    );
    const [pageByStore, setPageByStore] = useState(
        () => initialNavigation ? {} : initialSaved?.pageByStore || {}
    );
    const [copiedSn, setCopiedSn] = useState(null);
    const [excludeReturns, setExcludeReturns] = useState(false);
    const {
        syncs,
        isAnySyncing,
        recentlyCompleted,
        refresh: refreshSyncStatus,
    } = useOrderSyncStatus();
    const isSyncBusy = syncing || syncingStoreId !== null || isAnySyncing;

    const isInitialMount = useRef(true);
    const scrollRestoredRef = useRef(false);
    const isRestoringScrollRef = useRef(Boolean(!initialNavigation && initialSaved?.scrollTop && initialSaved.scrollTop > 0));
    const scrollPosRef = useRef(initialNavigation ? 0 : initialSaved?.scrollTop || 0);

    useEffect(() => {
        const applyNotificationNavigation = () => {
            const navigation = getNotificationNavigationState();
            if (!navigation) return;

            setSelectedStore(navigation.selectedStore);
            setSelectedFilterId("perlu_dikirim");
            setSelectedShippingProcess("needs_processing");
            setSelectedPlatform("all");
            setSelectedCancelCategory("all");
            setSearchQuery("");
            setExpandedOrders({});
            setPageByStore({});
            isRestoringScrollRef.current = false;
            scrollPosRef.current = 0;

            window.history.replaceState(null, "", `${window.location.pathname}#/orders`);
        };

        applyNotificationNavigation();
        window.addEventListener("hashchange", applyNotificationNavigation);
        return () => window.removeEventListener("hashchange", applyNotificationNavigation);
    }, []);

    const getScrollTop = () => {
        const container = document.getElementById("main-scroll-container");
        if (container && container.scrollTop !== undefined && container.scrollTop > 0) {
            return container.scrollTop;
        }
        return window.scrollY || document.documentElement.scrollTop || 0;
    };

    const setScrollTop = (top) => {
        const container = document.getElementById("main-scroll-container");
        if (container) {
            container.scrollTop = top;
        }
        window.scrollTo(0, top);
    };

    const saveStateToStorage = useCallback((overrideScrollTop) => {
        const currentScroll = overrideScrollTop !== undefined
            ? overrideScrollTop
            : (isRestoringScrollRef.current ? scrollPosRef.current : getScrollTop());

        scrollPosRef.current = currentScroll;

        const stateToSave = {
            selectedFilterId,
            selectedCancelCategory,
            selectedStore,
            selectedPlatform,
            selectedShippingProcess,
            searchQuery,
            limitByStore,
            pageByStore,
            expandedOrders,
            scrollTop: currentScroll,
        };
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(stateToSave));
        } catch (e) {
            console.error("Failed to save state to sessionStorage", e);
        }
    }, [selectedFilterId, selectedCancelCategory, selectedStore, selectedPlatform, selectedShippingProcess, searchQuery, limitByStore, pageByStore, expandedOrders]);

    const handleNavigateToDetail = (orderId) => {
        const currentPos = getScrollTop();
        saveStateToStorage(currentPos);
        const url = `${window.location.origin}${window.location.pathname}#/orders/${orderId}`;
        window.open(url, "_blank");
    };

    const handleCopySn = (e, sn) => {
        e.stopPropagation();
        navigator.clipboard.writeText(sn);
        setCopiedSn(sn);
        setTimeout(() => setCopiedSn(null), 2000);
    };

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }
        setPageByStore({});
        scrollPosRef.current = 0;
        setScrollTop(0);
    }, [searchQuery, selectedFilterId, selectedCancelCategory, selectedStore, selectedPlatform, selectedShippingProcess]);

    // Persist state changes
    useEffect(() => {
        if (!isInitialMount.current) {
            saveStateToStorage();
        }
    }, [selectedFilterId, selectedCancelCategory, selectedStore, selectedPlatform, selectedShippingProcess, searchQuery, limitByStore, pageByStore, expandedOrders, saveStateToStorage]);

    // Track scroll events
    useEffect(() => {
        const container = document.getElementById("main-scroll-container") || window;
        let timeoutId = null;

        const handleScroll = () => {
            if (isRestoringScrollRef.current) return;
            const pos = getScrollTop();
            scrollPosRef.current = pos;

            if (timeoutId) clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                saveStateToStorage(pos);
            }, 150);
        };

        container.addEventListener("scroll", handleScroll, { passive: true });
        return () => {
            if (timeoutId) clearTimeout(timeoutId);
            container.removeEventListener("scroll", handleScroll);
        };
    }, [saveStateToStorage]);

    // Save on beforeunload (refresh) and unmount
    useEffect(() => {
        const handleBeforeUnload = () => {
            saveStateToStorage(getScrollTop());
        };
        window.addEventListener("beforeunload", handleBeforeUnload);
        return () => {
            window.removeEventListener("beforeunload", handleBeforeUnload);
            saveStateToStorage(getScrollTop());
        };
    }, [saveStateToStorage]);

    const handleSync = async () => {
        if (isSyncBusy) return;

        setSyncing(true);
        try {
            await axios.post("/api/sync/orders");
            await refreshSyncStatus();
        } catch (err) {
            console.error("Failed to sync orders", err);
        } finally {
            setSyncing(false);
        }
    };

    const handleSyncStore = async (storeId) => {
        if (isSyncBusy) return;

        setSyncingStoreId(storeId);
        try {
            await axios.post("/api/sync/orders", { store_id: storeId });
            await refreshSyncStatus();
        } catch (err) {
            console.error(`Failed to sync orders for store ${storeId}`, err);
        } finally {
            setSyncingStoreId(null);
        }
    };

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (
                storeDropdownRef.current &&
                !storeDropdownRef.current.contains(e.target)
            ) {
                setIsStoreDropdownOpen(false);
            }
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () =>
            document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    const abortControllerRef = useRef(null);
    const fetchRequestIdRef = useRef(0);

    const fetchData = useCallback(async (silent = false) => {
        const requestId = fetchRequestIdRef.current + 1;
        fetchRequestIdRef.current = requestId;
        if (!silent) setLoading(true);
        
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
        abortControllerRef.current = new AbortController();
        
        try {
            const params = {};
            if (selectedFilterId === "gagal_kirim") {
                params.is_failed_delivery = 'true';
            } else if (selectedFilterId === "return") {
                params.is_return = 'true';
            } else {
                const filterGroup = FILTER_GROUPS.find(g => g.id === selectedFilterId);
                if (filterGroup && filterGroup.statuses.length > 0) {
                    params.statuses = filterGroup.statuses.join(",");
                }
                if (selectedFilterId === "perlu_dikirim" && selectedShippingProcess !== "all") {
                    params.shipping_process = selectedShippingProcess;
                }
                if (selectedFilterId === "batal" && selectedCancelCategory && selectedCancelCategory !== "all") {
                    params.cancel_category = selectedCancelCategory;
                }
            }
            if (selectedStore) params.store_id = selectedStore;
            if (selectedPlatform !== "all") params.platform = selectedPlatform;
            
            const res = await axios.get("/api/orders", { 
                params,
                signal: abortControllerRef.current.signal
            });
            if (requestId === fetchRequestIdRef.current) {
                setData(res.data);
            }
        } catch (err) {
            if (!axios.isCancel(err)) {
                console.error(err);
            }
        } finally {
            if (!silent && requestId === fetchRequestIdRef.current) {
                setLoading(false);
            }
        }
    }, [selectedFilterId, selectedCancelCategory, selectedStore, selectedPlatform, selectedShippingProcess]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    useEffect(() => {
        if (!selectedStore || !Array.isArray(data?.stores)) {
            return;
        }

        const storeStillExists = data.stores.some(
            (store) => String(store.id) === String(selectedStore),
        );

        if (!storeStillExists) {
            setSelectedStore("");
            setIsStoreDropdownOpen(false);
        }
    }, [data?.stores, selectedStore]);

    useEffect(() => {
        if (recentlyCompleted) {
            fetchData(true);
        }
    }, [recentlyCompleted, fetchData]);

    // Keep a ref to the latest fetchData so the event listener is always up-to-date
    // without needing to re-register on every filter change
    const fetchDataRef = useRef(fetchData);
    const realtimeRefreshTimeoutRef = useRef(null);
    useEffect(() => {
        fetchDataRef.current = fetchData;
    });

    // Listen to real-time events to auto-refresh order list seamlessly
    useEffect(() => {
        const handleOrderEvent = () => {
            if (realtimeRefreshTimeoutRef.current) {
                clearTimeout(realtimeRefreshTimeoutRef.current);
            }

            realtimeRefreshTimeoutRef.current = setTimeout(() => {
                realtimeRefreshTimeoutRef.current = null;
                fetchDataRef.current(true);
            }, 3000);
        };

        window.addEventListener('order-created', handleOrderEvent);
        window.addEventListener('order-updated', handleOrderEvent);
        return () => {
            if (realtimeRefreshTimeoutRef.current) {
                clearTimeout(realtimeRefreshTimeoutRef.current);
            }
            window.removeEventListener('order-created', handleOrderEvent);
            window.removeEventListener('order-updated', handleOrderEvent);
        };
    }, []); // Empty deps: register once, always calls latest fetchData via ref

    // Poll only while Reverb is unavailable. A connected WebSocket already
    // delivers order changes and does not need a second refresh mechanism.
    useEffect(() => {
        const refreshIfRealtimeUnavailable = () => {
            const connectionState = window.Echo?.connector?.pusher?.connection?.state;
            const realtimeConnected = connectionState === 'connected';

            if (document.visibilityState === 'visible' && !realtimeConnected) {
                fetchDataRef.current(true);
            }
        };

        const interval = setInterval(refreshIfRealtimeUnavailable, 60000);
        document.addEventListener('visibilitychange', refreshIfRealtimeUnavailable);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', refreshIfRealtimeUnavailable);
        };
    }, []); // Empty deps: register once

    // Restore scroll position after data has finished loading and rendering
    useEffect(() => {
        if (!loading && data && !scrollRestoredRef.current) {
            const targetScroll = savedStateRef.current?.scrollTop;
            if (typeof targetScroll === "number" && targetScroll > 0) {
                isRestoringScrollRef.current = true;
                const applyScroll = () => {
                    setScrollTop(targetScroll);
                };

                applyScroll();
                const raf = requestAnimationFrame(applyScroll);
                const t1 = setTimeout(applyScroll, 50);
                const t2 = setTimeout(applyScroll, 150);
                const t3 = setTimeout(applyScroll, 300);
                const tEnd = setTimeout(() => {
                    isRestoringScrollRef.current = false;
                }, 400);

                scrollRestoredRef.current = true;
                return () => {
                    cancelAnimationFrame(raf);
                    clearTimeout(t1);
                    clearTimeout(t2);
                    clearTimeout(t3);
                    clearTimeout(tEnd);
                    isRestoringScrollRef.current = false;
                };
            } else {
                scrollRestoredRef.current = true;
                isRestoringScrollRef.current = false;
            }
        }
    }, [loading, data]);





    const toggleExpand = (orderId) => {
        setExpandedOrders((prev) => ({ ...prev, [orderId]: !prev[orderId] }));
    };

    // A selected store keeps the existing grouped view. "Semua Toko" uses one
    // chronological stream so orders from different stores can be compared directly.
    const ordersByStore = {};
    const visibleOrders = [];
    if (data?.orders) {
        data.orders.forEach((order) => {
            if (searchQuery) {
                const q = searchQuery.toLowerCase();
                const matchOrderId = order.order_sn?.toLowerCase().includes(q);
                const matchItemName =
                    order.products?.some((item) =>
                        item.product_name?.toLowerCase().includes(q),
                    ) ||
                    order.first_product?.product_name
                        ?.toLowerCase()
                        .includes(q);
                if (!matchOrderId && !matchItemName) {
                    return;
                }
            }

            visibleOrders.push(order);
        });
    }

    visibleOrders.sort((a, b) => {
        const timeA = new Date(a.order_time || a.created_at || 0).getTime();
        const timeB = new Date(b.order_time || b.created_at || 0).getTime();
        return timeB - timeA;
    });

    if (selectedStore) {
        ordersByStore[selectedStore] = visibleOrders;
    } else {
        ordersByStore.all = visibleOrders;
    }

    const totalOrdersCount = visibleOrders.length;

    const handleResetFilter = () => {
        setSearchQuery("");
        setSelectedFilterId("semua");
        setSelectedCancelCategory("all");
        setSelectedStore("");
        setSelectedPlatform("all");
        setSelectedShippingProcess("needs_processing");
    };

    const hasActiveFilter =
        Boolean(searchQuery) ||
        (selectedFilterId !== "semua" && selectedFilterId !== "all") ||
        (selectedFilterId === "batal" && selectedCancelCategory !== "all") ||
        Boolean(selectedStore) ||
        selectedPlatform !== "all" ||
        (selectedFilterId === "perlu_dikirim" && selectedShippingProcess !== "needs_processing");

    const selectedStoreOption = data?.stores?.find(
        (store) => String(store.id) === String(selectedStore),
    );
    const selectedStoreLabel = selectedStore === ""
        ? "Semua Toko"
        : selectedStoreOption?.store_name || (data ? "Semua Toko" : "Memuat toko...");
    const displayStores = selectedStore
        ? (data?.stores || []).filter((store) => String(store.id) === String(selectedStore))
        : [{ id: "all", store_name: "Semua Pesanan", platform: null, isCombined: true }];

    return (
        <AppLayout>
            <div className="min-h-screen min-w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 py-2 px-1 sm:px-2">
                <div className="space-y-6 pb-20 animate-fade-in-up">
                    {/* Header */}
                    <div className="contents md:sticky md:top-0 md:z-20 md:flex md:flex-col md:gap-6 md:bg-slate-50 md:pb-2 md:pt-2 dark:md:bg-slate-900">
                        <div id="order-scroll-top-marker" className="flex flex-col gap-6 bg-slate-50 pb-2 pt-2 dark:bg-slate-900 md:contents">
                            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">
                                    Order Management
                                </h1>
                                <p className="text-sm text-gray-500 dark:text-slate-400">
                                    Kelola dan pantau status pesanan dari semua
                                    marketplace.
                                </p>
                            </div>

                            {/* Search and Store Filter */}
                            <div className="flex flex-col md:flex-row items-center gap-3 w-full md:w-auto">
                                <button
                                    onClick={handleSync}
                                    disabled={isSyncBusy}
                                    aria-busy={isSyncBusy}
                                    className="flex w-full md:w-auto items-center justify-center gap-2 px-4 py-2 bg-[#304674] hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-sm font-medium rounded-xl transition-colors disabled:cursor-not-allowed disabled:opacity-70 whitespace-nowrap"
                                >
                                    <span
                                        className={`material-symbols-rounded text-[20px] ${isSyncBusy ? "animate-spin" : ""}`}
                                    >
                                        sync
                                    </span>
                                    {isSyncBusy
                                        ? "Menyelaraskan..."
                                        : "Sinkronisasi Data"}
                                </button>
                                <div className="relative w-full md:w-64">
                                    <span className="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">
                                        search
                                    </span>
                                    <input
                                        type="text"
                                        placeholder="Cari order ID atau produk..."
                                        value={searchQuery}
                                        onChange={(e) =>
                                            setSearchQuery(e.target.value)
                                        }
                                        className="w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-10 pr-4 rounded-xl focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-[#304674] dark:focus:border-blue-500 shadow-sm text-sm transition-all outline-none"
                                    />
                                </div>
                                <div
                                    className="relative w-full md:w-64"
                                    ref={storeDropdownRef}
                                >
                                    <button
                                        onClick={() =>
                                            setIsStoreDropdownOpen(
                                                !isStoreDropdownOpen,
                                            )
                                        }
                                        className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-4 pr-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-sm transition-all hover:border-gray-300 dark:hover:border-slate-600"
                                    >
                                        <span className="truncate pr-2">
                                            {selectedStoreLabel}
                                        </span>
                                        <span
                                            className={`material-symbols-rounded text-gray-400 dark:text-slate-500 transition-transform duration-300 ${isStoreDropdownOpen ? "rotate-180" : ""}`}
                                        >
                                            expand_more
                                        </span>
                                    </button>

                                    <AnimatePresence>
                                        {isStoreDropdownOpen && (
                                            <motion.div
                                                initial={{
                                                    opacity: 0,
                                                    y: 10,
                                                    scale: 0.95,
                                                }}
                                                animate={{
                                                    opacity: 1,
                                                    y: 0,
                                                    scale: 1,
                                                }}
                                                exit={{
                                                    opacity: 0,
                                                    y: 10,
                                                    scale: 0.95,
                                                }}
                                                transition={{
                                                    duration: 0.2,
                                                    ease: "easeOut",
                                                }}
                                                className="absolute right-0 md:left-0 mt-2 w-full min-w-[240px] bg-white dark:bg-slate-900 rounded-2xl shadow-lg border border-slate-100 dark:border-slate-800 overflow-hidden z-50 origin-top"
                                            >
                                                <div className="max-h-64 overflow-y-auto p-2">
                                                    <button
                                                        onClick={() => {
                                                            setSelectedStore(
                                                                "",
                                                            );
                                                            setIsStoreDropdownOpen(
                                                                false,
                                                            );
                                                        }}
                                                        className={`w-full text-left px-3 py-2 text-sm font-medium rounded-xl transition-colors ${selectedStore === ""
                                                            ? "bg-[#304674] text-white dark:bg-blue-600"
                                                            : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                            }`}
                                                    >
                                                        Semua Toko
                                                    </button>
                                                    {data?.stores?.map(
                                                        (store) => (
                                                            <button
                                                                key={store.id}
                                                                onClick={() => {
                                                                    setSelectedStore(
                                                                        String(store.id),
                                                                    );
                                                                    setSelectedPlatform(store.platform);
                                                                    setIsStoreDropdownOpen(
                                                                        false,
                                                                    );
                                                                }}
                                                                className={`w-full flex items-center justify-between text-left px-3 py-2 mt-1 text-sm font-medium rounded-xl transition-colors ${selectedStore ==
                                                                    store.id
                                                                    ? "bg-[#304674] text-white dark:bg-blue-600"
                                                                    : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                                    }`}
                                                            >
                                                                <span className="truncate">
                                                                    {
                                                                        store.store_name
                                                                    }
                                                                </span>
                                                                <span
                                                                    className={`text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full ${selectedStore ==
                                                                        store.id
                                                                        ? "bg-white/20 text-white"
                                                                        : "bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400"
                                                                        }`}
                                                                >
                                                                    {
                                                                        store.platform
                                                                    }
                                                                </span>
                                                            </button>
                                                        ),
                                                    )}
                                                </div>
                                            </motion.div>
                                        )}
                                    </AnimatePresence>
                                </div>
                            </div>
                            </div>

                            <OrderSyncStatus
                                syncs={syncs}
                                recentlyCompleted={recentlyCompleted}
                            />
                        </div>

                        {/* Status Tabs */}
                        <div id="tour-filter-group" className="sticky top-0 z-20 mt-4 border-b border-gray-200 bg-slate-50 pt-4 lg:pt-0 dark:border-slate-700 dark:bg-slate-900 md:static md:z-auto md:mt-0 md:bg-transparent md:pt-0 dark:md:bg-transparent">
                            <div className="flex overflow-x-auto gap-4 pb-2 no-scrollbar">
                                {FILTER_GROUPS.map((group) => {
                                    const isActive = selectedFilterId === group.id;
                                    const count = group.id === "gagal_kirim"
                                        ? (data?.status_counts?.gagal_kirim || 0)
                                        : group.id === "return"
                                            ? (data?.status_counts?.return || 0)
                                            : group.statuses.reduce((sum, statusKey) => sum + (data?.status_counts?.[statusKey] || 0), 0);

                                    return (
                                        <button
                                            key={group.id}
                                            onClick={() => setSelectedFilterId(group.id)}
                                            className={`whitespace-nowrap pb-2 text-sm font-medium border-b-2 transition-colors flex items-center gap-1.5 ${isActive
                                                ? "border-[#304674] dark:border-blue-500 text-[#304674] dark:text-blue-400"
                                                : "border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300"
                                                }`}
                                        >
                                            {group.label}
                                            {count > 0 && (group.id === "perlu_dikirim" || group.id === "dikirim" || group.id === "gagal_kirim") && (
                                                <span
                                                    className={`px-1.5 py-0.5 rounded-md text-[10px] font-bold ${isActive
                                                        ? "bg-[#304674] text-white dark:bg-blue-500"
                                                        : "bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-400"
                                                        }`}
                                                >
                                                    {count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="space-y-2.5">
                            <div className="flex items-center gap-2 overflow-x-auto pt-4 lg:pt-0 pb-0.5 no-scrollbar">
                                <span className="mr-1 shrink-0 text-xs font-semibold text-gray-400 dark:text-slate-500">
                                    Platform:
                                </span>
                                {PLATFORM_FILTERS.map((platform) => {
                                    const isActive = selectedPlatform === platform.id;
                                    const platformTheme = PLATFORM_CONFIG[platform.id];

                                    return (
                                        <button
                                            key={platform.id}
                                            type="button"
                                            aria-label={`Platform: ${platform.label}`}
                                            onClick={() => {
                                                setSelectedPlatform(platform.id);
                                                if (
                                                    platform.id !== "all" &&
                                                    selectedStoreOption?.platform !== platform.id
                                                ) {
                                                    setSelectedStore("");
                                                }
                                            }}
                                            className={`flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors ${isActive
                                                ? "border-[#304674] bg-[#304674] text-white shadow-sm dark:border-blue-600 dark:bg-blue-600"
                                                : "border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                                }`}
                                        >
                                            {platformTheme?.icon && (
                                                <span className="flex h-4 w-4 items-center justify-center overflow-hidden rounded-sm">
                                                    {platformTheme.icon}
                                                </span>
                                            )}
                                            {platform.label}
                                        </button>
                                    );
                                })}
                            </div>

                            <AnimatePresence initial={false}>
                                {selectedFilterId === "perlu_dikirim" && (
                                    <motion.div
                                        initial={{ opacity: 0, height: 0 }}
                                        animate={{ opacity: 1, height: "auto" }}
                                        exit={{ opacity: 0, height: 0 }}
                                        transition={{ duration: 0.15 }}
                                        className="flex items-center gap-2 overflow-x-auto pb-0.5 no-scrollbar"
                                    >
                                        <span className="mr-1 shrink-0 text-xs font-semibold text-gray-400 dark:text-slate-500">
                                            Status Pesanan:
                                        </span>
                                        {SHIPPING_PROCESS_FILTERS.map((filter) => {
                                            const isActive = selectedShippingProcess === filter.id;
                                            const count = data?.shipping_process_counts?.[filter.id] || 0;

                                            return (
                                                <button
                                                    key={filter.id}
                                                    type="button"
                                                    onClick={() => setSelectedShippingProcess(filter.id)}
                                                    className={`flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors ${isActive
                                                        ? "border-[#304674] bg-[#304674] text-white shadow-sm dark:border-blue-600 dark:bg-blue-600"
                                                        : "border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                                                        }`}
                                                >
                                                    {filter.label}
                                                    <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold ${isActive
                                                        ? "bg-white/20 text-white"
                                                        : "bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-300"
                                                        }`}
                                                    >
                                                        {count}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>

                        {/* Sub-Filters for Pembatalan */}
                        <AnimatePresence>
                            {selectedFilterId === "batal" && (
                                <motion.div
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: "auto" }}
                                    exit={{ opacity: 0, height: 0 }}
                                    transition={{ duration: 0.15 }}
                                    className="flex items-center gap-2 pt-3 pb-1 overflow-x-auto no-scrollbar"
                                >
                                    <span className="text-xs font-semibold text-gray-400 dark:text-slate-500 mr-1 flex items-center gap-1">
                                        <span className="material-symbols-rounded text-[14px]">filter_list</span> Kategori:
                                    </span>

                                    <button
                                        onClick={() => setSelectedCancelCategory("all")}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5 ${selectedCancelCategory === "all"
                                                ? "bg-[#304674] text-white dark:bg-blue-600 shadow-sm"
                                                : "bg-gray-100 dark:bg-slate-700/60 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700"
                                            }`}
                                    >
                                        Semua
                                        <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-bold ${selectedCancelCategory === "all"
                                                ? "bg-white/20 text-white"
                                                : "bg-gray-200 dark:bg-slate-600 text-gray-600 dark:text-slate-300"
                                            }`}>
                                            {data?.cancel_sub_counts?.all || 0}
                                        </span>
                                    </button>

                                    <button
                                        onClick={() => setSelectedCancelCategory("SELLER_LATE_SHIPMENT")}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5 ${selectedCancelCategory === "SELLER_LATE_SHIPMENT"
                                                ? "bg-[#304674] text-white dark:bg-blue-600 shadow-sm"
                                                : "bg-gray-100 dark:bg-slate-700/60 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700"
                                            }`}
                                    >
                                        Terlambat Dikirim Penjual
                                        <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-bold ${selectedCancelCategory === "SELLER_LATE_SHIPMENT"
                                                ? "bg-white/20 text-white"
                                                : "bg-gray-200 dark:bg-slate-600 text-gray-600 dark:text-slate-300"
                                            }`}>
                                            {data?.cancel_sub_counts?.SELLER_LATE_SHIPMENT || 0}
                                        </span>
                                    </button>

                                    <button
                                        onClick={() => setSelectedCancelCategory("BUYER_SIDE")}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5 ${selectedCancelCategory === "BUYER_SIDE"
                                                ? "bg-[#304674] text-white dark:bg-blue-600 shadow-sm"
                                                : "bg-gray-100 dark:bg-slate-700/60 text-gray-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-700"
                                            }`}
                                    >
                                        Dari Sisi Buyer / Pembayaran
                                        <span className={`px-1.5 py-0.5 rounded-full text-[10px] font-bold ${selectedCancelCategory === "BUYER_SIDE"
                                                ? "bg-white/20 text-white"
                                                : "bg-gray-200 dark:bg-slate-600 text-gray-600 dark:text-slate-300"
                                            }`}>
                                            {data?.cancel_sub_counts?.BUYER_SIDE || 0}
                                        </span>
                                    </button>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>

                    {/* Order Content */}
                    {loading ? (
                        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden">
                            <SkeletonRow />
                            <SkeletonRow />
                            <SkeletonRow />
                        </div>
                    ) : !data?.stores?.length ? (
                        <motion.div
                            initial={{ opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            className="bg-white dark:bg-slate-800 rounded-3xl p-10 sm:p-14 text-center border border-gray-100 dark:border-slate-700/80 shadow-sm max-w-lg mx-auto my-8"
                        >
                            <div className="relative mx-auto w-40 h-40 sm:w-48 sm:h-48 mb-6 flex items-center justify-center">
                                <div className="absolute inset-0 bg-blue-500/10 dark:bg-blue-400/10 rounded-full blur-2xl transform scale-90" />
                                <img
                                    src="/images/empty-orders.jpg"
                                    alt="Belum ada toko terhubung"
                                    className="relative w-full h-full object-contain rounded-2xl shadow-sm border border-gray-100/60 dark:border-slate-700/60"
                                />
                            </div>
                            <h3 className="text-xl sm:text-2xl font-bold text-gray-800 dark:text-white tracking-tight mb-2">
                                Belum Ada Toko Terhubung
                            </h3>
                            <p className="text-sm text-gray-500 dark:text-slate-400 mb-6 max-w-sm mx-auto leading-relaxed">
                                Hubungkan toko marketplace Anda terlebih dahulu di menu Integrasi Toko untuk mulai mengelola dan memantau pesanan.
                            </p>
                            <a
                                href="#/stores"
                                className="inline-flex items-center gap-2 px-5 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-xs font-semibold rounded-xl transition-all shadow-sm"
                            >
                                <span className="material-symbols-rounded text-sm">storefront</span>
                                Hubungkan Toko
                            </a>
                        </motion.div>
                    ) : totalOrdersCount === 0 ? (
                        <motion.div
                            initial={{ opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.3 }}
                            className="bg-white dark:bg-slate-800 rounded-3xl p-8 sm:p-12 text-center border border-gray-100 dark:border-slate-700/80 shadow-sm max-w-lg mx-auto my-8"
                        >
                            <div className="relative mx-auto w-44 h-44 sm:w-52 sm:h-52 mb-6 flex items-center justify-center">
                                <div className="absolute inset-0 bg-blue-500/10 dark:bg-blue-400/10 rounded-full blur-2xl transform scale-90" />
                                <img
                                    src="/images/empty-orders.jpg"
                                    alt="Oops, pesanan tidak ditemukan"
                                    className="relative w-full h-full object-contain rounded-2xl shadow-md border border-gray-100/60 dark:border-slate-700/60 transition-transform duration-300 hover:scale-105"
                                />
                            </div>

                            <h3 className="text-xl sm:text-2xl font-bold text-gray-800 dark:text-white tracking-tight mb-2">
                                Oops, Pesanan Tidak Ditemukan!
                            </h3>

                            <p className="text-sm text-gray-500 dark:text-slate-400 max-w-md mx-auto mb-6 leading-relaxed">
                                {searchQuery ? (
                                    <>
                                        Tidak ada pesanan yang cocok dengan kata kunci{" "}
                                        <span className="font-semibold text-gray-700 dark:text-slate-200">
                                            "{searchQuery}"
                                        </span>
                                        .
                                    </>
                                ) : selectedFilterId === "batal" && selectedCancelCategory === "SELLER_LATE_SHIPMENT" ? (
                                    "Tidak ada pesanan pembatalan karena terlambat dikirim penjual."
                                ) : selectedFilterId === "batal" && selectedCancelCategory === "BUYER_SIDE" ? (
                                    "Tidak ada pesanan pembatalan dari sisi buyer / pembayaran."
                                ) : selectedFilterId !== "semua" && selectedFilterId !== "all" ? (
                                    `Tidak ada pesanan dalam status ${FILTER_GROUPS.find(g => g.id === selectedFilterId)?.label || "ini"}.`
                                ) : selectedStore ? (
                                    "Toko yang Anda pilih saat ini belum memiliki pesanan."
                                ) : (
                                    "Saat ini belum ada pesanan yang masuk atau data pesanan belum disinkronisasi."
                                )}
                            </p>

                            {!searchQuery && (
                                <div className="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-left dark:border-amber-900/60 dark:bg-amber-900/20">
                                    <div className="flex items-start gap-3">
                                        <span className="material-symbols-rounded mt-0.5 text-[18px] text-amber-600 dark:text-amber-300">
                                            info
                                        </span>
                                        <div>
                                            <p className="text-xs font-bold text-amber-900 dark:text-amber-100">
                                                Tidak menemukan pesanan?
                                            </p>
                                            <p className="mt-1 text-xs leading-relaxed text-amber-800 dark:text-amber-200">
                                                Sistem sedang mencoba menarik data dari toko kamu. Sync awal bisa membutuhkan beberapa menit. Jika data belum masuk, klik Sinkronisasi Data.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-wrap items-center justify-center gap-3">
                                {hasActiveFilter && (
                                    <button
                                        onClick={handleResetFilter}
                                        className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-200 transition-colors"
                                    >
                                        <span className="material-symbols-rounded text-sm">filter_alt_off</span>
                                        Reset Filter & Pencarian
                                    </button>
                                )}

                                <button
                                    onClick={handleSync}
                                    disabled={isSyncBusy}
                                    aria-busy={isSyncBusy}
                                    className="inline-flex items-center gap-2 px-5 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white text-xs font-semibold rounded-xl transition-all shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <span className={`material-symbols-rounded text-sm ${isSyncBusy ? "animate-spin" : ""}`}>
                                        sync
                                    </span>
                                    {isSyncBusy ? "Menyelaraskan..." : "Sinkronisasi Data"}
                                </button>
                            </div>
                        </motion.div>
                    ) : (
                        displayStores.map((store) => {
                            const storeOrders = ordersByStore[store.id] || [];
                            if (storeOrders.length === 0) return null;

                            const totalOrders = storeOrders.length;
                            const hasClientOnlyEscrowFilter = Boolean(searchQuery)
                                || (selectedFilterId === 'dikirim' && excludeReturns);
                            const totalSellingPrice = searchQuery
                                ? storeOrders.reduce(
                                    (sum, order) => sum + Number(order.order_selling_price || 0),
                                    0,
                                )
                                : Number(data?.totals?.order_selling_price || 0);
                            const totalEscrow = hasClientOnlyEscrowFilter
                                ? storeOrders.reduce((sum, order) => {
                                    if (selectedFilterId === 'dikirim' && excludeReturns && isReturnOrCancel(order)) {
                                        return sum;
                                    }

                                    return sum + Number(order.escrow_amount || 0);
                                }, 0)
                                : Number(data?.totals?.escrow_amount || 0);
                            const itemsPerPage = limitByStore[store.id] || 30;
                            const totalPages = Math.ceil(
                                totalOrders / itemsPerPage,
                            );
                            const currentPage = pageByStore[store.id] || 1;
                            const validPage = Math.min(
                                currentPage,
                                totalPages > 0 ? totalPages : 1,
                            );
                            const paginatedOrders = storeOrders.slice(
                                (validPage - 1) * itemsPerPage,
                                validPage * itemsPerPage,
                            );

                            const theme = store.isCombined ? {
                                bg: "bg-slate-100 dark:bg-slate-700",
                                text: "text-[#304674] dark:text-blue-400",
                                icon: <span className="material-symbols-rounded text-[18px]">receipt_long</span>,
                            } : PLATFORM_CONFIG[store.platform] || {
                                bg: "bg-blue-50",
                                text: "text-blue-600",
                                icon: "?",
                            };

                            return (
                                <div
                                    key={store.id}
                                    id={store.isCombined || store.id === displayStores[0]?.id ? "tour-order-table" : undefined}
                                    className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700 overflow-hidden"
                                >
                                    {/* Store Header */}
                                    <div className="px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/30 flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`w-8 h-8 rounded-lg ${theme.bg} ${theme.text} flex items-center justify-center font-bold text-lg border border-white dark:border-slate-600 shadow-sm`}
                                            >
                                                {theme.icon}
                                            </div>
                                            <h2 className="font-bold text-gray-800 dark:text-white">
                                                {store.store_name}
                                            </h2>
                                            {/* <span className="text-xs text-gray-400 dark:text-slate-500 font-mono hidden sm:inline">
                                                ID: {store.id}
                                            </span> */}
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-xs font-medium bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-1 rounded-md hidden sm:inline-block">
                                                {storeOrders.length} Orders
                                            </span>
                                            {!store.isCombined && (
                                                <button
                                                    onClick={() => handleSyncStore(store.id)}
                                                    disabled={isSyncBusy}
                                                    aria-busy={isSyncBusy}
                                                    className="flex items-center justify-center gap-1.5 px-3 py-1.5 bg-[#304674]/10 hover:bg-[#304674]/20 dark:bg-blue-600/20 dark:hover:bg-blue-600/30 text-[#304674] dark:text-blue-400 text-xs font-medium rounded-lg transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <span className={`material-symbols-rounded text-[16px] ${isSyncBusy ? 'animate-spin' : ''}`}>sync</span>
                                                    {isSyncBusy ? 'Menyelaraskan...' : 'Sinkronkan Toko'}
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Desktop Table */}
                                    <div className="hidden md:block overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-white dark:bg-slate-800 text-gray-400 dark:text-slate-500 font-medium text-xs uppercase border-b border-gray-100 dark:border-slate-700">
                                                <tr>
                                                    <th className="px-6 py-4 w-8"></th>
                                                    <th className="px-6 py-4">
                                                        Order Details
                                                    </th>
                                                    <th className="px-6 py-4">
                                                        Items Summary
                                                    </th>
                                                    <th className="px-6 py-4">
                                                        Status
                                                    </th>
                                                    <th className="px-6 py-4">
                                                        Informasi Logistik
                                                    </th>
                                                    <th className="px-6 py-4 text-right">
                                                        Total Price
                                                    </th>
                                                    <th className="px-6 py-4 text-right">
                                                        Escrow (Cuan)
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-50 dark:divide-slate-700/50">
                                                {paginatedOrders.map(
                                                    (order) => (
                                                        <React.Fragment
                                                            key={order.id}
                                                        >
                                                            <tr
                                                                key={order.id}
                                                                onClick={() => handleNavigateToDetail(order.id)}
                                                                className="hover:bg-gray-50/50 dark:hover:bg-slate-700/30 transition-colors group cursor-pointer"
                                                            >
                                                                <td className="px-6 py-4 align-top">
                                                                    <button
                                                                        onClick={(e) => {
                                                                            e.stopPropagation();
                                                                            toggleExpand(
                                                                                order.id,
                                                                            );
                                                                        }}
                                                                        className={`text-gray-400 dark:text-slate-500 hover:text-[#304674] dark:hover:text-blue-400 transition-transform duration-200 ${expandedOrders[order.id] ? "rotate-180" : ""}`}
                                                                    >
                                                                        <span className="material-symbols-rounded">
                                                                            expand_more
                                                                        </span>
                                                                    </button>
                                                                </td>
                                                                <td className="px-6 py-4 align-top">
                                                                    <button onClick={(e) => handleCopySn(e, order.order_sn)} className="font-bold text-[#304674] dark:text-blue-400 font-mono hover:text-[#233355] dark:hover:text-blue-300 transition-colors inline-flex items-center gap-1">
                                                                        {order.order_sn}
                                                                        <span className="material-symbols-rounded text-[14px]">{copiedSn === order.order_sn ? 'check' : 'content_copy'}</span>
                                                                    </button>
                                                                    <div className="text-xs text-gray-400 dark:text-slate-500 mt-1" title="Waktu Order">
                                                                        {formatDate(order.order_time)}
                                                                    </div>
                                                                    {store.isCombined && (
                                                                        <div className="mt-1.5 flex max-w-48 items-center gap-1.5 text-[10px] font-medium text-gray-500 dark:text-slate-400">
                                                                            <span className="truncate">{order.store_name || "Toko"}</span>
                                                                            <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 dark:bg-slate-700">
                                                                                {order.platform === "Tiktokshop" ? "TikTok Shop" : order.platform}
                                                                            </span>
                                                                        </div>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 align-top">
                                                                    <div className="flex items-center gap-3">
                                                                        {order.first_product?.variant_image || order.first_product?.image ? (
                                                                            <img
                                                                                src={
                                                                                    order.first_product?.variant_image || order.first_product?.image
                                                                                }
                                                                                className="w-10 h-10 rounded-md border border-gray-200 dark:border-slate-600 object-cover"
                                                                                alt=""
                                                                            />
                                                                        ) : (
                                                                            <div className="w-10 h-10 rounded-md bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                                <span className="material-symbols-rounded text-sm text-gray-300 dark:text-slate-500">
                                                                                    image
                                                                                </span>
                                                                            </div>
                                                                        )}
                                                                        <div className="min-w-0">
                                                                            <p className="font-medium text-gray-700 dark:text-slate-300 truncate w-48">
                                                                                {
                                                                                    order
                                                                                        .first_product
                                                                                        ?.product_name
                                                                                }
                                                                            </p>
                                                                            <p className="w-48 truncate text-xs font-semibold text-gray-500 dark:text-slate-400">
                                                                                Varian: {order.first_product?.model_name || "Tanpa varian"}
                                                                            </p>
                                                                            {order.product_count > 1 && (
                                                                                <button
                                                                                    onClick={(event) => {
                                                                                        event.stopPropagation();
                                                                                        toggleExpand(
                                                                                            order.id,
                                                                                        );
                                                                                    }}
                                                                                >
                                                                                    <span className="text-xs text-blue-600 dark:text-blue-400 font-medium">
                                                                                        +
                                                                                        {order.product_count -
                                                                                            1}{" "}
                                                                                        produk
                                                                                        lainnya
                                                                                    </span>
                                                                                </button>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                                <td className="px-6 py-4 align-top">
                                                                    <div className="flex flex-col items-start gap-1">
                                                                        <StatusBadge
                                                                            status={
                                                                                order.order_status
                                                                            }
                                                                            platform={
                                                                                order.platform
                                                                            }
                                                                        />
                                                                        {order.returns && order.returns.length > 0 && (
                                                                            <ReturnStatusBadge
                                                                                status={order.returns[0].normalized_status}
                                                                                platformStatus={order.returns[0].platform_status}
                                                                            />
                                                                        )}
                                                                        {order.normalized_cancel_category && ['CANCEL', 'CANCELLED', 'IN_CANCEL'].includes(order.order_status) && !order.packages?.some(p => p.normalized_logistics_status === 'DELIVERY_FAILED') && (
                                                                            <span
                                                                                className="inline-flex items-center text-[10px] font-medium px-1.5 py-0.5 rounded bg-red-100 dark:bg-slate-700/80 text-red-800 dark:text-red-200"
                                                                                title={order.cancel_reason ? `Alasan: ${order.cancel_reason}` : ''}
                                                                            >
                                                                                {order.normalized_cancel_category === 'SELLER_LATE_SHIPMENT' && 'Terlambat Dikirim Penjual'}
                                                                                {order.normalized_cancel_category === 'BUYER_SIDE' && 'Sisi Buyer / Pembayaran'}
                                                                                {order.normalized_cancel_category === 'UNKNOWN' && (order.cancel_reason || 'Alasan Lain')}
                                                                            </span>
                                                                        )}
                                                                        {order.packages?.some(p => p.normalized_logistics_status === 'DELIVERY_FAILED') && (
                                                                            <span className="inline-flex items-center text-[10px] font-medium px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200">
                                                                                Pengantaran Gagal
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </td>
                                                                <td className="px-6 py-4 align-top">
                                                                    {order.packages && order.packages.length > 0 ? (
                                                                        <div className="flex flex-col gap-3">
                                                                            {order.packages.map((pkg, pIdx) => {
                                                                                const isFailed = pkg.normalized_logistics_status === 'DELIVERY_FAILED';
                                                                                const isDelivered = pkg.normalized_logistics_status === 'DELIVERED';
                                                                                const trackingNo = pkg.tracking_number || order.tracking_number || '-';

                                                                                return (
                                                                                    <div key={pIdx} className="text-xs">
                                                                                        <p className="font-bold text-gray-800 dark:text-slate-200">{order.shipping_provider || 'Kurir'}</p>
                                                                                        <p className="font-mono text-gray-500 dark:text-slate-400 mb-1">{trackingNo}</p>
                                                                                        {isFailed ? (
                                                                                            <div className="mt-1">
                                                                                                <p className="font-medium flex items-start gap-1 text-amber-700 dark:text-amber-300">
                                                                                                    <span>🔴</span> <span>Pengantaran Gagal</span>
                                                                                                </p>
                                                                                                {pkg.logistics_status && (
                                                                                                    <p className="text-red-500 dark:text-red-400 mt-0.5 break-words">
                                                                                                        Alasan: {pkg.logistics_status}
                                                                                                    </p>
                                                                                                )}
                                                                                            </div>
                                                                                        ) : isDelivered ? (
                                                                                            <p className="font-medium flex items-start gap-1 text-emerald-600 dark:text-emerald-400 mt-1">
                                                                                                <span>🟢</span> <span>Terkirim</span>
                                                                                            </p>
                                                                                        ) : null}
                                                                                    </div>
                                                                                );
                                                                            })}
                                                                        </div>
                                                                    ) : (order.shipping_provider || order.tracking_number) ? (
                                                                        <div className="text-xs">
                                                                            <p className="font-bold text-gray-800 dark:text-slate-200">{order.shipping_provider || 'Kurir'}</p>
                                                                            <p className="font-mono text-gray-500 dark:text-slate-400 mb-1">{order.tracking_number || '-'}</p>
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-xs text-gray-400 italic">Informasi logistik belum tersedia</span>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 align-top text-right font-medium text-gray-600 dark:text-slate-300">
                                                                    {formatRp(
                                                                        order.order_selling_price,
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 align-top text-right font-bold text-[#304674] dark:text-blue-400">
                                                                    {formatRp(
                                                                        order.escrow_amount,
                                                                    )}
                                                                </td>
                                                            </tr>
                                                            {/* Expanded Detail */}
                                                            {expandedOrders[
                                                                order.id
                                                            ] && (
                                                                    <tr
                                                                        key={`${order.id}-detail`}
                                                                        className="bg-gray-50/50 dark:bg-slate-900/30"
                                                                    >
                                                                        <td
                                                                            colSpan={
                                                                                7
                                                                            }
                                                                            className="px-6 py-4"
                                                                        >
                                                                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm">
                                                                                <h4 className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-3">
                                                                                    Detail
                                                                                    Pesanan
                                                                                </h4>
                                                                                <div className="space-y-3">
                                                                                    {order.products?.map(
                                                                                        (
                                                                                            product,
                                                                                            idx,
                                                                                        ) => (
                                                                                            <div
                                                                                                key={
                                                                                                    idx
                                                                                                }
                                                                                                className="flex items-start justify-between"
                                                                                            >
                                                                                                <div className="flex items-start gap-3">
                                                                                                    {product.variant_image || product.image ? (
                                                                                                        <img
                                                                                                            src={
                                                                                                                product.variant_image || product.image
                                                                                                            }
                                                                                                            className="w-12 h-12 rounded-lg border border-gray-100 dark:border-slate-600 object-cover"
                                                                                                            alt=""
                                                                                                        />
                                                                                                    ) : (
                                                                                                        <div className="w-12 h-12 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                                                            <span className="material-symbols-rounded text-sm text-gray-300 dark:text-slate-500">
                                                                                                                image
                                                                                                            </span>
                                                                                                        </div>
                                                                                                    )}
                                                                                                    <div className="min-w-0 max-w-xs">
                                                                                                        <p className="truncate text-sm font-semibold text-gray-700 dark:text-slate-200" title={product.product_name}>
                                                                                                            {
                                                                                                                product.product_name
                                                                                                            }
                                                                                                        </p>
                                                                                                        <p className="truncate text-xs font-semibold text-gray-500 dark:text-slate-400" title={product.model_name || "Tanpa varian"}>
                                                                                                            Varian:{" "}
                                                                                                            {
                                                                                                                product.model_name
                                                                                                            }{" "}
                                                                                                            •
                                                                                                            Qty:{" "}
                                                                                                            {
                                                                                                                product.quantity_purchased
                                                                                                            }
                                                                                                        </p>
                                                                                                    </div>
                                                                                                </div>
                                                                                            </div>
                                                                                        ),
                                                                                    )}
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                )}
                                                        </React.Fragment>
                                                    ),
                                                )}
                                            </tbody>
                                            <tfoot className="bg-gray-50 dark:bg-slate-900/30 border-t border-gray-100 dark:border-slate-700">
                                                <tr>
                                                    <td
                                                        colSpan={5}
                                                        className="px-6 py-3 font-bold text-gray-500 dark:text-slate-400 text-right"
                                                    >
                                                        Total:
                                                    </td>
                                                    <td className="px-6 py-3 font-bold text-gray-800 dark:text-white text-right">
                                                        {formatRp(totalSellingPrice)}
                                                    </td>
                                                    <td className="px-6 py-3 font-bold text-green-600 dark:text-green-400 text-right">
                                                        {formatRp(totalEscrow)}
                                                    </td>
                                                </tr>
                                                {selectedFilterId === 'dikirim' && (
                                                    <tr>
                                                        <td colSpan={7} className="px-6 py-3 text-right">
                                                            <label className="inline-flex items-center cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    className="form-checkbox h-4 w-4 text-[#304674] dark:text-blue-500 rounded border-gray-300 dark:border-slate-600 focus:ring-[#304674] dark:focus:ring-blue-500 bg-white dark:bg-slate-800 transition duration-150 ease-in-out cursor-pointer"
                                                                    checked={excludeReturns}
                                                                    onChange={(e) => setExcludeReturns(e.target.checked)}
                                                                />
                                                                <span className="ml-2 text-xs text-gray-500 dark:text-slate-400 font-medium">
                                                                    Kecualikan Pengembalian / Batal
                                                                </span>
                                                            </label>
                                                        </td>
                                                    </tr>
                                                )}
                                            </tfoot>
                                        </table>
                                    </div>

                                    {/* Mobile Cards */}
                                    <div className="md:hidden p-4 space-y-4 bg-gray-50/50 dark:bg-slate-900/30">
                                        {paginatedOrders.map((order) => {
                                            return (
                                                <div
                                                    key={order.id}
                                                    onClick={() => handleNavigateToDetail(order.id)}
                                                    className="tour-order-mobile-card bg-white dark:bg-slate-800 rounded-xl p-4 shadow-sm border border-gray-100 dark:border-slate-700 relative cursor-pointer"
                                                >
                                                    <div className="flex justify-between items-start mb-3 gap-2">
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex flex-wrap items-start gap-2 mb-1">
                                                                <button onClick={(e) => handleCopySn(e, order.order_sn)} className="font-mono text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 inline-flex items-center gap-0.5 min-w-0 truncate">
                                                                    <span className="truncate">#{order.order_sn}</span>
                                                                    <span className="material-symbols-rounded text-[12px] shrink-0">{copiedSn === order.order_sn ? 'check' : 'content_copy'}</span>
                                                                </button>
                                                                <div className="flex flex-col items-start gap-1">
                                                                    <StatusBadge
                                                                        status={
                                                                            order.order_status
                                                                        }
                                                                        platform={
                                                                            order.platform
                                                                        }
                                                                    />
                                                                    {order.returns && order.returns.length > 0 && (
                                                                        <ReturnStatusBadge
                                                                            status={order.returns[0].normalized_status}
                                                                            platformStatus={order.returns[0].platform_status}
                                                                        />
                                                                    )}
                                                                    {order.normalized_cancel_category && ['CANCEL', 'CANCELLED', 'IN_CANCEL'].includes(order.order_status) && !order.packages?.some(p => p.normalized_logistics_status === 'DELIVERY_FAILED') && (
                                                                        <span
                                                                            className="inline-flex items-center text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-700/80 text-gray-600 dark:text-slate-300"
                                                                            title={order.cancel_reason ? `Alasan: ${order.cancel_reason}` : ''}
                                                                        >
                                                                            {order.normalized_cancel_category === 'SELLER_LATE_SHIPMENT' && 'Terlambat Dikirim Penjual'}
                                                                            {order.normalized_cancel_category === 'BUYER_SIDE' && 'Sisi Buyer / Pembayaran'}
                                                                            {order.normalized_cancel_category === 'UNKNOWN' && (order.cancel_reason || 'Alasan Lain')}
                                                                        </span>
                                                                    )}
                                                                    {order.packages?.some(p => p.normalized_logistics_status === 'DELIVERY_FAILED') && (
                                                                        <span className="inline-flex items-center text-[10px] font-medium px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200">
                                                                            Pengantaran Gagal
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {order.packages && order.packages.length > 0 ? (
                                                                    <div className="mt-2 space-y-2">
                                                                        {order.packages.map((pkg, pIdx) => {
                                                                            const isFailed = pkg.normalized_logistics_status === 'DELIVERY_FAILED';
                                                                            const isDelivered = pkg.normalized_logistics_status === 'DELIVERED';
                                                                            const trackingNo = pkg.tracking_number || order.tracking_number || '-';

                                                                            return (
                                                                                <div key={pIdx} className="text-[11px] p-2 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-100 dark:border-slate-700">
                                                                                    <div className="font-bold text-gray-700 dark:text-slate-300">{order.shipping_provider || 'Kurir'}</div>
                                                                                    <div className="font-mono text-gray-500 dark:text-slate-500">{trackingNo}</div>
                                                                                    {isFailed ? (
                                                                                        <div className="mt-1">
                                                                                            <div className="font-medium text-amber-700 dark:text-amber-300 flex items-start gap-1">
                                                                                                <span>🔴</span> <span>Pengantaran Gagal</span>
                                                                                            </div>
                                                                                            {pkg.logistics_status && (
                                                                                                <div className="text-red-500 mt-0.5 break-words">Alasan: {pkg.logistics_status}</div>
                                                                                            )}
                                                                                        </div>
                                                                                    ) : isDelivered ? (
                                                                                        <div className="font-medium mt-1 text-emerald-600 dark:text-emerald-400 flex items-start gap-1">
                                                                                            <span>🟢</span> <span>Terkirim</span>
                                                                                        </div>
                                                                                    ) : null}
                                                                                </div>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                ) : (order.shipping_provider || order.tracking_number) ? (
                                                                    <div className="mt-2 text-[11px] p-2 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-100 dark:border-slate-700">
                                                                        <div className="font-bold text-gray-700 dark:text-slate-300">{order.shipping_provider || 'Kurir'}</div>
                                                                        <div className="font-mono text-gray-500 dark:text-slate-500">{order.tracking_number || '-'}</div>
                                                                    </div>
                                                                ) : (
                                                                    <div className="mt-2 text-[11px] text-gray-400 italic">Informasi logistik belum tersedia</div>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-gray-400 dark:text-slate-500 mt-2">
                                                                {formatDate(order.order_time)}
                                                            </p>
                                                            {store.isCombined && (
                                                                <div className="mt-1 flex min-w-0 items-center gap-1.5 text-[10px] font-medium text-gray-500 dark:text-slate-400">
                                                                    <span className="truncate">{order.store_name || "Toko"}</span>
                                                                    <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 dark:bg-slate-700">
                                                                        {order.platform === "Tiktokshop" ? "TikTok Shop" : order.platform}
                                                                    </span>
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="text-xs text-gray-400 dark:text-slate-500">
                                                                Escrow
                                                            </p>
                                                            <p className="text-sm font-bold text-green-600 dark:text-green-400">
                                                                {formatRp(
                                                                    order.escrow_amount,
                                                                )}
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div
                                                        className="flex items-center gap-3 bg-gray-50 dark:bg-slate-900/50 p-2 rounded-lg cursor-pointer"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            toggleExpand(order.id);
                                                        }}
                                                    >
                                                        {order.first_product?.variant_image || order.first_product?.image ? (
                                                            <img
                                                                src={
                                                                    order.first_product?.variant_image || order.first_product?.image
                                                                }
                                                                className="w-10 h-10 rounded border border-gray-200 dark:border-slate-600"
                                                                alt=""
                                                            />
                                                        ) : (
                                                            <div className="w-10 h-10 rounded bg-gray-100 dark:bg-slate-700 flex items-center justify-center">
                                                                <span className="material-symbols-rounded text-sm text-gray-300">
                                                                    image
                                                                </span>
                                                            </div>
                                                        )}
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium text-gray-700 dark:text-slate-300 truncate">
                                                                {
                                                                    order
                                                                        .first_product
                                                                        ?.product_name
                                                                }
                                                            </p>
                                                            <p className="truncate text-xs font-semibold text-gray-500 dark:text-slate-400">
                                                                Varian: {order.first_product?.model_name || "Tanpa varian"}
                                                            </p>
                                                            {order.product_count > 1 && (
                                                                <p className="text-xs text-blue-600 dark:text-blue-400">
                                                                    +
                                                                    {order.product_count -
                                                                        1}{" "}
                                                                    produk
                                                                    lainnya
                                                                </p>
                                                            )}
                                                        </div>
                                                        <span
                                                            className={`material-symbols-rounded text-gray-400 dark:text-slate-500 transition-transform ${expandedOrders[order.id] ? "rotate-180" : ""}`}
                                                        >
                                                            expand_more
                                                        </span>
                                                    </div>

                                                    {expandedOrders[
                                                        order.id
                                                    ] && (
                                                            <div className="mt-3 space-y-3 border-t border-gray-100 dark:border-slate-700 pt-3">
                                                                {order.products?.map(
                                                                    (product, idx) => (
                                                                        <div
                                                                            key={
                                                                                idx
                                                                            }
                                                                            className="flex min-w-0 items-start justify-between gap-3 text-xs"
                                                                        >
                                                                            <div className="min-w-0 flex-1">
                                                                                <p className="truncate font-medium text-gray-700 dark:text-slate-200" title={product.product_name}>
                                                                                    {product.product_name}
                                                                                </p>
                                                                                <p className="mt-0.5 truncate font-semibold text-gray-500 dark:text-slate-400" title={product.model_name || "Tanpa varian"}>
                                                                                    Varian: {product.model_name || "Tanpa varian"}
                                                                                </p>
                                                                            </div>
                                                                            <span className="shrink-0 font-semibold text-gray-500 dark:text-slate-400">
                                                                                x{product.quantity_purchased}
                                                                            </span>
                                                                        </div>
                                                                    ),
                                                                )}
                                                                <div className="border-t border-dashed border-gray-200 dark:border-slate-700 pt-2 flex justify-between items-center">
                                                                    <span className="text-xs font-bold text-gray-600 dark:text-slate-300">
                                                                        Total
                                                                        Penjualan
                                                                    </span>
                                                                    <span className="text-sm font-bold text-gray-800 dark:text-white">
                                                                        {formatRp(
                                                                            order.order_selling_price,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        )}
                                                </div>
                                            );
                                        })}
                                    </div>

                                    {/* Pagination Controls */}
                                    <div className="px-6 py-4 border-t border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                                        <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                                            <div className="flex items-center gap-1">
                                                <span className="text-sm text-gray-500 dark:text-slate-400">
                                                    Tampilkan maksimal:
                                                </span>
                                                <LimitDropdown
                                                    value={itemsPerPage}
                                                    onChange={(val) => {
                                                        setLimitByStore(
                                                            (prev) => ({
                                                                ...prev,
                                                                [store.id]: val,
                                                            }),
                                                        );
                                                        setPageByStore(
                                                            (prev) => ({
                                                                ...prev,
                                                                [store.id]: 1,
                                                            }),
                                                        );
                                                    }}
                                                />
                                            </div>
                                            <span className="text-sm text-gray-500 dark:text-slate-400">
                                                Menampilkan{" "}
                                                {(validPage - 1) *
                                                    itemsPerPage +
                                                    1}{" "}
                                                -{" "}
                                                {Math.min(
                                                    validPage * itemsPerPage,
                                                    totalOrders,
                                                )}{" "}
                                                dari {totalOrders} pesanan
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() =>
                                                    setPageByStore((p) => ({
                                                        ...p,
                                                        [store.id]:
                                                            validPage - 1,
                                                    }))
                                                }
                                                disabled={validPage === 1}
                                                className="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                                            >
                                                <span className="material-symbols-rounded text-xl">
                                                    chevron_left
                                                </span>
                                            </button>
                                            <span className="text-sm font-medium text-gray-700 dark:text-slate-300 min-w-[60px] text-center">
                                                {validPage} / {totalPages}
                                            </span>
                                            <button
                                                onClick={() =>
                                                    setPageByStore((p) => ({
                                                        ...p,
                                                        [store.id]:
                                                            validPage + 1,
                                                    }))
                                                }
                                                disabled={
                                                    validPage === totalPages
                                                }
                                                className="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500 disabled:opacity-30 disabled:hover:bg-transparent transition-colors"
                                            >
                                                <span className="material-symbols-rounded text-xl">
                                                    chevron_right
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            </div>

            <style>{`
                .no-scrollbar::-webkit-scrollbar { display: none; }
                .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up { animation: fadeInUp 0.4s ease-out forwards; }
            `}</style>
            <OnboardingTour
                steps={ORDER_TOUR_STEPS}
                isOpen={tour.isOpen}
                currentStep={tour.currentStep}
                onNext={tour.next}
                onPrev={tour.prev}
                onSkip={tour.skip}
                onFinish={tour.finish}
                onStart={tour.start}
            />
            <ScrollToTopButton markerId="order-scroll-top-marker" />
        </AppLayout>
    );
}
