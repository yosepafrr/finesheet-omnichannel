import React, { useState, useEffect, useRef, useCallback } from 'react';
import AppLayout from "../../views/components/layouts/AppLayout";
import axios from "axios";
import { toast } from "react-hot-toast";
import * as XLSX from 'xlsx';
import html2canvas from 'html2canvas';
import { jsPDF } from 'jspdf';
import {
    Wallet,
    TrendingUp,
    TrendingDown,
    CreditCard,
    CheckCircle2,
    Plus,
    Trash2,
    RotateCcw,
    Search,
    X,
    ChevronDown,
    Download,
    FileText,
    Image,
    FileSpreadsheet,
    Copy,
    Check,
    ExternalLink,
    Settings,
    ArrowLeftRight,
    AlertTriangle,
    Building2,
    Truck,
    Layers,
    Filter
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import SupplierOnboardingModal from "../../views/components/react/SupplierOnboardingModal";
import ManageSuppliersModal from "../../views/components/react/ManageSuppliersModal";
import OnboardingTour from "@/components/OnboardingTour";
import { useOnboarding } from "@/hooks/useOnboarding";

const PAYABLE_TOUR_STEPS = [
    {
        selector: "#tour-supplier-actions",
        title: "Pengaturan Supplier",
        description: "Pilih supplier atau atur alokasi produk ke masing-masing supplier dari menu ini.",
        position: "bottom",
    },
    {
        selector: "#tour-supplier-summary",
        title: "Ringkasan Tagihan",
        description: "Lihat total tagihan berjalan, produk terjual, dan catat nominal pembayaran untuk supplier yang dipilih.",
        position: "bottom",
    },
    {
        selector: "#tour-supplier-history",
        title: "Riwayat Order",
        description: "Pantau daftar lengkap pesanan yang masuk untuk periode ini beserta rincian estimasi Harga Pokok Penjualan (HPP).",
        position: "top",
    },
    {
        selector: "#tour-supplier-download",
        title: "Download Rekap",
        description: "Unduh laporan rekapitulasi hutang dalam format PDF, PNG, atau Excel untuk arsip Anda.",
        position: "bottom",
    },
    {
        selector: "#tour-supplier-more-actions",
        title: "Aksi Lainnya",
        description: "Gunakan menu ini untuk menandai status periode menjadi lunas, sinkronisasi data terbaru, memindahkan event, atau menghapus periode.",
        position: "left",
    },
    {
        selector: "#tour-supplier-debt-info",
        title: "Hutang Supplier",
        description: "Bagian ini digunakan untuk mencatat dan memantau rincian pembayaran hutang Anda kepada supplier. Anda bisa menambah cicilan pembayaran dan memantau status lunas di sini.",
        position: "left",
    },
];

const formatCurrency = (value) => {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(value || 0);
};

// Normalize DB date string to a parseable format (assume WIB / +07:00 if no tz info)
const normalizeDateStr = (dateString) => {
    if (!dateString) return null;
    let dStr = dateString;
    if (typeof dStr === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dStr)) {
        dStr = dStr + 'T00:00:00+07:00';
    } else if (typeof dStr === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(dStr)) {
        dStr = dStr.replace(' ', 'T') + '+07:00';
    }
    return dStr;
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    const dStr = normalizeDateStr(dateString);
    const date = new Date(dStr);
    if (isNaN(date.getTime())) return dateString;

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }).format(date);
};

// Returns { date: '1 Sep 2026', time: '12:45' } for two-line display
const formatDateSplit = (dateString) => {
    if (!dateString) return { date: '-', time: '' };
    const dStr = normalizeDateStr(dateString);
    const d = new Date(dStr);
    if (isNaN(d.getTime())) return { date: dateString, time: '' };
    const tz = 'Asia/Jakarta';
    const datePart = new Intl.DateTimeFormat('id-ID', { timeZone: tz, day: 'numeric', month: 'short', year: 'numeric' }).format(d);
    const timePart = new Intl.DateTimeFormat('id-ID', { timeZone: tz, hour: '2-digit', minute: '2-digit' }).format(d);
    return { date: datePart, time: timePart };
};

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

function getOrderStatusLabel(status, platform) {
    if (!status) return "Unknown";
    const p = (platform || '').toLowerCase();
    const isTiktok = p.includes('tiktok') || p.includes('tts');
    const isShopee = p.includes('shopee');

    if (isTiktok && STATUS_CONFIG_TIKTOK[status]) {
        return STATUS_CONFIG_TIKTOK[status].label;
    } else if (isShopee && STATUS_CONFIG_SHOPEE[status]) {
        return STATUS_CONFIG_SHOPEE[status].label;
    } else if (STATUS_CONFIG_TIKTOK[status]) {
        return STATUS_CONFIG_TIKTOK[status].label;
    } else if (STATUS_CONFIG_SHOPEE[status]) {
        return STATUS_CONFIG_SHOPEE[status].label;
    }
    return status;
}

function StatusBadge({ status, platform }) {
    const label = getOrderStatusLabel(status, platform);
    let isReturn = false;
    const p = (platform || '').toLowerCase();
    if (p.includes('shopee') && STATUS_CONFIG_SHOPEE[status]?.isReturn) {
        isReturn = true;
    } else if (status === 'TO_RETURN') {
        isReturn = true;
    }

    if (isReturn) {
        return (
            <span className="inline-block text-[10px] font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-200/60 dark:border-red-500/20 px-2 py-0.5 rounded">
                {label}
            </span>
        );
    }

    return (
        <span className="inline-block text-[10px] font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 dark:bg-slate-700/60 border border-slate-200/60 dark:border-slate-600/50 px-2 py-0.5 rounded" title={status}>
            {label}
        </span>
    );
}

export default function PayableRekap() {
    const tour = useOnboarding("payable", PAYABLE_TOUR_STEPS.length);
    const [periods, setPeriods] = useState([]);
    const [selectedPeriodId, setSelectedPeriodId] = useState(null);
    const [periodDetails, setPeriodDetails] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isDetailLoading, setIsDetailLoading] = useState(false);

    const selectedPeriodIdRef = useRef(selectedPeriodId);
    const refreshTimeoutRef = useRef(null);

    const [isConfigModalOpen, setIsConfigModalOpen] = useState(false);
    const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const pollingRef = useRef(null);

    // Settings (duration change) modal
    const [isSettingsModalOpen, setIsSettingsModalOpen] = useState(false);
    const [settingsForm, setSettingsForm] = useState({ length_days: 14, apply_mode: 'future' });
    const [settingsDurationConfirm, setSettingsDurationConfirm] = useState(false); // sub-confirm
    const [isSettingsSaving, setIsSettingsSaving] = useState(false);

    // Manual period modal (hidden access)
    const [isManualPeriodOpen, setIsManualPeriodOpen] = useState(false);
    const [manualPeriodForm, setManualPeriodForm] = useState({ name: '', start_date: '', end_date: '' });

    // Payment Status Toggle Modal
    const [isPaymentStatusModalOpen, setIsPaymentStatusModalOpen] = useState(false);
    const [targetPaymentStatus, setTargetPaymentStatus] = useState('PAID');
    const [isUpdatingPaymentStatus, setIsUpdatingPaymentStatus] = useState(false);

    // Event Reassignment modal
    const [isReassignModalOpen, setIsReassignModalOpen] = useState(false);
    const [reassignSearch, setReassignSearch] = useState('');
    const [reassignResults, setReassignResults] = useState([]);
    const [reassignLoading, setReassignLoading] = useState(false);
    const [selectedEventIds, setSelectedEventIds] = useState([]);
    const [reassignTargetPeriodId, setReassignTargetPeriodId] = useState('');
    const [reassignConfirmOpen, setReassignConfirmOpen] = useState(false);
    const [isReassigning, setIsReassigning] = useState(false);
    const reassignSearchTimeout = useRef(null);

    // Suppliers State
    const [suppliers, setSuppliers] = useState([]);
    const [unassignedProductsCount, setUnassignedProductsCount] = useState(0);
    const [selectedSupplierIds, setSelectedSupplierIds] = useState([]); // empty = ALL
    const [isSupplierDropdownOpen, setIsSupplierDropdownOpen] = useState(false);
    const [isSupplierOnboardingOpen, setIsSupplierOnboardingOpen] = useState(false);
    const [isManageSupplierOpen, setIsManageSupplierOpen] = useState(false);

    const supplierDropdownRef = useRef(null);
    const selectedSupplierIdsRef = useRef(selectedSupplierIds);

    // Search and Dropdown States
    const [orderSearch, setOrderSearch] = useState('');
    const [orderStatusFilter, setOrderStatusFilter] = useState('ALL');
    const [returnSearch, setReturnSearch] = useState('');
    const [isPeriodDropdownOpen, setIsPeriodDropdownOpen] = useState(false);
    const [isStatusDropdownOpen, setIsStatusDropdownOpen] = useState(false);
    const [isDownloadOpen, setIsDownloadOpen] = useState(false);
    const [isActionDropdownOpen, setIsActionDropdownOpen] = useState(false);
    const [isExporting, setIsExporting] = useState(false);

    const periodDropdownRef = useRef(null);
    const statusDropdownRef = useRef(null);
    const downloadDropdownRef = useRef(null);
    const actionDropdownRef = useRef(null);
    const reportContainerRef = useRef(null);

    // Config Form State
    const [configForm, setConfigForm] = useState({
        first_period_start: '',
        length_days: 14
    });

    // Payment Form State
    const [paymentForm, setPaymentForm] = useState({
        payment_date: new Date().toISOString().slice(0, 10),
        amount: '',
        payment_method: 'Transfer Bank',
        supplier_id: '',
        notes: ''
    });
    const [editingPaymentId, setEditingPaymentId] = useState(null);
    const [paymentToDelete, setPaymentToDelete] = useState(null);
    const [isDeletingPayment, setIsDeletingPayment] = useState(false);

    // Derived state for single supplier mode
    const isSingleSupplier = selectedSupplierIds.length === 1 || (selectedSupplierIds.length === 0 && suppliers.length === 1);
    const activeSupplierId = selectedSupplierIds.length === 1 ? selectedSupplierIds[0] : (suppliers.length === 1 ? suppliers[0].id : null);

    useEffect(() => {
        selectedPeriodIdRef.current = selectedPeriodId;
    }, [selectedPeriodId]);

    useEffect(() => {
        selectedSupplierIdsRef.current = selectedSupplierIds;
    }, [selectedSupplierIds]);

    const fetchSuppliers = async () => {
        try {
            const res = await axios.get('/api/payable/suppliers');
            const data = res.data.data || [];
            setSuppliers(data);
            setUnassignedProductsCount(res.data.unassigned_products_count || 0);
            if (data.length === 0) {
                setIsSupplierOnboardingOpen(true);
            }
            const validSupplierIds = data.map(s => s.id);
            setSelectedSupplierIds(prev => {
                const filtered = prev.filter(id => validSupplierIds.includes(id));
                selectedSupplierIdsRef.current = filtered;
                return filtered;
            });
            return data;
        } catch (error) {
            console.error("Failed to fetch suppliers", error);
            return [];
        }
    };

    const fetchPeriods = async (silent = false, customSupplierIds = null) => {
        if (!silent) setIsLoading(true);
        try {
            const supIds = customSupplierIds !== null ? customSupplierIds : selectedSupplierIdsRef.current;
            const isSingle = supIds && supIds.length === 1;
            const params = {};
            if (supIds && supIds.length > 0) {
                params.supplier_ids = supIds.join(',');
            }
            const res = await axios.get('/api/payable/periods', { params });
            const data = res.data.data || [];
            setPeriods(data);
            
            let nextSelectedId = selectedPeriodIdRef.current;
            if (!isSingle) {
                nextSelectedId = 'latest';
                selectedPeriodIdRef.current = nextSelectedId;
                setSelectedPeriodId(nextSelectedId);
            } else {
                if (data.length > 0) {
                    const currentId = selectedPeriodIdRef.current;
                    const exists = currentId && data.some(p => p.id === currentId);
                    if (!currentId || !exists || currentId === 'latest') {
                        nextSelectedId = data[0].id;
                        selectedPeriodIdRef.current = nextSelectedId;
                        setSelectedPeriodId(nextSelectedId);
                    }
                } else {
                    nextSelectedId = null;
                    selectedPeriodIdRef.current = null;
                    setSelectedPeriodId(null);
                    setPeriodDetails(null);
                }
            }
            return { periods: data, selectedPeriodId: nextSelectedId };
        } catch (error) {
            if (!silent && error?.response?.status !== 401) toast.error("Gagal mengambil daftar periode");
            return { periods: [], selectedPeriodId: selectedPeriodIdRef.current };
        } finally {
            if (!silent) setIsLoading(false);
        }
    };

    const fetchPeriodDetails = async (id, silent = false, customSupplierIds = null) => {
        if (!id) return;
        if (!silent) setIsDetailLoading(true);
        try {
            const supIds = customSupplierIds !== null ? customSupplierIds : selectedSupplierIdsRef.current;
            const params = {};
            if (supIds && supIds.length > 0) {
                params.supplier_ids = supIds.join(',');
            }
            const res = await axios.get(`/api/payable/periods/${id}/details`, { params });
            setPeriodDetails(res.data.data);
        } catch (error) {
            if (!silent) toast.error("Gagal mengambil detail periode");
        } finally {
            setIsDetailLoading(false);
        }
    };

    const handleToggleSupplier = async (supplierId) => {
        let updated;
        if (selectedSupplierIds.includes(supplierId)) {
            updated = selectedSupplierIds.filter(id => id !== supplierId);
        } else {
            updated = [...selectedSupplierIds, supplierId];
        }
        selectedSupplierIdsRef.current = updated;
        setSelectedSupplierIds(updated);
        if (updated.length === 1) {
            await fetchConfig(updated[0]);
        }
        setIsDetailLoading(true);
        const { selectedPeriodId: nextPeriodId } = await fetchPeriods(true, updated);
        if (nextPeriodId) {
            await fetchPeriodDetails(nextPeriodId, false, updated);
        } else {
            setIsDetailLoading(false);
        }
    };

    const handleSelectAllSuppliers = async () => {
        selectedSupplierIdsRef.current = [];
        setSelectedSupplierIds([]);
        setIsDetailLoading(true);
        const { selectedPeriodId: nextPeriodId } = await fetchPeriods(true, []);
        if (nextPeriodId) {
            await fetchPeriodDetails(nextPeriodId, false, []);
        } else {
            setIsDetailLoading(false);
        }
    };

    const openPaymentModal = () => {
        let defaultSupplierId = '';
        if (selectedSupplierIds.length === 1) {
            defaultSupplierId = String(selectedSupplierIds[0]);
        } else if (suppliers.length === 1) {
            defaultSupplierId = String(suppliers[0].id);
        }
        setEditingPaymentId(null);
        setPaymentForm({
            payment_date: new Date().toISOString().slice(0, 10),
            amount: '',
            payment_method: 'Transfer Bank',
            supplier_id: defaultSupplierId,
            notes: ''
        });
        setIsPaymentModalOpen(true);
    };

    const fetchConfig = async (supplierId = activeSupplierId) => {
        if (!supplierId) return null;
        try {
            const res = await axios.get('/api/payable/config', {
                params: { supplier_id: supplierId }
            });
            if (res.data.data) {
                setConfigForm({
                    first_period_start: res.data.data.first_period_start.slice(0, 16),
                    length_days: res.data.data.length_days
                });
                setSettingsForm(prev => ({ ...prev, length_days: res.data.data.length_days }));
            } else {
                setConfigForm({ first_period_start: '', length_days: 14 });
                setSettingsForm(prev => ({ ...prev, length_days: 14 }));
            }
            return res.data.data;
        } catch (error) {
            console.error("Failed to fetch config", error);
            return null;
        }
    };

    const openConfigModal = async () => {
        if (!activeSupplierId) {
            toast.error('Pilih satu supplier untuk mengatur periodenya');
            return;
        }

        await fetchConfig(activeSupplierId);
        setIsConfigModalOpen(true);
    };

    // --- Settings: save duration change ---
    const handleSaveDuration = async () => {
        if (!activeSupplierId) {
            toast.error('Pilih satu supplier terlebih dahulu');
            return;
        }
        setIsSettingsSaving(true);
        try {
            const res = await axios.post('/api/payable/config/duration', {
                ...settingsForm,
                supplier_id: activeSupplierId
            });
            toast.success(res.data.message || 'Durasi periode berhasil diperbarui');
            setIsSettingsModalOpen(false);
            setSettingsDurationConfirm(false);
            await fetchConfig(activeSupplierId);
            if (settingsForm.apply_mode === 'all') startSyncPolling(selectedPeriodId);
            else await fetchPeriods(true);
        } catch (err) {
            toast.error('Gagal menyimpan durasi periode');
        } finally {
            setIsSettingsSaving(false);
        }
    };

    // --- Manual Period: create ---
    const handleCreateManualPeriod = async (e) => {
        e.preventDefault();
        if (!activeSupplierId) {
            toast.error('Pilih satu supplier terlebih dahulu');
            return;
        }
        try {
            await axios.post('/api/payable/periods/manual', {
                ...manualPeriodForm,
                supplier_id: activeSupplierId
            });
            toast.success('Periode manual berhasil dibuat');
            setIsManualPeriodOpen(false);
            setManualPeriodForm({ name: '', start_date: '', end_date: '' });
            await fetchPeriods(true);
        } catch (err) {
            toast.error('Gagal membuat periode manual');
        }
    };

    // --- Update Payment Status ---
    const handleUpdatePaymentStatus = async () => {
        if (!selectedPeriodId) return;
        setIsUpdatingPaymentStatus(true);
        try {
            const res = await axios.put(`/api/payable/periods/${selectedPeriodId}/status`, { status: targetPaymentStatus });
            toast.success(res.data.message);
            setIsPaymentStatusModalOpen(false);
            fetchPeriodDetails(selectedPeriodId, true);
        } catch (error) {
            toast.error(error.response?.data?.message || 'Gagal mengubah status pelunasan');
        } finally {
            setIsUpdatingPaymentStatus(false);
        }
    };

    // --- Event Reassignment ---
    const handleReassignSearch = (q) => {
        setReassignSearch(q);
        setSelectedEventIds([]);
        if (reassignSearchTimeout.current) clearTimeout(reassignSearchTimeout.current);
        reassignSearchTimeout.current = setTimeout(async () => {
            if (!q.trim()) { setReassignResults([]); return; }
            setReassignLoading(true);
            try {
                const res = await axios.get('/api/payable/events/search', { params: { q, limit: 30 } });
                setReassignResults(res.data.data || []);
            } catch { toast.error('Gagal mencari event'); }
            finally { setReassignLoading(false); }
        }, 400);
    };

    const toggleEventSelection = (id) => {
        setSelectedEventIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const handleConfirmReassign = async () => {
        if (!reassignTargetPeriodId || selectedEventIds.length === 0) return;
        setIsReassigning(true);
        try {
            const res = await axios.post('/api/payable/events/reassign', {
                event_ids: selectedEventIds,
                target_period_id: parseInt(reassignTargetPeriodId),
            });
            toast.success(res.data.message);
            if (res.data.data?.errors?.length > 0) {
                res.data.data.errors.forEach(err => toast.error(err, { duration: 5000 }));
            }
            setReassignConfirmOpen(false);
            setIsReassignModalOpen(false);
            setSelectedEventIds([]);
            setReassignSearch('');
            setReassignResults([]);
            setReassignTargetPeriodId('');
            await fetchPeriods(true);
            if (selectedPeriodId) fetchPeriodDetails(selectedPeriodId, true);
        } catch (err) {
            toast.error('Gagal memindahkan event');
        } finally {
            setIsReassigning(false);
        }
    };

    const getReturnTypeLabel = (type) => {
        switch (type) {
            case 'CREATE_ORDER': return 'Order';
            case 'RETURN_ORDER': return 'Retur';
            case 'FAILED_DELIVERY': return 'Gagal Kirim';
            case 'BUYER_CANCEL': return 'Batal Pembeli';
            default: return type ? type.replace(/_/g, ' ') : '-';
        }
    };

    const triggerAutoRefresh = useCallback(() => {
        if (refreshTimeoutRef.current) {
            clearTimeout(refreshTimeoutRef.current);
        }
        refreshTimeoutRef.current = setTimeout(async () => {
            console.log("PayableRekap: Auto-refreshing data...");
            await fetchPeriods(true);
            const currentId = selectedPeriodIdRef.current;
            if (currentId) {
                fetchPeriodDetails(currentId, true);
            }
        }, 300);
    }, []);

    useEffect(() => {
        const initialize = async () => {
            const supplierData = await fetchSuppliers();
            await fetchPeriods();
            if (supplierData.length === 1) {
                await fetchConfig(supplierData[0].id);
            }
        };

        initialize();
        return () => {
            if (pollingRef.current) clearInterval(pollingRef.current);
            if (refreshTimeoutRef.current) clearTimeout(refreshTimeoutRef.current);
        };
    }, []);

    useEffect(() => {
        function handleClickOutside(event) {
            if (supplierDropdownRef.current && !supplierDropdownRef.current.contains(event.target)) {
                setIsSupplierDropdownOpen(false);
            }
            if (periodDropdownRef.current && !periodDropdownRef.current.contains(event.target)) {
                setIsPeriodDropdownOpen(false);
            }
            if (statusDropdownRef.current && !statusDropdownRef.current.contains(event.target)) {
                setIsStatusDropdownOpen(false);
            }
            if (downloadDropdownRef.current && !downloadDropdownRef.current.contains(event.target)) {
                setIsDownloadOpen(false);
            }
            if (actionDropdownRef.current && !actionDropdownRef.current.contains(event.target)) {
                setIsActionDropdownOpen(false);
            }
        }
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    useEffect(() => {
        if (selectedPeriodId) {
            fetchPeriodDetails(selectedPeriodId);
        }
    }, [selectedPeriodId]);

    // Listen to real-time events from Reverb (PayableUpdated & OrderCreated)
    useEffect(() => {
        const handleRealtimeUpdate = (e) => {
            console.log("PayableRekap: Real-time event received:", e.type, e.detail);
            triggerAutoRefresh();
        };

        window.addEventListener('payable-updated', handleRealtimeUpdate);
        window.addEventListener('order-created', handleRealtimeUpdate);
        return () => {
            window.removeEventListener('payable-updated', handleRealtimeUpdate);
            window.removeEventListener('order-created', handleRealtimeUpdate);
        };
    }, [triggerAutoRefresh]);

    // Refresh when tab gains focus or becomes visible
    useEffect(() => {
        const handleVisibilityOrFocus = () => {
            if (document.visibilityState === 'visible') {
                triggerAutoRefresh();
            }
        };
        document.addEventListener('visibilitychange', handleVisibilityOrFocus);
        window.addEventListener('focus', handleVisibilityOrFocus);
        return () => {
            document.removeEventListener('visibilitychange', handleVisibilityOrFocus);
            window.removeEventListener('focus', handleVisibilityOrFocus);
        };
    }, [triggerAutoRefresh]);

    // Gentle background polling fallback every 20 seconds while page is active
    useEffect(() => {
        const interval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                triggerAutoRefresh();
            }
        }, 20000);
        return () => clearInterval(interval);
    }, [triggerAutoRefresh]);

    const handleSaveConfig = async (e) => {
        e.preventDefault();
        if (!activeSupplierId) {
            toast.error('Pilih satu supplier terlebih dahulu');
            return;
        }
        try {
            await axios.post('/api/payable/config', {
                ...configForm,
                supplier_id: activeSupplierId
            });
            toast.success("Konfigurasi berhasil disimpan, sinkronisasi data dimulai...");
            setIsConfigModalOpen(false);
            // Fetch periods first, then start polling for sync completion
            await fetchPeriods();
            startSyncPolling();
        } catch (error) {
            toast.error("Gagal menyimpan konfigurasi");
        }
    };

    // Poll every 2s until events appear (max 60s)
    const startSyncPolling = (initialPeriodId) => {
        setIsSyncing(true);
        let attempts = 0;
        const maxAttempts = 30;
        if (pollingRef.current) clearInterval(pollingRef.current);
        pollingRef.current = setInterval(async () => {
            attempts++;
            try {
                const res = await axios.get('/api/payable/periods');
                const updatedPeriods = res.data.data || [];
                setPeriods(updatedPeriods);
                // Check if any period now has events
                const hasEvents = updatedPeriods.some(p => (p.event_count || 0) > 0);
                if (hasEvents || attempts >= maxAttempts) {
                    clearInterval(pollingRef.current);
                    pollingRef.current = null;
                    setIsSyncing(false);
                    if (hasEvents) {
                        toast.success("Data pesanan berhasil disinkronisasi!");
                        // Reload detail of selected period, using the first available if not passed
                        const targetId = initialPeriodId || (updatedPeriods.length > 0 ? updatedPeriods[0].id : null);
                        if (targetId) {
                            setSelectedPeriodId(targetId);
                            fetchPeriodDetails(targetId);
                        }
                    } else {
                        toast.error("Sinkronisasi selesai, tapi belum ada data. Cek log server.");
                    }
                }
            } catch (e) {
                // ignore polling errors
            }
        }, 2000);
    };

    const handleSavePayment = async (e) => {
        e.preventDefault();
        try {
            if (editingPaymentId) {
                await axios.put(`/api/payable/payments/${editingPaymentId}`, paymentForm);
                toast.success("Hutang supplier berhasil diperbarui");
            } else {
                await axios.post(`/api/payable/periods/${selectedPeriodId}/payments`, paymentForm);
                toast.success("Hutang supplier berhasil dicatat");
            }
            setIsPaymentModalOpen(false);
            setEditingPaymentId(null);
            setPaymentForm({
                payment_date: new Date().toISOString().slice(0, 10),
                amount: '',
                payment_method: 'Transfer Bank',
                supplier_id: '',
                notes: ''
            });
            fetchPeriodDetails(selectedPeriodId, true);
            fetchPeriods(true);
            fetchSuppliers();
        } catch (error) {
            toast.error("Gagal mencatat hutang supplier");
        }
    };

    const handleEditPayment = (payment) => {
        setEditingPaymentId(payment.id);
        setPaymentForm({
            payment_date: payment.payment_date ? payment.payment_date.split('T')[0] : new Date().toISOString().slice(0, 10),
            amount: payment.amount || '',
            payment_method: payment.payment_method || 'Transfer Bank',
            supplier_id: payment.supplier_id || '',
            notes: payment.notes || ''
        });
        setIsPaymentModalOpen(true);
    };

    const handleDeletePayment = async () => {
        if (!paymentToDelete) return;
        setIsDeletingPayment(true);
        try {
            await axios.delete(`/api/payable/payments/${paymentToDelete.id}`);
            toast.success("Hutang supplier berhasil dihapus");
            setPaymentToDelete(null);
            fetchPeriodDetails(selectedPeriodId, true);
            fetchPeriods(true);
            fetchSuppliers();
        } catch (error) {
            toast.error("Gagal menghapus hutang supplier");
        } finally {
            setIsDeletingPayment(false);
        }
    };

    const handleDeletePeriod = async () => {
        try {
            await axios.delete(`/api/payable/periods/${selectedPeriodId}`);
            toast.success("Periode berhasil dihapus");
            setIsDeleteModalOpen(false);
            setSelectedPeriodId(null);
            setPeriodDetails(null);
            await fetchPeriods();
        } catch (error) {
            toast.error("Gagal menghapus periode");
        }
    };

    const handleManualSync = async () => {
        try {
            await axios.post('/api/payable/sync');
            toast.success("Sinkronisasi dimulai...");
            startSyncPolling(selectedPeriodId);
        } catch (error) {
            toast.error("Gagal memulai sinkronisasi");
        }
    };

    const getReturnLabel = (type) => {
        switch (type) {
            case 'RETURN_ORDER': return 'Retur';
            case 'FAILED_DELIVERY': return 'Batal';
            case 'BUYER_CANCEL': return 'Batal Pembeli';
            default: return type ? type.replace(/_/g, ' ') : '-';
        }
    };

    const [copiedSn, setCopiedSn] = useState(null);
    const handleCopySn = (e, sn) => {
        e.stopPropagation();
        if (!sn) return;
        navigator.clipboard.writeText(sn);
        setCopiedSn(sn);
        toast.success("Order ID disalin", { duration: 1500 });
        setTimeout(() => setCopiedSn(null), 2000);
    };

    const handleNavigateToDetail = (orderId) => {
        if (!orderId) {
            toast.error("Detail pesanan tidak ditemukan");
            return;
        }
        const url = `${window.location.origin}${window.location.pathname}#/orders/${orderId}`;
        window.open(url, "_blank");
    };

    const EXCLUDED_ORDER_STATUSES = ['UNPAID', 'UNKNOWN', 'ON_HOLD', 'CANCEL', 'CANCELLED', 'IN_CANCEL'];

    const orderEvents = (periodDetails?.events || []).filter(ev => {
        if (ev.source_type !== 'CREATE_ORDER') return false;
        const statusUpper = (ev.order_status || '').toUpperCase().trim();
        if (!statusUpper || EXCLUDED_ORDER_STATUSES.includes(statusUpper)) {
            return false;
        }
        const statusLabel = getOrderStatusLabel(ev.order_status, ev.platform);
        if (statusLabel === 'Batal' || statusLabel === 'Pengajuan Batal') {
            return false;
        }
        return true;
    });

    const availableOrderStatuses = Array.from(
        new Set(orderEvents.map(ev => ev.order_status).filter(Boolean))
    );

    useEffect(() => {
        if (orderStatusFilter !== 'ALL' && !availableOrderStatuses.includes(orderStatusFilter)) {
            setOrderStatusFilter('ALL');
        }
    }, [availableOrderStatuses, orderStatusFilter]);

    const returnEvents = (periodDetails?.events || []).filter(ev => ev.source_type !== 'CREATE_ORDER');

    const filteredOrderEvents = orderEvents.filter(ev => {
        if (orderStatusFilter !== 'ALL' && ev.order_status !== orderStatusFilter) {
            return false;
        }
        if (!orderSearch.trim()) return true;
        const q = orderSearch.toLowerCase();
        const statusLabel = getOrderStatusLabel(ev.order_status, ev.platform).toLowerCase();
        const supplierName = (ev.supplier_info?.name || '').toLowerCase();
        return (
            (ev.source_id && ev.source_id.toLowerCase().includes(q)) ||
            (ev.order_status && ev.order_status.toLowerCase().includes(q)) ||
            statusLabel.includes(q) ||
            supplierName.includes(q) ||
            (ev.platform && ev.platform.toLowerCase().includes(q)) ||
            (ev.event_date && ev.event_date.toLowerCase().includes(q)) ||
            (ev.first_product?.product_name && ev.first_product.product_name.toLowerCase().includes(q)) ||
            (ev.first_product?.model_name && ev.first_product.model_name.toLowerCase().includes(q))
        );
    });

    const filteredReturnEvents = returnEvents.filter(ev => {
        if (!returnSearch.trim()) return true;
        const q = returnSearch.toLowerCase();
        const label = getReturnLabel(ev.source_type).toLowerCase();
        const supplierName = (ev.supplier_info?.name || '').toLowerCase();
        return (
            (ev.source_id && ev.source_id.toLowerCase().includes(q)) ||
            (ev.source_type && ev.source_type.toLowerCase().includes(q)) ||
            label.includes(q) ||
            supplierName.includes(q) ||
            (ev.platform && ev.platform.toLowerCase().includes(q)) ||
            (ev.event_date && ev.event_date.toLowerCase().includes(q)) ||
            (ev.first_product?.product_name && ev.first_product.product_name.toLowerCase().includes(q)) ||
            (ev.first_product?.model_name && ev.first_product.model_name.toLowerCase().includes(q))
        );
    });

    const getPeriodName = () => {
        const p = periods.find(x => x.id == selectedPeriodId);
        return p ? p.name.replace(/[^a-zA-Z0-9_-]/g, '_') : 'Periode';
    };

    // Calculate dynamic totals based on active filters
    const dynamicTotalDebt = filteredOrderEvents.reduce((sum, ev) => sum + parseFloat(ev.amount || 0), 0);
    const dynamicTotalReduction = filteredReturnEvents.reduce((sum, ev) => sum + parseFloat(ev.amount || 0), 0);
    const dynamicNetPayable = dynamicTotalDebt + dynamicTotalReduction;

    // ── Helper: build a styled offscreen rekap card, capture it, then remove it
    const buildRekapCanvas = async () => {
        const p = periodDetails.period;
        const sisa = dynamicNetPayable - parseFloat(p.total_paid);
        const activeSuppliersMap = new Map();
        filteredOrderEvents.forEach(e => {
            if (e.supplier_info?.name) activeSuppliersMap.set(e.supplier_info.name, true);
        });
        const supplierNames = Array.from(activeSuppliersMap.keys());
        if (supplierNames.length === 0 && suppliers && suppliers.length > 0) {
            suppliers.forEach(s => supplierNames.push(s.name));
        }

        let badgeText = 'Semua Supplier';
        if (supplierNames.length === 1) {
            badgeText = supplierNames[0];
        } else if (supplierNames.length === 2) {
            badgeText = `${supplierNames[0]} dan ${supplierNames[1]}`;
        } else if (supplierNames.length > 2) {
            badgeText = supplierNames.join(', ');
        }

        const statusBg = '#e0e7ff';
        const statusColor = '#3730a3';
        const status = badgeText;

        // Platform & calculations (using filteredOrderEvents)
        const shopeeOrders = filteredOrderEvents.filter(e => (e.platform || '').toLowerCase().includes('shopee'));
        const tiktokOrders = filteredOrderEvents.filter(e => (e.platform || '').toLowerCase().includes('tiktok'));
        const otherOrders = filteredOrderEvents.filter(e => !(e.platform || '').toLowerCase().includes('shopee') && !(e.platform || '').toLowerCase().includes('tiktok'));
        const shopeeHpp = shopeeOrders.reduce((s, e) => s + parseFloat(e.amount || 0), 0);
        const tiktokHpp = tiktokOrders.reduce((s, e) => s + parseFloat(e.amount || 0), 0);
        const otherHpp = otherOrders.reduce((s, e) => s + parseFloat(e.amount || 0), 0);

        const paymentsList = periodDetails.payments || [];

        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'position:fixed;left:-9999px;top:0;z-index:-1;';

        const card = document.createElement('div');
        card.style.cssText = [
            'width:920px',
            'background:#ffffff',
            'font-family:Inter,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
            'border-radius:24px',
            'overflow:hidden',
            'box-shadow:0 10px 30px rgba(0,0,0,0.08)',
            'border:1px solid #e2e8f0',
            'color:#1e293b'
        ].join(';');

        // ── Header strip
        const header = document.createElement('div');
        header.style.cssText = 'background:linear-gradient(135deg,#1e3a5f 0%,#2b4374 60%,#3b5998 100%);padding:28px 36px;color:#fff;';
        header.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="display:inline-flex;align-items:center;background:rgba(255,255,255,0.12);padding:4px 12px;border-radius:6px;font-size:10px;letter-spacing:1.5px;text-transform:uppercase;font-weight:700;margin-bottom:8px;backdrop-filter:blur(4px);">
                        REKAPITULASI TRANSAKSI DENGAN SUPPLIER
                    </div>
                    <h1 style="font-size:24px;font-weight:800;margin:0 0 4px;letter-spacing:-0.5px;">${p.name}</h1>
                    <p style="font-size:13px;margin:0;opacity:.85;">
                        ${p.is_virtual ? 'Menampilkan periode terbaru untuk masing-masing supplier yang difilter.' : `Periode Transaksi: <strong>${formatDate(p.start_date)}</strong> s/d <strong>${formatDate(p.end_date)}</strong>`}
                    </p>
                </div>
                <div style="text-align:right;">
                    <span style="display:inline-block;background:${statusBg};color:${statusColor};padding:6px 18px;border-radius:100px;font-size:12px;font-weight:800;letter-spacing:.5px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">${status}</span>
                    <p style="font-size:11px;margin:8px 0 0;opacity:.7;">Tgl Cetak: ${new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())}</p>
                </div>
            </div>`;
        card.appendChild(header);

        // ── Summary 4 Metric Cards
        const grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:24px 36px 16px;background:#f8fafc;';

        const metrics = [
            {
                label: 'Total Pesanan (HPP)',
                value: formatCurrency(dynamicTotalDebt),
                sub: `${filteredOrderEvents.length} Pesanan Selesai`,
                color: '#1e3a5f',
                bg: '#ffffff',
                border: '#e2e8f0',
                accent: '#3b82f6'
            },
            {
                label: 'Total Retur & Batal',
                value: formatCurrency(dynamicTotalReduction),
                sub: `${filteredReturnEvents.length} Pengurangan Dicatat`,
                color: '#be123c',
                bg: '#ffffff',
                border: '#e2e8f0',
                accent: '#f43f5e'
            },
            {
                label: 'Total Hutang Supplier',
                value: formatCurrency(p.total_paid),
                sub: `${paymentsList.length} Transaksi Terverifikasi`,
                color: '#065f46',
                bg: '#ffffff',
                border: '#e2e8f0',
                accent: '#10b981'
            },
            {
                label: 'Sisa Tagihan',
                value: formatCurrency(sisa),
                sub: sisa <= 0 ? 'Semua tagihan lunas' : 'Belum terlunasi',
                color: sisa <= 0 ? '#047857' : '#991b1b',
                bg: sisa <= 0 ? '#f0fdf4' : '#fef2f2',
                border: sisa <= 0 ? '#bbf7d0' : '#fecaca',
                accent: sisa <= 0 ? '#10b981' : '#ef4444'
            },
        ];

        metrics.forEach(m => {
            const cell = document.createElement('div');
            cell.style.cssText = `background:${m.bg};border:1px solid ${m.border};border-top:3px solid ${m.accent};border-radius:14px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,0.02);`;
            cell.innerHTML = `
                <p style="font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin:0 0 6px;font-weight:700;">${m.label}</p>
                <p style="font-size:18px;font-weight:800;color:${m.color};margin:0 0 4px;letter-spacing:-0.3px;">${m.value}</p>
                <p style="font-size:10px;color:#94a3b8;margin:0;">${m.sub}</p>`;
            grid.appendChild(cell);
        });
        card.appendChild(grid);

        // ── Main Content: 2-Columns (Rincian Finansial & Riwayat Pembayaran)
        const content = document.createElement('div');
        content.style.cssText = 'padding:16px 36px 24px;display:grid;grid-template-columns:1fr 1fr;gap:20px;';

        // Column 1: Rincian Perhitungan
        const colLeft = document.createElement('div');
        colLeft.style.cssText = 'background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;display:flex;flex-direction:column;justify-content:space-between;';

        let platformBreakdownHtml = '';
        if (filteredOrderEvents.length > 0) {
            platformBreakdownHtml = `
                <div style="margin-top:16px;padding-top:14px;border-top:1px dashed #e2e8f0;">
                    <p style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;">Breakdown Platform</p>
                    <div style="display:flex;gap:8px;">
                        ${shopeeOrders.length > 0 ? `
                            <div style="flex:1;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:8px 10px;">
                                <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:700;color:#c2410c;margin-bottom:2px;">
                                    <span>SHOPEE</span>
                                    <span>${shopeeOrders.length} order</span>
                                </div>
                                <div style="font-size:12px;font-weight:800;color:#9a3412;">${formatCurrency(shopeeHpp)}</div>
                            </div>` : ''}
                        ${tiktokOrders.length > 0 ? `
                            <div style="flex:1;background:#ecfeff;border:1px solid #a5f3fc;border-radius:8px;padding:8px 10px;">
                                <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:700;color:#0e7490;margin-bottom:2px;">
                                    <span>TIKTOK SHOP</span>
                                    <span>${tiktokOrders.length} order</span>
                                </div>
                                <div style="font-size:12px;font-weight:800;color:#155e75;">${formatCurrency(tiktokHpp)}</div>
                            </div>` : ''}
                        ${otherOrders.length > 0 ? `
                            <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;">
                                <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:700;color:#475569;margin-bottom:2px;">
                                    <span>LAINNYA</span>
                                    <span>${otherOrders.length} order</span>
                                </div>
                                <div style="font-size:12px;font-weight:800;color:#334155;">${formatCurrency(otherHpp)}</div>
                            </div>` : ''}
                    </div>
                </div>`;
        }

        colLeft.innerHTML = `
            <div>
                <h3 style="font-size:12px;font-weight:800;color:#1e3a5f;text-transform:uppercase;letter-spacing:.8px;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                    Rincian Perhitungan Rekapitulasi
                </h3>
                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <tbody>
                        <tr>
                            <td style="padding:6px 0;color:#64748b;">Total Nilai Pesanan (HPP)</td>
                            <td style="padding:6px 0;text-align:right;font-weight:700;color:#1e293b;">+ ${formatCurrency(dynamicTotalDebt)}</td>
                        </tr>
                        <tr>
                            <td style="padding:6px 0;color:#64748b;">Potongan Retur & Pengembalian</td>
                            <td style="padding:6px 0;text-align:right;font-weight:700;color:#be123c;">- ${formatCurrency(Math.abs(dynamicTotalReduction))}</td>
                        </tr>
                        <tr style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                            <td style="padding:8px 0;font-weight:700;color:#1e293b;">Total Tagihan Bersih (Net)</td>
                            <td style="padding:8px 0;text-align:right;font-weight:800;color:#1e3a5f;font-size:12px;">${formatCurrency(dynamicNetPayable)}</td>
                        </tr>
                        <tr>
                            <td style="padding:6px 0;color:#64748b;">Total Hutang Supplier</td>
                            <td style="padding:6px 0;text-align:right;font-weight:700;color:#065f46;">- ${formatCurrency(p.total_paid)}</td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top:12px;background:${sisa <= 0 ? '#ecfdf5' : '#fef2f2'};border:1px solid ${sisa <= 0 ? '#a7f3d0' : '#fecaca'};border-radius:10px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <span style="font-size:10px;font-weight:700;color:${sisa <= 0 ? '#047857' : '#991b1b'};text-transform:uppercase;letter-spacing:.5px;display:block;">Sisa Kewajiban</span>
                    </div>
                    <span style="font-size:16px;font-weight:900;color:${sisa <= 0 ? '#047857' : '#991b1b'};">${formatCurrency(sisa)}</span>
                </div>
            </div>
            ${platformBreakdownHtml}
        `;
        content.appendChild(colLeft);

        // Column 2: Riwayat Pembayaran
        const colRight = document.createElement('div');
        colRight.style.cssText = 'background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;display:flex;flex-direction:column;';

        let paymentRowsHtml = '';
        if (paymentsList.length === 0) {
            paymentRowsHtml = `
                <tr>
                    <td colspan="4" style="padding:28px 12px;text-align:center;color:#94a3b8;font-size:11px;">
                        <div style="font-weight:600;margin-bottom:2px;">Belum ada catatan hutang supplier</div>
                        <div style="font-size:10px;">Hutang supplier yang dicatat akan terdata di sini</div>
                    </td>
                </tr>`;
        } else {
            paymentRowsHtml = paymentsList.map((pmt, idx) => `
                <tr style="background:${idx % 2 === 0 ? '#fff' : '#f8fafc'};">
                    <td style="padding:7px 8px;font-size:11px;color:#334155;border-bottom:1px solid #f1f5f9;">${formatDate(pmt.payment_date)}</td>
                    <td style="padding:7px 8px;font-size:11px;color:#334155;border-bottom:1px solid #f1f5f9;font-weight:600;">${pmt.payment_method || '-'}</td>
                    <td style="padding:7px 8px;font-size:10px;color:#64748b;border-bottom:1px solid #f1f5f9;">${pmt.notes || '-'}</td>
                    <td style="padding:7px 8px;font-size:11px;color:#065f46;font-weight:800;text-align:right;border-bottom:1px solid #f1f5f9;">${formatCurrency(pmt.amount)}</td>
                </tr>
            `).join('');
        }

        colRight.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">
                <h3 style="font-size:12px;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.8px;margin:0;">
                    Riwayat Hutang Supplier
                </h3>
                <span style="font-size:10px;color:#64748b;font-weight:600;">${paymentsList.length} transaksi</span>
            </div>
            <div style="flex:1;overflow:hidden;border:1px solid #f1f5f9;border-radius:10px;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:7px 8px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Tanggal</th>
                            <th style="padding:7px 8px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Metode</th>
                            <th style="padding:7px 8px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Catatan</th>
                            <th style="padding:7px 8px;text-align:right;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${paymentRowsHtml}
                    </tbody>
                    ${paymentsList.length > 0 ? `
                        <tfoot>
                            <tr style="background:#f0fdf4;border-top:1px solid #bbf7d0;">
                                <td colspan="3" style="padding:8px 8px;font-size:11px;font-weight:700;color:#065f46;">TOTAL HUTANG SUPPLIER</td>
                                <td style="padding:8px 8px;font-size:12px;font-weight:800;color:#065f46;text-align:right;">${formatCurrency(p.total_paid)}</td>
                            </tr>
                        </tfoot>` : ''}
                </table>
            </div>
        `;
        content.appendChild(colRight);
        card.appendChild(content);

        // ── Signatures & Verification Area
        const signSection = document.createElement('div');
        signSection.style.cssText = 'padding:14px 36px 20px;display:flex;justify-content:space-between;background:#f8fafc;border-top:1px solid #e2e8f0;';
        signSection.innerHTML = `
            <div style="width:220px;text-align:center;">
                <p style="font-size:10px;color:#64748b;margin:0 0 45px;">Dibuat / Disiapkan Oleh,</p>
                <div style="border-bottom:1px dashed #94a3b8;width:100%;margin-bottom:4px;"></div>
                <p style="font-size:11px;font-weight:700;color:#334155;margin:0;">Bagian Keuangan / Admin</p>
            </div>
            <div style="width:220px;text-align:center;">
                <p style="font-size:10px;color:#64748b;margin:0 0 45px;">Diverifikasi & Diterima Oleh,</p>
                <div style="border-bottom:1px dashed #94a3b8;width:100%;margin-bottom:4px;"></div>
                <p style="font-size:11px;font-weight:700;color:#334155;margin:0;">Pihak Supplier</p>
            </div>
        `;
        card.appendChild(signSection);

        // ── Footer
        const footer = document.createElement('div');
        footer.style.cssText = 'background:#1e293b;padding:10px 36px;display:flex;justify-content:space-between;align-items:center;color:#94a3b8;font-size:10px;';
        footer.innerHTML = `
            <p style="margin:0;">FineSheet Omnichannel &bull; Dicetak: ${new Date().toLocaleString('id-ID')} &bull; ID Periode: #${p.id}</p>
            <p style="margin:0;opacity:.8;">Dokumen Rekapitulasi Transaksi Supplier Resmi</p>`;
        card.appendChild(footer);

        wrapper.appendChild(card);
        document.body.appendChild(wrapper);

        const canvas = await html2canvas(card, {
            scale: 2,
            useCORS: true,
            logging: false,
            backgroundColor: '#ffffff',
            width: 920,
        });
        document.body.removeChild(wrapper);
        return canvas;
    };

    const exportToExcel = () => {
        if (!periodDetails) return;
        try {
            const wb = XLSX.utils.book_new();
            const p = periodDetails.period;
            const sisa = dynamicNetPayable - parseFloat(p.total_paid);

            // ── Helper: apply header style
            const headerStyle = {
                font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11 },
                fill: { fgColor: { rgb: '1E3A5F' } },
                alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
                border: { bottom: { style: 'thin', color: { rgb: 'FFFFFF' } } }
            };
            const labelStyle = {
                font: { bold: true, color: { rgb: '1E3A5F' }, sz: 10 },
                fill: { fgColor: { rgb: 'EEF2FF' } },
                alignment: { horizontal: 'left' }
            };
            const valueStyle = { font: { sz: 10 }, alignment: { horizontal: 'left' } };
            const currencyStyle = { font: { bold: true, sz: 10 }, numFmt: '"Rp"#,##0', alignment: { horizontal: 'right' } };
            const subHeaderStyle = {
                font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 10 },
                fill: { fgColor: { rgb: '304674' } },
                alignment: { horizontal: 'center' },
                border: { bottom: { style: 'thin', color: { rgb: '4F6FA8' } } }
            };
            const altRowStyle = { fill: { fgColor: { rgb: 'F8FAFC' } } };

            // ── Sheet 1: Ringkasan
            const wsSum = XLSX.utils.aoa_to_sheet([
                ['REKAPITULASI TRANSAKSI DENGAN SUPPLIER'],
                [''],
                ['Nama Periode', p.name],
                ['Rentang', p.is_virtual ? 'Periode berjalan' : `${formatDate(p.start_date)} s/d ${formatDate(p.end_date)}`],
                ['Supplier Terkait', badgeText],
                [''],
                ['Ringkasan Keuangan (Berdasarkan Filter)', ''],
                ['Sisa Tagihan', sisa],
                ['Total Pesanan (HPP)', parseFloat(dynamicTotalDebt)],
                ['Total Retur & Batal', parseFloat(dynamicTotalReduction)],
                ['Total Hutang Supplier', parseFloat(p.total_paid)],
                [''],
                ['Jumlah Order', filteredOrderEvents.length],
                ['Jumlah Pengembalian', filteredReturnEvents.length],
                ['Jumlah Hutang Supplier', (periodDetails.payments || []).length],
            ]);
            // Title merge & style
            wsSum['A1'].s = { font: { bold: true, sz: 16, color: { rgb: '1E3A5F' } }, alignment: { horizontal: 'left' } };
            wsSum['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 1 } }];
            wsSum['!cols'] = [{ wch: 30 }, { wch: 45 }];
            // Section headers
            ['A7'].forEach(addr => { if (wsSum[addr]) wsSum[addr].s = labelStyle; });
            ['A8', 'A9', 'A10', 'A11'].forEach(addr => { if (wsSum[addr]) wsSum[addr].s = labelStyle; });
            ['B8', 'B9', 'B10', 'B11'].forEach(addr => { if (wsSum[addr]) wsSum[addr].s = currencyStyle; });
            XLSX.utils.book_append_sheet(wb, wsSum, '📊 Ringkasan');

            // ── Sheet 2: Riwayat Order
            const orderAoa = [
                ['RIWAYAT ORDER (HPP)'],
                ['Tanggal', 'Order ID', 'Status', 'Supplier', 'Item Summary', 'Platform', 'HPP'],
                ...filteredOrderEvents.map(ev => [
                    formatDate(ev.event_date),
                    ev.source_id || '-',
                    getOrderStatusLabel(ev.order_status, ev.platform),
                    ev.supplier_info?.name || '-',
                    ev.first_product ? (ev.first_product.product_name + (ev.first_product.model_name ? ` (${ev.first_product.model_name})` : '') + (ev.product_count > 1 ? ` +${ev.product_count - 1} produk` : '')) : '-',
                    ev.platform || '-',
                    parseFloat(ev.amount)
                ]),
                [],
                ['TOTAL', '', '', '', '', '', filteredOrderEvents.reduce((s, ev) => s + parseFloat(ev.amount || 0), 0)]
            ];
            const wsO = XLSX.utils.aoa_to_sheet(orderAoa.length > 2 ? orderAoa : [['Tidak ada data']]);
            if (wsO['A1']) wsO['A1'].s = { font: { bold: true, sz: 14, color: { rgb: '1E3A5F' } } };
            if (wsO['A2']) ['A2', 'B2', 'C2', 'D2', 'E2', 'F2', 'G2'].forEach(a => { if (wsO[a]) wsO[a].s = subHeaderStyle; });
            wsO['!cols'] = [{ wch: 22 }, { wch: 28 }, { wch: 20 }, { wch: 22 }, { wch: 35 }, { wch: 12 }, { wch: 18 }];
            XLSX.utils.book_append_sheet(wb, wsO, '🛒 Riwayat Order');

            // ── Sheet 3: Pengembalian
            const retAoa = [
                ['RIWAYAT PENGEMBALIAN & BATAL'],
                ['Tanggal', 'Tipe', 'Order ID', 'Supplier', 'Item Summary', 'Platform', 'Nominal Pengurangan'],
                ...filteredReturnEvents.map(ev => [
                    formatDate(ev.event_date),
                    getReturnLabel(ev.source_type),
                    ev.source_id || '-',
                    ev.supplier_info?.name || '-',
                    ev.first_product ? (ev.first_product.product_name + (ev.first_product.model_name ? ` (${ev.first_product.model_name})` : '') + (ev.product_count > 1 ? ` +${ev.product_count - 1} produk` : '')) : '-',
                    ev.platform || '-',
                    Math.abs(parseFloat(ev.amount))
                ]),
                [],
                ['TOTAL PENGURANGAN', '', '', '', '', '', filteredReturnEvents.reduce((s, ev) => s + Math.abs(parseFloat(ev.amount || 0)), 0)]
            ];
            const wsR = XLSX.utils.aoa_to_sheet(retAoa.length > 2 ? retAoa : [['Tidak ada data']]);
            if (wsR['A1']) wsR['A1'].s = { font: { bold: true, sz: 14, color: { rgb: 'BE123C' } } };
            if (wsR['A2']) ['A2', 'B2', 'C2', 'D2', 'E2', 'F2', 'G2'].forEach(a => { if (wsR[a]) wsR[a].s = subHeaderStyle; });
            wsR['!cols'] = [{ wch: 22 }, { wch: 16 }, { wch: 28 }, { wch: 22 }, { wch: 35 }, { wch: 12 }, { wch: 20 }];
            XLSX.utils.book_append_sheet(wb, wsR, '↩️ Pengembalian');

            // ── Sheet 4: Hutang Supplier
            const paidAoa = [
                ['RIWAYAT HUTANG SUPPLIER'],
                ['Tanggal Bayar', 'Metode / Bank', 'Supplier', 'Nominal', 'Catatan'],
                ...(periodDetails.payments || []).map(pmt => [
                    formatDate(pmt.payment_date),
                    pmt.payment_method,
                    pmt.supplier?.name || '-',
                    parseFloat(pmt.amount),
                    pmt.notes || '-'
                ]),
                [],
                ['TOTAL DIBAYAR', '', '', (periodDetails.payments || []).reduce((s, pmt) => s + parseFloat(pmt.amount || 0), 0), '']
            ];
            const wsP = XLSX.utils.aoa_to_sheet(paidAoa.length > 2 ? paidAoa : [['Belum ada hutang supplier']]);
            if (wsP['A1']) wsP['A1'].s = { font: { bold: true, sz: 14, color: { rgb: '065F46' } } };
            if (wsP['A2']) ['A2', 'B2', 'C2', 'D2', 'E2'].forEach(a => { if (wsP[a]) wsP[a].s = subHeaderStyle; });
            wsP['!cols'] = [{ wch: 22 }, { wch: 20 }, { wch: 22 }, { wch: 18 }, { wch: 30 }];
            XLSX.utils.book_append_sheet(wb, wsP, '💳 Hutang Supplier');

            XLSX.writeFile(wb, `Rekapitulasi_Hutang_Supplier_${getPeriodName()}.xlsx`);
            toast.success('File Excel berhasil diunduh!');
        } catch (err) {
            console.error('Excel export error', err);
            toast.error('Gagal mengunduh file Excel');
        }
    };

    const exportToPng = async () => {
        if (!periodDetails) return;
        setIsExporting(true);
        const toastId = toast.loading('Sedang memproses gambar PNG...');
        try {
            const canvas = await buildRekapCanvas();
            const link = document.createElement('a');
            link.download = `Rekapitulasi_Hutang_Supplier_${getPeriodName()}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            toast.success('Gambar PNG berhasil diunduh!', { id: toastId });
        } catch (err) {
            console.error('PNG export error', err);
            toast.error('Gagal membuat gambar PNG', { id: toastId });
        } finally {
            setIsExporting(false);
        }
    };

    const exportToPdf = async () => {
        if (!periodDetails) return;
        setIsExporting(true);
        const toastId = toast.loading('Sedang memproses dokumen PDF...');
        try {
            const canvas = await buildRekapCanvas();
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF('l', 'mm', 'a4');
            const pageW = 297; const pageH = 210; const margin = 12;
            const avW = pageW - margin * 2;
            const avH = pageH - margin * 2;
            const ratio = canvas.width / canvas.height;
            let drawW = avW;
            let drawH = drawW / ratio;
            if (drawH > avH) { drawH = avH; drawW = drawH * ratio; }
            const offsetX = margin + (avW - drawW) / 2;
            const offsetY = margin + (avH - drawH) / 2;
            pdf.addImage(imgData, 'PNG', offsetX, offsetY, drawW, drawH);
            pdf.save(`Rekapitulasi_Hutang_Supplier_${getPeriodName()}.pdf`);
            toast.success('Dokumen PDF berhasil diunduh!', { id: toastId });
        } catch (err) {
            console.error('PDF export error', err);
            toast.error('Gagal membuat dokumen PDF', { id: toastId });
        } finally {
            setIsExporting(false);
        }
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'PAID': return 'bg-emerald-500/10 text-emerald-500 ring-emerald-500/20';
            case 'PARTIAL': return 'bg-amber-500/10 text-amber-500 ring-amber-500/20';
            default: return 'bg-rose-500/10 text-rose-500 ring-rose-500/20';
        }
    };

    const getStatusText = (status) => {
        switch (status) {
            case 'PAID': return 'Lunas';
            case 'PARTIAL': return 'Dibayar Sebagian';
            default: return 'Belum Dibayar';
        }
    };

    const isAnyModalOpen = isConfigModalOpen || isPaymentModalOpen || isDeleteModalOpen || isSettingsModalOpen || isManualPeriodOpen || isReassignModalOpen || reassignConfirmOpen || isSupplierOnboardingOpen || isManageSupplierOpen || isPaymentStatusModalOpen || !!paymentToDelete;

    return (
        <AppLayout isBlurred={isAnyModalOpen}>
            <div className="min-h-screen bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 p-3 sm:p-4 lg:p-0 font-sans selection:bg-indigo-500/30">

                {/* Header Section */}
                <div className="grid grid-cols-1 xl:grid-cols-[auto_1fr_auto] gap-4 mb-4 sm:mb-5 xl:items-center">
                    <div>
                        <h1 className="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">
                            Suppliers transactions
                        </h1>
                        <p className="text-slate-500 dark:text-slate-400 mt-0.5 text-xs sm:text-sm">Rekapitulasi HPP & Hutang Supplier per Periode</p>
                    </div>

                    {/* Filter Controls (Left Anchored) */}
                    <div id="tour-supplier-actions" className="flex items-center flex-wrap gap-2.5">

                        {/* Supplier Filter / Single Badge / Unassigned Warning */}
                        {suppliers.length === 1 && (
                            <div className="flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-indigo-200 dark:border-indigo-800/60 rounded-xl p-1 shadow-sm">
                                <div className="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 rounded-lg text-xs font-semibold">
                                    <Building2 className="w-3.5 h-3.5 text-indigo-500" />
                                    <span className="truncate max-w-[150px]">{suppliers[0].name}</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setIsManageSupplierOpen(true)}
                                    className="p-1.5 text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                                    title="Kelola Supplier & Produk"
                                >
                                    <Settings className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}

                        {suppliers.length > 1 && (
                            <div className="relative w-full sm:w-[260px]" ref={supplierDropdownRef}>
                                <button
                                    type="button"
                                    onClick={() => setIsSupplierDropdownOpen(!isSupplierDropdownOpen)}
                                    className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-3.5 pr-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-sm transition-all hover:border-gray-300 dark:hover:border-slate-600 cursor-pointer"
                                >
                                    <div className="flex items-center gap-2 truncate pr-1">
                                        <Building2 className="w-4 h-4 text-indigo-500 flex-shrink-0" />
                                        <span className="truncate font-medium text-xs sm:text-sm">
                                            {selectedSupplierIds.length === 0
                                                ? "Semua Supplier"
                                                : selectedSupplierIds.length === 1
                                                    ? suppliers.find(s => s.id === selectedSupplierIds[0])?.name || "1 Supplier Dipilih"
                                                    : `${selectedSupplierIds.length} Supplier Dipilih`
                                            }
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1.5 flex-shrink-0">
                                        {selectedSupplierIds.length > 0 && (
                                            <span className="w-5 h-5 rounded-full bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 text-[10px] font-bold flex items-center justify-center">
                                                {selectedSupplierIds.length}
                                            </span>
                                        )}
                                        <ChevronDown
                                            className={`w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform duration-300 ${isSupplierDropdownOpen ? "rotate-180" : ""}`}
                                        />
                                    </div>
                                </button>

                                <AnimatePresence>
                                    {isSupplierDropdownOpen && (
                                        <motion.div
                                            initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                            animate={{ opacity: 1, y: 0, scale: 1 }}
                                            exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                            transition={{ duration: 0.2, ease: "easeOut" }}
                                            className="absolute left-0 mt-2 w-full min-w-[280px] bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden z-50 origin-top"
                                        >
                                            <div className="p-2 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                                <span className="text-xs font-bold text-slate-600 dark:text-slate-300 px-2">Filter Supplier</span>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setIsSupplierDropdownOpen(false);
                                                        setIsManageSupplierOpen(true);
                                                    }}
                                                    className="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline px-2 py-1 flex items-center gap-1"
                                                >
                                                    <Settings className="w-3 h-3" />
                                                    Kelola
                                                </button>
                                            </div>
                                            <div className="max-h-64 overflow-y-auto p-2 space-y-1">
                                                {/* Option: Semua Supplier */}
                                                <button
                                                    type="button"
                                                    onClick={handleSelectAllSuppliers}
                                                    className={`w-full flex items-center justify-between text-left px-3 py-2 text-xs font-medium rounded-xl transition-colors ${
                                                        selectedSupplierIds.length === 0
                                                           ? "bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 font-bold"
                                                           : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <div className={`w-4 h-4 rounded border flex items-center justify-center ${
                                                            selectedSupplierIds.length === 0
                                                                ? "bg-indigo-500 border-indigo-500 text-white"
                                                                : "border-slate-300 dark:border-slate-600"
                                                        }`}>
                                                            {selectedSupplierIds.length === 0 && <Check className="w-3 h-3 stroke-[3]" />}
                                                        </div>
                                                        <span>Semua Supplier</span>
                                                    </div>
                                                    <span className="text-[10px] text-slate-400 font-semibold">{suppliers.length} supplier</span>
                                                </button>

                                                <div className="h-px bg-slate-100 dark:bg-slate-800 my-1" />

                                                {/* Each Supplier */}
                                                {suppliers.map(sup => {
                                                    const isSelected = selectedSupplierIds.includes(sup.id);
                                                    return (
                                                        <button
                                                            key={sup.id}
                                                            type="button"
                                                            onClick={() => handleToggleSupplier(sup.id)}
                                                            className={`w-full flex items-center justify-between text-left px-3 py-2 text-xs font-medium rounded-xl transition-colors ${
                                                                isSelected
                                                                    ? "bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 font-semibold"
                                                                    : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                            }`}
                                                        >
                                                            <div className="flex items-center gap-2 truncate pr-2">
                                                                <div className={`w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
                                                                    isSelected
                                                                        ? "bg-indigo-500 border-indigo-500 text-white"
                                                                        : "border-slate-300 dark:border-slate-600"
                                                                }`}>
                                                                    {isSelected && <Check className="w-3 h-3 stroke-[3]" />}
                                                                </div>
                                                                <span className="truncate">{sup.name}</span>
                                                            </div>
                                                            {sup.products_count !== undefined && (
                                                                <span className="text-[10px] text-slate-400 font-medium px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 flex-shrink-0">
                                                                    {sup.products_count} SKU
                                                                </span>
                                                            )}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        )}

                        {suppliers.length === 0 && (
                            <button
                                type="button"
                                onClick={() => setIsSupplierOnboardingOpen(true)}
                                className="flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold transition-colors shadow-sm cursor-pointer"
                            >
                                <Building2 className="w-3.5 h-3.5" />
                                <span>Setup Supplier</span>
                            </button>
                        )}

                        {/* Unassigned Products Warning Badge */}
                        {unassignedProductsCount > 0 && suppliers.length > 1 && (
                            <button
                                type="button"
                                onClick={() => setIsManageSupplierOpen(true)}
                                className="flex items-center gap-1.5 px-3 py-2 bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-800/60 rounded-xl text-xs font-semibold hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors shadow-sm cursor-pointer"
                                title="Ada SKU baru yang belum dihubungkan ke supplier. Klik untuk memetakan."
                            >
                                <AlertTriangle className="w-3.5 h-3.5 text-amber-500 flex-shrink-0" />
                                <span className="truncate max-w-[160px] sm:max-w-none">{unassignedProductsCount} Produk Belum Dipetakan</span>
                            </button>
                        )}


                        {/* Custom Period Dropdown (Identical to Store Dropdown in OrderList) */}
                        {isSingleSupplier && (
                        <div className="relative w-full sm:w-[350px]" ref={periodDropdownRef}>
                            <button
                                type="button"
                                onClick={() => setIsPeriodDropdownOpen(!isPeriodDropdownOpen)}
                                className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2.5 pl-4 pr-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-sm transition-all hover:border-gray-300 dark:hover:border-slate-600 cursor-pointer"
                            >
                                <span className="truncate pr-2 font-medium">
                                    {periods.find(p => p.id == selectedPeriodId)?.name || (periods.length === 0 ? "Belum ada periode" : "Pilih Periode")}
                                </span>
                                <ChevronDown
                                    className={`w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform duration-300 ${isPeriodDropdownOpen ? "rotate-180" : ""
                                        }`}
                                />
                            </button>

                            <AnimatePresence>
                                {isPeriodDropdownOpen && (
                                    <motion.div
                                        initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                        animate={{ opacity: 1, y: 0, scale: 1 }}
                                        exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                        transition={{ duration: 0.2, ease: "easeOut" }}
                                        className="absolute right-0 md:left-0 mt-2 w-full min-w-[260px] bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden z-50 origin-top"
                                    >
                                        <div className="max-h-64 overflow-y-auto p-2">
                                            {periods.length === 0 && (
                                                <div className="p-3 text-center text-xs text-slate-400">Belum ada periode</div>
                                            )}
                                            {periods.map((period) => (
                                                <button
                                                    key={period.id}
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedPeriodId(period.id);
                                                        setIsPeriodDropdownOpen(false);
                                                    }}
                                                    className={`w-full flex items-center justify-between text-left px-3 py-2.5 mt-1 text-sm font-medium rounded-xl transition-colors ${selectedPeriodId == period.id
                                                        ? "bg-[#304674] text-white dark:bg-blue-600"
                                                        : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                        }`}
                                                >
                                                    <span className="truncate">{period.name}</span>
                                                    {period.event_count !== undefined && (
                                                        <span
                                                            className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${selectedPeriodId == period.id
                                                                ? "bg-white/20 text-white"
                                                                : "bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400"
                                                                }`}
                                                        >
                                                            {period.event_count} orders
                                                        </span>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                        )}

                        {/* Minimalist PAID Badge */}
                        {isSingleSupplier && periodDetails && !periodDetails.period.is_virtual && periodDetails.period.payment_status === 'PAID' && (
                            <div
                                className="flex items-center gap-1.5 px-3 py-2 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/20 rounded-xl text-[11px] font-bold tracking-widest uppercase cursor-default select-none shadow-sm"
                                title="Periode ini telah dilunasi"
                            >
                                <CheckCircle2 className="w-3.5 h-3.5" />
                                <span>Paid</span>
                            </div>
                        )}

                    </div>

                    {/* Action Controls (Right Anchored) */}
                    <div className="flex items-center flex-wrap gap-2.5 xl:justify-end">
                        {/* Download Rekapitulasi Dropdown (PDF, PNG, Excel) */}
                        {selectedPeriodId && periodDetails && (
                            <div id="tour-supplier-download" className="relative" ref={downloadDropdownRef}>
                                <button
                                    type="button"
                                    onClick={() => setIsDownloadOpen(!isDownloadOpen)}
                                    disabled={isExporting}
                                    className="flex items-center gap-2 px-3.5 py-2.5 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shadow-sm text-xs font-semibold cursor-pointer"
                                    title="Download Rekapitulasi"
                                >
                                    <Download className="w-4 h-4 text-indigo-500" />
                                    <span className="hidden sm:inline">Download Rekap</span>
                                    <ChevronDown className={`w-3.5 h-3.5 text-slate-400 transition-transform ${isDownloadOpen ? 'rotate-180' : ''}`} />
                                </button>

                                <AnimatePresence>
                                    {isDownloadOpen && (
                                        <motion.div
                                            initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                            animate={{ opacity: 1, y: 0, scale: 1 }}
                                            exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                            transition={{ duration: 0.2, ease: "easeOut" }}
                                            className="absolute left-0 sm:left-auto sm:right-0 mt-2 w-52 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 p-1.5 z-50 origin-top"
                                        >
                                            <button
                                                type="button"
                                                onClick={() => { exportToPdf(); setIsDownloadOpen(false); }}
                                                className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                            >
                                                <FileText className="w-4 h-4 text-rose-500" />
                                                <span>Dokumen PDF (.pdf)</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => { exportToPng(); setIsDownloadOpen(false); }}
                                                className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                            >
                                                <Image className="w-4 h-4 text-cyan-500" />
                                                <span>Gambar PNG (.png)</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => { exportToExcel(); setIsDownloadOpen(false); }}
                                                className="w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                            >
                                                <FileSpreadsheet className="w-4 h-4 text-emerald-500" />
                                                <span>Spreadsheet Excel (.xlsx)</span>
                                            </button>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        )}

                        {/* More Actions Dropdown */}
                        <div id="tour-supplier-more-actions" className="relative" ref={actionDropdownRef}>
                            <button
                                type="button"
                                onClick={() => setIsActionDropdownOpen(!isActionDropdownOpen)}
                                className="p-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors shadow-sm cursor-pointer"
                                title="Aksi Lainnya"
                            >
                                <Settings className="w-5 h-5 text-slate-600 dark:text-slate-300" />
                            </button>

                            <AnimatePresence>
                                {isActionDropdownOpen && (
                                    <motion.div
                                        initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                        animate={{ opacity: 1, y: 0, scale: 1 }}
                                        exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                        transition={{ duration: 0.2, ease: "easeOut" }}
                                        className="absolute left-0 sm:left-auto sm:right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 p-1.5 z-50 origin-top"
                                    >
                                        <div className="px-3 py-2 text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-1">
                                            Kelola Periode
                                        </div>

                                        {isSingleSupplier && (
                                            <button
                                                type="button"
                                                onClick={() => { setIsManualPeriodOpen(true); setIsActionDropdownOpen(false); }}
                                                className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                            >
                                                <Plus className="w-4 h-4 text-indigo-500" />
                                                <span>Buat Periode Manual</span>
                                            </button>
                                        )}

                                        {isSingleSupplier && periodDetails && !periodDetails.period.is_virtual && (
                                            periodDetails.period.payment_status === 'PAID' ? (
                                                <button
                                                    type="button"
                                                    onClick={() => { setTargetPaymentStatus('UNPAID'); setIsPaymentStatusModalOpen(true); setIsActionDropdownOpen(false); }}
                                                    className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-xl transition-colors text-left cursor-pointer"
                                                >
                                                    <CheckCircle2 className="w-4 h-4" />
                                                    <span>Lunas (Batalkan)</span>
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => { setTargetPaymentStatus('PAID'); setIsPaymentStatusModalOpen(true); setIsActionDropdownOpen(false); }}
                                                    className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                                >
                                                    <Check className="w-4 h-4 text-emerald-500" />
                                                    <span>Tandai Lunas</span>
                                                </button>
                                            )
                                        )}

                                        <button
                                            type="button"
                                            onClick={() => { setIsReassignModalOpen(true); setReassignSearch(''); setReassignResults([]); setSelectedEventIds([]); setReassignTargetPeriodId(''); setIsActionDropdownOpen(false); }}
                                            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer"
                                        >
                                            <ArrowLeftRight className="w-4 h-4 text-amber-500" />
                                            <span>Pindah Event</span>
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => { handleManualSync(); setIsActionDropdownOpen(false); }}
                                            disabled={isSyncing}
                                            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors text-left cursor-pointer disabled:opacity-50"
                                        >
                                            <RotateCcw className={`w-4 h-4 text-blue-500 ${isSyncing ? 'animate-spin' : ''}`} />
                                            <span>Sinkronisasi Data</span>
                                        </button>

                                        {selectedPeriodId && (
                                            <>
                                                <div className="h-px bg-slate-100 dark:bg-slate-800 my-1"></div>
                                                <button
                                                    type="button"
                                                    onClick={() => { setIsDeleteModalOpen(true); setIsActionDropdownOpen(false); }}
                                                    className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm font-medium text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-500/10 rounded-xl transition-colors text-left cursor-pointer"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                    <span>Hapus Periode</span>
                                                </button>
                                            </>
                                        )}
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                    </div>
                </div>

                {isLoading ? (
                    <div className="flex justify-center items-center h-64">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-500"></div>
                    </div>
                ) : isDetailLoading ? (
                    // ── Skeleton Loading ─────────────────────────────────────────
                    <div className="space-y-4 animate-pulse">
                        {/* Top Section Skeleton: 4 metric cards + payment card */}
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-5">
                            {/* Left: 4 metric card skeletons */}
                            <div className="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                {[...Array(4)].map((_, i) => (
                                    <div key={i} className="bg-white/70 dark:bg-slate-800/70 rounded-3xl p-5 border border-slate-200/50 dark:border-slate-700/50">
                                        <div className="flex items-center gap-2 mb-3">
                                            <div className="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-700"></div>
                                            <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                        </div>
                                        <div className="h-7 w-36 bg-slate-200 dark:bg-slate-700 rounded-xl mb-2"></div>
                                        <div className="h-2.5 w-20 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                    </div>
                                ))}
                            </div>
                            {/* Right: payment card skeleton */}
                            <div className="bg-white/70 dark:bg-slate-800/70 rounded-3xl border border-slate-200/50 dark:border-slate-700/50 overflow-hidden">
                                <div className="p-4 border-b border-slate-100 dark:border-slate-700/50">
                                    <div className="h-4 w-32 bg-slate-200 dark:bg-slate-700 rounded-full mb-2"></div>
                                    <div className="h-3 w-20 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                </div>
                                <div className="p-4 space-y-3">
                                    {[...Array(3)].map((_, i) => (
                                        <div key={i} className="flex justify-between items-center p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50">
                                            <div className="space-y-1.5">
                                                <div className="h-3.5 w-24 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                                <div className="h-2.5 w-16 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                            </div>
                                            <div className="h-3 w-16 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Bottom Section Skeleton: order table + return table */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">
                            {[...Array(2)].map((_, tableIdx) => (
                                <div key={tableIdx} className="bg-white/70 dark:bg-slate-800/70 rounded-3xl border border-slate-200/50 dark:border-slate-700/50 overflow-hidden">
                                    {/* Table header */}
                                    <div className="p-4 border-b border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <div className="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-700"></div>
                                            <div>
                                                <div className="h-3.5 w-28 bg-slate-200 dark:bg-slate-700 rounded-full mb-1.5"></div>
                                                <div className="h-2.5 w-20 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                            </div>
                                        </div>
                                        <div className="h-8 w-40 bg-slate-100 dark:bg-slate-700/60 rounded-xl"></div>
                                    </div>
                                    {/* Table rows */}
                                    <div className="divide-y divide-slate-100 dark:divide-slate-700/50">
                                        {[...Array(5)].map((_, rowIdx) => (
                                            <div key={rowIdx} className="flex items-center gap-4 px-5 py-3.5">
                                                {/* Date col */}
                                                <div className="space-y-1.5 flex-shrink-0 w-14">
                                                    <div className="h-2.5 w-12 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                                    <div className="h-2 w-10 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                                </div>
                                                {/* ID col */}
                                                <div className="flex-1 space-y-1.5">
                                                    <div className="h-3 w-32 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                                    <div className="h-2.5 w-14 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                                </div>
                                                {/* Product col */}
                                                <div className="flex items-center gap-2">
                                                    <div className="w-9 h-9 rounded-lg bg-slate-200 dark:bg-slate-700 flex-shrink-0"></div>
                                                    <div className="space-y-1.5">
                                                        <div className="h-2.5 w-24 bg-slate-200 dark:bg-slate-700 rounded-full"></div>
                                                        <div className="h-2 w-16 bg-slate-100 dark:bg-slate-700/60 rounded-full"></div>
                                                    </div>
                                                </div>
                                                {/* Amount col */}
                                                <div className="h-3 w-16 bg-slate-200 dark:bg-slate-700 rounded-full ml-auto"></div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                    // ── End Skeleton ──────────────────────────────────────────────
                ) : !periodDetails ? (
                    <div className="flex flex-col items-center justify-center h-64 text-center">
                        <Wallet className="w-16 h-16 text-slate-300 dark:text-slate-600 mb-4" />
                        <p className="text-slate-500 dark:text-slate-400">Belum ada data periode yang dapat ditampilkan.</p>
                        <button
                            type="button"
                            onClick={openConfigModal}
                            className="mt-4 text-indigo-500 hover:text-indigo-600 font-medium text-sm"
                        >
                            Atur Konfigurasi Sekarang &rarr;
                        </button>
                    </div>
                ) : (
                    <div className="space-y-4" ref={reportContainerRef}>
                        {/* Syncing Banner */}
                        {isSyncing && (
                            <motion.div
                                initial={{ opacity: 0, y: -10 }}
                                animate={{ opacity: 1, y: 0 }}
                                className="flex items-center gap-3 bg-indigo-500/10 border border-indigo-500/30 rounded-2xl px-4 py-3"
                            >
                                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-indigo-500 flex-shrink-0"></div>
                                <div>
                                    <p className="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Sinkronisasi data sedang berjalan...</p>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400">Data pesanan akan muncul otomatis dalam beberapa detik.</p>
                                </div>
                            </motion.div>
                        )}

                        {/* Top Section: Metrics + Card Pembayaran */}
                        <div id="tour-supplier-summary" className="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-5">

                            {/* Left 2 Cols: 4 Metric Cards */}
                            <div className="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">

                                {/* Card 1: Sisa Tagihan (Soft Pastel Indigo Theme) */}
                                <motion.div
                                    initial={{ opacity: 0, y: 15 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    className="bg-indigo-50/70 dark:bg-indigo-950/30 backdrop-blur-xl rounded-3xl p-5 shadow-sm border border-indigo-100/80 dark:border-indigo-800/40 relative overflow-hidden flex flex-col justify-between"
                                >
                                    <div className="absolute -right-3 -top-3 p-4 opacity-10 text-indigo-500 pointer-events-none">
                                        <Wallet className="w-24 h-24" />
                                    </div>
                                    <div className="relative z-10">
                                        <div className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 mb-2">
                                            <div className="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center">
                                                <Wallet className="w-4 h-4 text-indigo-600 dark:text-indigo-300" />
                                            </div>
                                            <span className="text-sm font-semibold">Total Tagihan</span>
                                        </div>
                                        <div className="text-2xl sm:text-3xl font-bold text-indigo-950 dark:text-indigo-100 tracking-tight">
                                            {formatCurrency(dynamicNetPayable - periodDetails.period.total_paid)}
                                        </div>
                                    </div>
                                    <div className="relative z-10 mt-4 flex items-center justify-end">
                                        {filteredOrderEvents.length > 0 && (
                                            <span className="text-xs text-indigo-600/70 dark:text-indigo-300/70 font-medium">
                                                {filteredOrderEvents.length} transaksi
                                            </span>
                                        )}
                                    </div>
                                </motion.div>

                                {/* Card 2: Total Pesanan (HPP) */}
                                <motion.div
                                    initial={{ opacity: 0, y: 15 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.05 }}
                                    className="bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl p-5 shadow-sm border border-slate-200/50 dark:border-slate-700/50 flex flex-col justify-between"
                                >
                                    <div>
                                        <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400 mb-2">
                                            <div className="w-8 h-8 rounded-full bg-emerald-500/10 flex items-center justify-center">
                                                <TrendingUp className="w-4 h-4 text-emerald-500" />
                                            </div>
                                            <span className="text-sm font-medium">Total Pesanan (HPP)</span>
                                        </div>
                                        <div className="text-2xl font-bold text-slate-800 dark:text-white">
                                            {formatCurrency(dynamicTotalDebt)}
                                        </div>
                                    </div>
                                    <div className="mt-4 text-xs text-slate-400">
                                        {filteredOrderEvents.length} pesanan tercatat
                                    </div>
                                </motion.div>

                                {/* Card 3: Retur & Batal */}
                                <motion.div
                                    initial={{ opacity: 0, y: 15 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.1 }}
                                    className="bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl p-5 shadow-sm border border-slate-200/50 dark:border-slate-700/50 flex flex-col justify-between"
                                >
                                    <div>
                                        <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400 mb-2">
                                            <div className="w-8 h-8 rounded-full bg-rose-500/10 flex items-center justify-center">
                                                <TrendingDown className="w-4 h-4 text-rose-500" />
                                            </div>
                                            <span className="text-sm font-medium">Retur & Batal</span>
                                        </div>
                                        <div className="text-2xl font-bold text-rose-500">
                                            {formatCurrency(dynamicTotalReduction)}
                                        </div>
                                    </div>
                                    <div className="mt-4 text-xs text-slate-400">
                                        {filteredReturnEvents.length} pengurangan dicatat
                                    </div>
                                </motion.div>

                                {/* Card 4: Total Dibayar */}
                                <motion.div
                                    initial={{ opacity: 0, y: 15 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.15 }}
                                    className="bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl p-5 shadow-sm border border-slate-200/50 dark:border-slate-700/50 flex flex-col justify-between"
                                >
                                    <div>
                                        <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400 mb-2">
                                            <div className="w-8 h-8 rounded-full bg-cyan-500/10 flex items-center justify-center">
                                                <CheckCircle2 className="w-4 h-4 text-cyan-500" />
                                            </div>
                                            <span className="text-sm font-medium">Total Hutang Supplier</span>
                                        </div>
                                        <div className="text-2xl font-bold text-cyan-500">
                                            {formatCurrency(periodDetails.period.total_paid)}
                                        </div>
                                    </div>
                                    <div className="mt-4 text-xs text-slate-400">
                                        {(periodDetails.payments || []).length} hutang terverifikasi
                                    </div>
                                </motion.div>

                            </div>

                            {/* Right 1 Col: Card Hutang Supplier */}
                            <motion.div
                                id="tour-supplier-debt-info"
                                initial={{ opacity: 0, y: 15 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.2 }}
                                className="lg:col-span-1 bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-700/50 overflow-hidden flex flex-col justify-between"
                            >
                                <div className="p-4 sm:p-5 border-b border-slate-100 dark:border-slate-700/50 flex justify-between items-center">
                                    <div className="flex items-center gap-2">
                                        <div className="w-8 h-8 rounded-full bg-indigo-500/10 flex items-center justify-center">
                                            <CreditCard className="w-4 h-4 text-indigo-500" />
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-800 dark:text-white">Data Hutang Supplier</h3>
                                            <p className="text-[11px] text-slate-400">{periodDetails.payments.length} data</p>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={openPaymentModal}
                                        className="flex items-center gap-1 px-3 py-1.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-xs font-semibold transition-colors shadow-sm shadow-indigo-500/30 cursor-pointer"
                                        title="Tambah Hutang Supplier Baru"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                        <span>Tambah Hutang</span>
                                    </button>
                                </div>
                                <div className="p-4 sm:p-5 flex-1 overflow-y-auto max-h-[260px]">
                                    <div className="space-y-3">
                                        {periodDetails.payments.map(payment => (
                                            <div key={payment.id} className="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 hover:border-indigo-500/20 transition-colors">
                                                <div className="flex justify-between items-start">
                                                    <div>
                                                        <p className="text-sm font-bold text-slate-800 dark:text-white mb-0.5">
                                                            {payment.payment_method && payment.payment_method !== 'Transfer Bank' ? payment.payment_method : 'Hutang Supplier'}
                                                        </p>
                                                        <p className="text-xs font-semibold text-rose-500 dark:text-rose-400 mb-1.5">
                                                            {formatCurrency(payment.amount)}
                                                        </p>
                                                        <div className="flex flex-wrap items-center gap-1.5 mt-0.5">
                                                            {payment.supplier && (
                                                                <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/50">
                                                                    <Building2 className="w-2.5 h-2.5" />
                                                                    {payment.supplier.name}
                                                                </span>
                                                            )}
                                                            <span className="text-[10px] font-medium text-slate-500 dark:text-slate-400 bg-white dark:bg-slate-700 px-2 py-0.5 rounded border border-slate-100 dark:border-slate-700/50">
                                                                {formatDate(payment.payment_date).split(',')[0]}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <button 
                                                            onClick={() => handleEditPayment(payment)}
                                                            className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded-lg transition-colors cursor-pointer"
                                                            title="Edit Hutang"
                                                        >
                                                            <Settings className="w-3.5 h-3.5" />
                                                        </button>
                                                        <button 
                                                            onClick={() => {
                                                                setPaymentToDelete(payment);
                                                                setIsDeleteModalOpen(false); // Make sure this is standard naming? Wait I will just make it inline or use a separate state
                                                            }}
                                                            className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors cursor-pointer"
                                                            title="Hapus Hutang"
                                                        >
                                                            <Trash2 className="w-3.5 h-3.5" />
                                                        </button>
                                                    </div>
                                                </div>
                                                {payment.notes && (
                                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-2 bg-white/80 dark:bg-slate-800 p-2 rounded-lg italic border border-slate-100 dark:border-slate-700/50">
                                                        "{payment.notes}"
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                        {periodDetails.payments.length === 0 && (
                                            <div className="text-center py-8">
                                                <CreditCard className="w-9 h-9 text-slate-300 dark:text-slate-600 mx-auto mb-2" />
                                                <p className="text-xs text-slate-500">Belum ada data hutang supplier</p>
                                                <button
                                                    type="button"
                                                    onClick={openPaymentModal}
                                                    className="mt-2 text-xs text-indigo-500 hover:text-indigo-600 font-semibold cursor-pointer"
                                                >
                                                    + Tambah Hutang Supplier
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </motion.div>

                        </div>

                        {/* Bottom Section: Riwayat Order & Riwayat Pengembalian */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5">

                            {/* Left: Riwayat Order (with Search Bar) */}
                            <motion.div
                                id="tour-supplier-history"
                                initial={{ opacity: 0, y: 15 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.1 }}
                                className="bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-700/50 flex flex-col"
                            >
                                <div className="p-4 sm:p-5 border-b border-slate-100 dark:border-slate-700/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <div className="w-8 h-8 rounded-full bg-cyan-500/10 flex items-center justify-center flex-shrink-0">
                                            <TrendingUp className="w-4 h-4 text-cyan-500" />
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-800 dark:text-white">Riwayat Order</h3>
                                            <span className="text-[10px] text-slate-400">
                                                {filteredOrderEvents.length} dari {orderEvents.length} order
                                            </span>
                                        </div>
                                    </div>

                                    {/* Search & Status Filter */}
                                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                                        {availableOrderStatuses.length > 0 && (
                                            <div className="relative w-full sm:w-[190px]" ref={statusDropdownRef}>
                                                <button
                                                    type="button"
                                                    onClick={() => setIsStatusDropdownOpen(!isStatusDropdownOpen)}
                                                    className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-1.5 pl-3.5 pr-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-xs transition-all hover:border-gray-300 dark:hover:border-slate-600 cursor-pointer"
                                                >
                                                    <span className="truncate pr-2 font-medium">
                                                        {orderStatusFilter === 'ALL'
                                                            ? "Semua Status"
                                                            : (() => {
                                                                const sample = orderEvents.find(e => e.order_status === orderStatusFilter);
                                                                return getOrderStatusLabel(orderStatusFilter, sample?.platform);
                                                            })()}
                                                    </span>
                                                    <ChevronDown
                                                        className={`w-3.5 h-3.5 text-gray-400 dark:text-slate-500 transition-transform duration-300 flex-shrink-0 ${isStatusDropdownOpen ? "rotate-180" : ""}`}
                                                    />
                                                </button>

                                                <AnimatePresence>
                                                    {isStatusDropdownOpen && (
                                                        <motion.div
                                                            initial={{ opacity: 0, y: 10, scale: 0.95 }}
                                                            animate={{ opacity: 1, y: 0, scale: 1 }}
                                                            exit={{ opacity: 0, y: 10, scale: 0.95 }}
                                                            transition={{ duration: 0.2, ease: "easeOut" }}
                                                            className="absolute right-0 mt-2 w-full min-w-[210px] bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden z-40 origin-top"
                                                        >
                                                            <div className="max-h-64 overflow-y-auto p-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setOrderStatusFilter('ALL');
                                                                        setIsStatusDropdownOpen(false);
                                                                    }}
                                                                    className={`w-full flex items-center justify-between text-left px-3 py-2 text-xs font-medium rounded-xl transition-colors cursor-pointer ${orderStatusFilter === 'ALL'
                                                                        ? "bg-[#304674] text-white dark:bg-blue-600"
                                                                        : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                                        }`}
                                                                >
                                                                    <span className="truncate">Semua Status</span>
                                                                    <span
                                                                        className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${orderStatusFilter === 'ALL'
                                                                            ? "bg-white/20 text-white"
                                                                            : "bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400"
                                                                            }`}
                                                                    >
                                                                        {orderEvents.length}
                                                                    </span>
                                                                </button>
                                                                {availableOrderStatuses.map(st => {
                                                                    const sampleEv = orderEvents.find(e => e.order_status === st);
                                                                    const label = getOrderStatusLabel(st, sampleEv?.platform);
                                                                    const count = orderEvents.filter(e => e.order_status === st).length;
                                                                    const isSelected = orderStatusFilter === st;
                                                                    return (
                                                                        <button
                                                                            key={st}
                                                                            type="button"
                                                                            onClick={() => {
                                                                                setOrderStatusFilter(st);
                                                                                setIsStatusDropdownOpen(false);
                                                                            }}
                                                                            className={`w-full flex items-center justify-between text-left px-3 py-2 mt-1 text-xs font-medium rounded-xl transition-colors cursor-pointer ${isSelected
                                                                                ? "bg-[#304674] text-white dark:bg-blue-600"
                                                                                : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
                                                                                }`}
                                                                        >
                                                                            <span className="truncate">{label}</span>
                                                                            <span
                                                                                className={`text-[10px] font-semibold px-2 py-0.5 rounded-full ${isSelected
                                                                                    ? "bg-white/20 text-white"
                                                                                    : "bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400"
                                                                                    }`}
                                                                            >
                                                                                {count}
                                                                            </span>
                                                                        </button>
                                                                    );
                                                                })}
                                                            </div>
                                                        </motion.div>
                                                    )}
                                                </AnimatePresence>
                                            </div>
                                        )}
                                        <div className="relative w-full sm:w-56">
                                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5 pointer-events-none" />
                                            <input
                                                type="text"
                                                placeholder="Cari order ID atau produk..."
                                                value={orderSearch}
                                                onChange={e => setOrderSearch(e.target.value)}
                                                className="w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-1.5 pl-8 pr-7 rounded-xl focus:ring-1 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-[#304674] dark:focus:border-blue-500 text-xs transition-all outline-none"
                                            />
                                            {orderSearch && (
                                                <button
                                                    type="button"
                                                    onClick={() => setOrderSearch('')}
                                                    className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-slate-300"
                                                >
                                                    <X className="w-3.5 h-3.5" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="overflow-x-auto flex-1 max-h-[440px] overflow-y-auto rounded-b-3xl">
                                    <table className="w-full text-left text-sm whitespace-nowrap">
                                        <thead className="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/70 sticky top-0 backdrop-blur-sm z-10">
                                            <tr>
                                                <th className="px-5 py-3 font-semibold">
                                                    <div className="relative inline-flex items-center gap-1 group/th cursor-help">
                                                        <span className="border-b border-dotted border-slate-400/60 dark:border-slate-500 pb-0.5">TANGGAL</span>
                                                        <div className="absolute left-0 top-full mt-1.5 px-2.5 py-1 bg-slate-900/95 dark:bg-slate-800 text-white text-[11px] font-normal rounded-lg shadow-xl whitespace-nowrap opacity-0 invisible group-hover/th:opacity-100 group-hover/th:visible transition-all duration-200 z-30 pointer-events-none border border-slate-700/50">
                                                            Tanggal pemesanan
                                                            <div className="absolute bottom-full left-4 -mb-[3px] border-4 border-transparent border-b-slate-900/95 dark:border-b-slate-800"></div>
                                                        </div>
                                                    </div>
                                                </th>
                                                <th className="px-5 py-3 font-semibold">ORDER ID</th>
                                                <th className="px-5 py-3 font-semibold">ITEM SUMMARY</th>
                                                <th className="px-5 py-3 font-semibold text-right">HPP</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700/50">
                                            {filteredOrderEvents.map(ev => (
                                                <tr
                                                    key={ev.id}
                                                    onClick={() => handleNavigateToDetail(ev.order_id)}
                                                    className="hover:bg-indigo-50/40 dark:hover:bg-slate-700/30 transition-colors group cursor-pointer"
                                                    title={ev.order_id ? "Klik untuk melihat detail pesanan (tab baru)" : ""}
                                                >
                                                    <td className="px-5 py-3.5 text-xs text-slate-600 dark:text-slate-300 align-middle" title="">
                                                        <div className="relative inline-flex flex-col group/date cursor-help">
                                                            {(() => { const { date, time } = formatDateSplit(ev.event_date); return (<><span className="block text-slate-400 dark:text-slate-500 mt-0.5">{time}</span><span className="block font-medium text-slate-700 dark:text-slate-200">{date}</span></>); })()}
                                                            <div className="absolute left-full top-1/2 -translate-y-1/2 ml-2.5 px-2.5 py-1 bg-slate-900/95 dark:bg-slate-800 text-white text-[11px] font-normal rounded-lg shadow-xl whitespace-nowrap opacity-0 invisible group-hover/date:opacity-100 group-hover/date:visible transition-all duration-200 z-30 pointer-events-none border border-slate-700/50">
                                                                Tanggal pemesanan
                                                                <div className="absolute right-full top-1/2 -translate-y-1/2 -mr-[3px] border-4 border-transparent border-r-slate-900/95 dark:border-r-slate-800"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-3.5 font-medium text-xs text-slate-700 dark:text-slate-200 align-middle">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="font-mono font-semibold text-[#304674] dark:text-blue-400 group-hover:underline">
                                                                {ev.source_id || '-'}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={(e) => handleCopySn(e, ev.source_id)}
                                                                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-0.5 rounded transition-colors"
                                                                title="Salin Order ID"
                                                            >
                                                                {copiedSn === ev.source_id ? (
                                                                    <Check className="w-3.5 h-3.5 text-emerald-500" />
                                                                ) : (
                                                                    <Copy className="w-3.5 h-3.5" />
                                                                )}
                                                            </button>
                                                        </div>
                                                        <div className="flex flex-wrap items-center gap-1.5 mt-1">
                                                            {ev.platform && (
                                                                <span className={`inline-block text-[10px] uppercase font-semibold px-2 py-0.5 rounded ${ev.platform.toLowerCase().includes('shopee')
                                                                    ? 'bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400'
                                                                    : ev.platform.toLowerCase().includes('tiktok')
                                                                        ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-400'
                                                                        : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300'
                                                                    }`}>
                                                                    {ev.platform}
                                                                </span>
                                                            )}
                                                            {ev.order_status && (
                                                                <StatusBadge status={ev.order_status} platform={ev.platform} />
                                                            )}
                                                            {ev.supplier_info && (
                                                                <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/50">
                                                                    <Building2 className="w-2.5 h-2.5" />
                                                                    {ev.supplier_info.name}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-3.5 align-middle">
                                                        {ev.first_product ? (
                                                            <div className="flex items-center gap-2.5 max-w-[240px]">
                                                                {ev.first_product.variant_image || ev.first_product.image ? (
                                                                    <img
                                                                        src={ev.first_product.variant_image || ev.first_product.image}
                                                                        className="w-10 h-10 rounded-lg border border-slate-200 dark:border-slate-700 object-cover flex-shrink-0 shadow-sm"
                                                                        alt=""
                                                                    />
                                                                ) : (
                                                                    <div className="w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0 text-slate-400 dark:text-slate-500">
                                                                        <Image className="w-5 h-5 opacity-60" />
                                                                    </div>
                                                                )}
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="font-medium text-xs text-slate-700 dark:text-slate-200 truncate" title={ev.first_product.product_name}>
                                                                        {ev.first_product.product_name}
                                                                    </p>
                                                                    {ev.product_count > 1 ? (
                                                                        <span className="text-[11px] text-blue-600 dark:text-blue-400 font-semibold block">
                                                                            +{ev.product_count - 1} produk lainnya
                                                                        </span>
                                                                    ) : ev.first_product.model_name ? (
                                                                        <span className="text-[11px] text-slate-400 dark:text-slate-500 truncate block" title={ev.first_product.model_name}>
                                                                            Var: {ev.first_product.model_name}
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-slate-400 italic">-</span>
                                                        )}
                                                    </td>
                                                    <td className="px-5 py-3.5 font-bold text-xs text-right text-slate-800 dark:text-white align-middle">
                                                        +{formatCurrency(ev.amount)}
                                                    </td>
                                                </tr>
                                            ))}
                                            {filteredOrderEvents.length === 0 && (
                                                <tr>
                                                    <td colSpan="4" className="px-6 py-10 text-center text-slate-400 text-xs">
                                                        {orderSearch ? "Tidak ada pesanan yang sesuai dengan kata kunci" : "Tidak ada data order di periode ini"}
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </motion.div>

                            {/* Right: Riwayat Pengembalian (with Search Bar) */}
                            <motion.div
                                initial={{ opacity: 0, y: 15 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.15 }}
                                className="bg-white/70 dark:bg-slate-800/70 backdrop-blur-xl rounded-3xl shadow-sm border border-slate-200/50 dark:border-slate-700/50 overflow-hidden flex flex-col"
                            >
                                <div className="p-4 sm:p-5 border-b border-slate-100 dark:border-slate-700/50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <div className="w-8 h-8 rounded-full bg-rose-500/10 flex items-center justify-center flex-shrink-0">
                                            <TrendingDown className="w-4 h-4 text-rose-500" />
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-800 dark:text-white">Riwayat Pembatalan & Pengembalian</h3>
                                            <span className="text-[10px] text-slate-400">
                                                {filteredReturnEvents.length} dari {returnEvents.length} pembatalan & pengembalian
                                            </span>
                                        </div>
                                    </div>

                                    {/* Search Return Input */}
                                    <div className="relative w-full sm:w-60">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-3.5 h-3.5 pointer-events-none" />
                                        <input
                                            type="text"
                                            placeholder="Cari return, order ID, produk..."
                                            value={returnSearch}
                                            onChange={e => setReturnSearch(e.target.value)}
                                            className="w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-1.5 pl-8 pr-7 rounded-xl focus:ring-1 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-[#304674] dark:focus:border-blue-500 text-xs transition-all outline-none"
                                        />
                                        {returnSearch && (
                                            <button
                                                type="button"
                                                onClick={() => setReturnSearch('')}
                                                className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-slate-300"
                                            >
                                                <X className="w-3.5 h-3.5" />
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <div className="overflow-x-auto flex-1 max-h-[440px] overflow-y-auto">
                                    <table className="w-full text-left text-sm whitespace-nowrap">
                                        <thead className="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/70 sticky top-0 backdrop-blur-sm z-10">
                                            <tr>
                                                <th className="px-5 py-3 font-semibold">
                                                    <div className="relative inline-flex items-center gap-1 group/th cursor-help">
                                                        <span className="border-b border-dotted border-slate-400/60 dark:border-slate-500 pb-0.5">TANGGAL</span>
                                                        <div className="absolute left-0 top-full mt-1.5 px-2.5 py-1 bg-slate-900/95 dark:bg-slate-800 text-white text-[11px] font-normal rounded-lg shadow-xl whitespace-nowrap opacity-0 invisible group-hover/th:opacity-100 group-hover/th:visible transition-all duration-200 z-30 pointer-events-none border border-slate-700/50">
                                                            Tanggal pembatalan/pengembalian
                                                            <div className="absolute bottom-full left-4 -mb-[3px] border-4 border-transparent border-b-slate-900/95 dark:border-b-slate-800"></div>
                                                        </div>
                                                    </div>
                                                </th>
                                                <th className="px-5 py-3 font-semibold">TIPE</th>
                                                <th className="px-5 py-3 font-semibold">ORDER ID</th>
                                                <th className="px-5 py-3 font-semibold">ITEM SUMMARY</th>
                                                <th className="px-5 py-3 font-semibold text-right">HPP</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700/50">
                                            {filteredReturnEvents.map(ev => (
                                                <tr
                                                    key={ev.id}
                                                    onClick={() => handleNavigateToDetail(ev.order_id)}
                                                    className="hover:bg-rose-50/30 dark:hover:bg-slate-700/30 transition-colors group cursor-pointer"
                                                    title={ev.order_id ? "Klik untuk melihat detail pesanan (tab baru)" : ""}
                                                >
                                                    <td className="px-5 py-3.5 text-xs text-slate-600 dark:text-slate-300 align-middle" title="">
                                                        <div className="relative inline-flex flex-col group/date cursor-help">
                                                            {(() => { const { date, time } = formatDateSplit(ev.event_date); return (<><span className="block text-slate-400 dark:text-slate-500 mt-0.5">{time}</span><span className="block font-medium text-slate-700 dark:text-slate-200">{date}</span></>); })()}
                                                            <div className="absolute left-full top-1/2 -translate-y-1/2 ml-2.5 px-2.5 py-1 bg-slate-900/95 dark:bg-slate-800 text-white text-[11px] font-normal rounded-lg shadow-xl whitespace-nowrap opacity-0 invisible group-hover/date:opacity-100 group-hover/date:visible transition-all duration-200 z-30 pointer-events-none border border-slate-700/50">
                                                                Tanggal pembatalan/pengembalian
                                                                <div className="absolute right-full top-1/2 -translate-y-1/2 -mr-[3px] border-4 border-transparent border-r-slate-900/95 dark:border-r-slate-800"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-3.5 align-middle">
                                                        <span className="px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-rose-500/10 text-rose-500">
                                                            {getReturnLabel(ev.source_type)}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3.5 font-medium text-xs text-slate-700 dark:text-slate-200 align-middle">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="font-mono font-semibold text-[#304674] dark:text-blue-400 group-hover:underline">
                                                                {ev.source_id || '-'}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={(e) => handleCopySn(e, ev.source_id)}
                                                                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-0.5 rounded transition-colors"
                                                                title="Salin Order ID"
                                                            >
                                                                {copiedSn === ev.source_id ? (
                                                                    <Check className="w-3.5 h-3.5 text-emerald-500" />
                                                                ) : (
                                                                    <Copy className="w-3.5 h-3.5" />
                                                                )}
                                                            </button>
                                                        </div>
                                                        {ev.platform && (
                                                            <span className={`mt-1 inline-block text-[10px] uppercase font-semibold px-2 py-0.5 rounded ${ev.platform.toLowerCase().includes('shopee')
                                                                ? 'bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400'
                                                                : ev.platform.toLowerCase().includes('tiktok')
                                                                    ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-400'
                                                                    : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300'
                                                                }`}>
                                                                {ev.platform}
                                                            </span>
                                                        )}
                                                        {ev.supplier_info && (
                                                            <span className="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800/50">
                                                                <Building2 className="w-2.5 h-2.5" />
                                                                {ev.supplier_info.name}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-5 py-3.5 align-middle">
                                                        {ev.first_product ? (
                                                            <div className="flex items-center gap-2.5 max-w-[240px]">
                                                                {ev.first_product.variant_image || ev.first_product.image ? (
                                                                    <img
                                                                        src={ev.first_product.variant_image || ev.first_product.image}
                                                                        className="w-10 h-10 rounded-lg border border-slate-200 dark:border-slate-700 object-cover flex-shrink-0 shadow-sm"
                                                                        alt=""
                                                                    />
                                                                ) : (
                                                                    <div className="w-10 h-10 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0 text-slate-400 dark:text-slate-500">
                                                                        <Image className="w-5 h-5 opacity-60" />
                                                                    </div>
                                                                )}
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="font-medium text-xs text-slate-700 dark:text-slate-200 truncate" title={ev.first_product.product_name}>
                                                                        {ev.first_product.product_name}
                                                                    </p>
                                                                    {ev.product_count > 1 ? (
                                                                        <span className="text-[11px] text-blue-600 dark:text-blue-400 font-semibold block">
                                                                            +{ev.product_count - 1} produk lainnya
                                                                        </span>
                                                                    ) : ev.first_product.model_name ? (
                                                                        <span className="text-[11px] text-slate-400 dark:text-slate-500 truncate block" title={ev.first_product.model_name}>
                                                                            Var: {ev.first_product.model_name}
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-slate-400 italic">-</span>
                                                        )}
                                                    </td>
                                                    <td className="px-5 py-3.5 font-bold text-xs text-right text-rose-500 align-middle">
                                                        -{formatCurrency(Math.abs(ev.amount))}
                                                    </td>
                                                </tr>
                                            ))}
                                            {filteredReturnEvents.length === 0 && (
                                                <tr>
                                                    <td colSpan="5" className="px-6 py-10 text-center text-slate-400 text-xs">
                                                        {returnSearch ? "Tidak ada pengembalian yang sesuai dengan kata kunci" : "Tidak ada data pengembalian di periode ini"}
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </motion.div>

                        </div>
                    </div>
                )}

                {/* Modals */}
                <AnimatePresence>
                    {/* Derived state for single supplier mode */}
                    {/* Initial Config Modal (first setup) */}
                    {isConfigModalOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setIsConfigModalOpen(false)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-md rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden">
                                <h2 className="text-lg font-bold text-slate-800 dark:text-white mb-1">Atur Periode Pertama</h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">
                                    Tentukan tanggal mulai dan durasi untuk {suppliers.find(supplier => supplier.id === activeSupplierId)?.name || 'supplier ini'}. Periode berikutnya akan dibuat otomatis.
                                </p>
                                <form onSubmit={handleSaveConfig} className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Tgl &amp; Jam Mulai Periode Pertama</label>
                                        <input
                                            type="datetime-local"
                                            required
                                            value={configForm.first_period_start}
                                            onChange={e => setConfigForm({ ...configForm, first_period_start: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                        />
                                        <p className="text-[10px] text-slate-400 mt-1">Data historis sebelum tanggal ini tidak akan masuk ke rekap.</p>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Durasi Periode (Hari)</label>
                                        <div className="flex gap-2 mb-2">
                                            {[7, 14, 30].map(d => (
                                                <button key={d} type="button" onClick={() => setConfigForm(c => ({ ...c, length_days: d }))}
                                                    className={`flex-1 py-1.5 rounded-lg text-xs font-bold border transition-colors ${
                                                        parseInt(configForm.length_days) === d
                                                            ? 'bg-indigo-500 text-white border-indigo-500'
                                                            : 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:border-indigo-400'
                                                    }`}>{d} hari</button>
                                            ))}
                                        </div>
                                        <input
                                            type="number"
                                            min="1"
                                            required
                                            value={configForm.length_days}
                                            onChange={e => setConfigForm({ ...configForm, length_days: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                            placeholder="atau masukkan jumlah hari..."
                                        />
                                    </div>
                                    <div className="flex gap-3 pt-4">
                                        <button type="button" onClick={() => setIsConfigModalOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                        <button type="submit" className="flex-1 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-indigo-500/30">Mulai &amp; Sinkronkan</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}

                    {/* Settings Modal: change duration only */}
                    {isSettingsModalOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => { setIsSettingsModalOpen(false); setSettingsDurationConfirm(false); }}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-md rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden">
                                {!settingsDurationConfirm ? (
                                    <>
                                        <div className="flex items-center gap-3 mb-4">
                                            <div className="w-10 h-10 rounded-2xl bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center">
                                                <Settings className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                                            </div>
                                            <div>
                                                <h2 className="text-lg font-bold text-slate-800 dark:text-white">Pengaturan Periode</h2>
                                                <p className="text-xs text-slate-500">Ubah durasi periode berjalan</p>
                                            </div>
                                        </div>
                                        <div className="space-y-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-2">Durasi Periode Baru (Hari)</label>
                                                <div className="flex gap-2 mb-2">
                                                    {[7, 14, 30].map(d => (
                                                        <button key={d} type="button" onClick={() => setSettingsForm(f => ({ ...f, length_days: d }))}
                                                            className={`flex-1 py-1.5 rounded-lg text-xs font-bold border transition-colors ${
                                                                parseInt(settingsForm.length_days) === d
                                                                    ? 'bg-indigo-500 text-white border-indigo-500'
                                                                    : 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:border-indigo-400'
                                                            }`}>{d} hari</button>
                                                    ))}
                                                </div>
                                                <input
                                                    type="number" min="1"
                                                    value={settingsForm.length_days}
                                                    onChange={e => setSettingsForm(f => ({ ...f, length_days: parseInt(e.target.value) || 1 }))}
                                                    className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-2">Kapan Diterapkan?</label>
                                                <div className="space-y-2">
                                                    <label className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors ${
                                                        settingsForm.apply_mode === 'future' ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/20' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300'
                                                    }`}>
                                                        <input type="radio" name="apply_mode" value="future" checked={settingsForm.apply_mode === 'future'} onChange={() => setSettingsForm(f => ({ ...f, apply_mode: 'future' }))} className="mt-0.5" />
                                                        <div>
                                                            <p className="text-sm font-semibold text-slate-800 dark:text-white">Setelah periode ini selesai</p>
                                                            <p className="text-xs text-slate-500">Hanya periode mendatang yang terpengaruh. Data saat ini aman.</p>
                                                        </div>
                                                    </label>
                                                    <label className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors ${
                                                        settingsForm.apply_mode === 'all' ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-slate-200 dark:border-slate-700 hover:border-slate-300'
                                                    }`}>
                                                        <input type="radio" name="apply_mode" value="all" checked={settingsForm.apply_mode === 'all'} onChange={() => setSettingsForm(f => ({ ...f, apply_mode: 'all' }))} className="mt-0.5" />
                                                        <div>
                                                            <p className="text-sm font-semibold text-slate-800 dark:text-white">Terapkan ke semua periode</p>
                                                            <p className="text-xs text-slate-500 text-amber-700 dark:text-amber-400">⚠ Semua periode lama akan dihapus &amp; disinkronisasi ulang.</p>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex gap-3 pt-5">
                                            <button type="button" onClick={() => { setIsSettingsModalOpen(false); setSettingsDurationConfirm(false); }} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                            <button type="button" onClick={() => setSettingsDurationConfirm(true)} className="flex-1 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-indigo-500/30">Lanjutkan</button>
                                        </div>
                                    </>
                                ) : (
                                    // Sub-confirm
                                    <div className="text-center">
                                        <div className="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center mx-auto mb-4">
                                            <AlertTriangle className="w-7 h-7 text-amber-500" />
                                        </div>
                                        <h3 className="text-lg font-bold text-slate-800 dark:text-white mb-2">Konfirmasi Perubahan Durasi</h3>
                                        <p className="text-sm text-slate-600 dark:text-slate-300 mb-1">
                                            Durasi periode akan diubah menjadi <strong>{settingsForm.length_days} hari</strong>.
                                        </p>
                                        <p className="text-sm text-slate-500 dark:text-slate-400 mb-5">
                                            {settingsForm.apply_mode === 'future'
                                                ? 'Perubahan akan diterapkan setelah periode yang sedang berjalan selesai.'
                                                : '⚠ Perubahan ini akan menghapus semua periode otomatis dan menyinkronisasi ulang semua data. Proses ini tidak dapat dibatalkan.'}
                                        </p>
                                        <div className="flex gap-3">
                                            <button type="button" onClick={() => setSettingsDurationConfirm(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Kembali</button>
                                            <button type="button" onClick={handleSaveDuration} disabled={isSettingsSaving} className="flex-1 py-2.5 bg-indigo-500 hover:bg-indigo-600 disabled:opacity-60 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-indigo-500/30">
                                                {isSettingsSaving ? 'Menyimpan...' : 'Ya, Terapkan'}
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </motion.div>
                        </div>
                    )}

                    {/* Manual Period Modal (hidden access via id="btn-create-manual-period") */}
                    {isManualPeriodOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setIsManualPeriodOpen(false)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-md rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden">
                                <h2 className="text-lg font-bold text-slate-800 dark:text-white mb-1">Buat Periode Manual</h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mb-4">Periode ini tidak akan terpengaruh oleh sistem otomatis.</p>
                                <form onSubmit={handleCreateManualPeriod} className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Nama Periode</label>
                                        <input type="text" required value={manualPeriodForm.name}
                                            onChange={e => setManualPeriodForm(f => ({ ...f, name: e.target.value }))}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                            placeholder="Periode Khusus" />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Tanggal Mulai</label>
                                            <input type="datetime-local" required value={manualPeriodForm.start_date}
                                                onChange={e => setManualPeriodForm(f => ({ ...f, start_date: e.target.value }))}
                                                className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50" />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Tanggal Selesai</label>
                                            <input type="datetime-local" required value={manualPeriodForm.end_date}
                                                onChange={e => setManualPeriodForm(f => ({ ...f, end_date: e.target.value }))}
                                                className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50" />
                                        </div>
                                    </div>
                                    <div className="flex gap-3 pt-2">
                                        <button type="button" onClick={() => setIsManualPeriodOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                        <button type="submit" className="flex-1 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-indigo-500/30">Buat Periode</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}

                    {/* Event Reassignment Modal */}
                    {isReassignModalOpen && !reassignConfirmOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={() => setIsReassignModalOpen(false)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-2xl rounded-3xl shadow-2xl ring-1 ring-white/10 overflow-hidden flex flex-col max-h-[90vh]">
                                {/* Header */}
                                <div className="flex items-center justify-between p-5 border-b border-slate-100 dark:border-slate-700/50">
                                    <div className="flex items-center gap-3">
                                        <div className="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center">
                                            <ArrowLeftRight className="w-4 h-4 text-amber-600 dark:text-amber-400" />
                                        </div>
                                        <div>
                                            <h2 className="text-base font-bold text-slate-800 dark:text-white">Pindahkan Event ke Periode Lain</h2>
                                            <p className="text-xs text-slate-500">Cari event, pilih, lalu tentukan periode tujuan</p>
                                        </div>
                                    </div>
                                    <button type="button" onClick={() => setIsReassignModalOpen(false)} className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
                                        <X className="w-4 h-4 text-slate-500" />
                                    </button>
                                </div>

                                {/* Search */}
                                <div className="p-4 border-b border-slate-100 dark:border-slate-700/50">
                                    <div className="relative">
                                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
                                        <input
                                            type="text"
                                            placeholder="Cari order ID, tipe event, platform..."
                                            value={reassignSearch}
                                            onChange={e => handleReassignSearch(e.target.value)}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-amber-500/40"
                                            autoFocus
                                        />
                                        {reassignLoading && <div className="absolute right-3 top-1/2 -translate-y-1/2 animate-spin w-4 h-4 border-2 border-amber-400 border-t-transparent rounded-full" />}
                                    </div>
                                </div>

                                {/* Results */}
                                <div className="flex-1 overflow-y-auto">
                                    {reassignResults.length === 0 && reassignSearch && !reassignLoading && (
                                        <div className="p-8 text-center text-slate-400 text-sm">Tidak ada event ditemukan untuk &ldquo;{reassignSearch}&rdquo;</div>
                                    )}
                                    {reassignResults.length === 0 && !reassignSearch && (
                                        <div className="p-8 text-center text-slate-400 text-sm">Ketik Order ID atau platform untuk mencari event</div>
                                    )}
                                    {reassignResults.length > 0 && (
                                        <table className="w-full text-sm">
                                            <thead className="bg-slate-50 dark:bg-slate-800/70 sticky top-0 text-xs text-slate-500 dark:text-slate-400">
                                                <tr>
                                                    <th className="px-4 py-2.5 w-10">
                                                        <input type="checkbox"
                                                            checked={selectedEventIds.length === reassignResults.length && reassignResults.length > 0}
                                                            onChange={e => setSelectedEventIds(e.target.checked ? reassignResults.map(r => r.id) : [])}
                                                            className="rounded"
                                                        />
                                                    </th>
                                                    <th className="px-4 py-2.5 text-left font-semibold">Order ID</th>
                                                    <th className="px-4 py-2.5 text-left font-semibold">Tipe</th>
                                                    <th className="px-4 py-2.5 text-left font-semibold">Periode Saat Ini</th>
                                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-slate-700/50">
                                                {reassignResults.map(ev => (
                                                    <tr key={ev.id} onClick={() => toggleEventSelection(ev.id)}
                                                        className={`cursor-pointer transition-colors ${
                                                            selectedEventIds.includes(ev.id)
                                                                ? 'bg-amber-50 dark:bg-amber-900/20'
                                                                : 'hover:bg-slate-50 dark:hover:bg-slate-700/30'
                                                        }`}>
                                                        <td className="px-4 py-3">
                                                            <input type="checkbox" checked={selectedEventIds.includes(ev.id)} onChange={() => toggleEventSelection(ev.id)} onClick={e => e.stopPropagation()} className="rounded" />
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="font-mono text-xs font-semibold text-[#304674] dark:text-blue-400">{ev.source_id || '-'}</span>
                                                            {ev.platform && <span className={`ml-1.5 text-[10px] font-semibold px-1.5 py-0.5 rounded ${
                                                                ev.platform.toLowerCase().includes('shopee') ? 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-400' :
                                                                ev.platform.toLowerCase().includes('tiktok') ? 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-400' :
                                                                'bg-slate-100 text-slate-500 dark:bg-slate-700'
                                                            }`}>{ev.platform}</span>}
                                                            {ev.is_manual_moved && <span className="ml-1 text-[10px] text-amber-500 font-bold">• Dipindah</span>}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-md ${
                                                                ev.source_type === 'CREATE_ORDER' ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-400' : 'bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400'
                                                            }`}>{getReturnTypeLabel(ev.source_type)}</span>
                                                        </td>
                                                        <td className="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                                            {ev.period ? ev.period.name : <span className="text-slate-400 italic">Tanpa periode</span>}
                                                        </td>
                                                        <td className={`px-4 py-3 text-xs font-bold text-right ${
                                                            parseFloat(ev.amount) < 0 ? 'text-rose-500' : 'text-slate-800 dark:text-white'
                                                        }`}>
                                                            {parseFloat(ev.amount) < 0 ? '-' : '+'}{formatCurrency(Math.abs(parseFloat(ev.amount)))}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    )}
                                </div>

                                {/* Footer: target period + confirm */}
                                {selectedEventIds.length > 0 && (
                                    <div className="p-4 border-t border-slate-100 dark:border-slate-700/50 bg-slate-50/80 dark:bg-slate-800/50">
                                        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                                            <div className="flex-1">
                                                <p className="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1.5">{selectedEventIds.length} event dipilih &mdash; Pindahkan ke periode:</p>
                                                <select
                                                    value={reassignTargetPeriodId}
                                                    onChange={e => setReassignTargetPeriodId(e.target.value)}
                                                    className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-500/40"
                                                >
                                                    <option value="">-- Pilih Periode Tujuan --</option>
                                                    {periods.map(p => (
                                                        <option key={p.id} value={p.id}>{p.name}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => { if (reassignTargetPeriodId) setReassignConfirmOpen(true); else toast.error('Pilih periode tujuan terlebih dahulu'); }}
                                                className="flex items-center gap-2 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm shadow-amber-500/30 flex-shrink-0"
                                            >
                                                <ArrowLeftRight className="w-4 h-4" />
                                                Pindahkan
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </motion.div>
                        </div>
                    )}

                    {/* Reassign Confirm Sub-Modal */}
                    {reassignConfirmOpen && (
                        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-sm rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 text-center">
                                <div className="w-14 h-14 bg-amber-100 dark:bg-amber-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <ArrowLeftRight className="w-7 h-7 text-amber-500" />
                                </div>
                                <h3 className="text-lg font-bold text-slate-800 dark:text-white mb-2">Pastikan Pemindahan</h3>
                                <p className="text-sm text-slate-500 dark:text-slate-400 mb-1">
                                    <strong>{selectedEventIds.length} event</strong> akan dipindahkan ke:
                                </p>
                                <p className="text-sm font-semibold text-indigo-600 dark:text-indigo-400 mb-5">
                                    {periods.find(p => p.id == reassignTargetPeriodId)?.name || 'Periode tujuan'}
                                </p>
                                <p className="text-xs text-slate-400 mb-5">Event dengan kombinasi Order ID + Tipe yang sudah ada di periode tujuan akan dilewati secara otomatis.</p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setReassignConfirmOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Kembali</button>
                                    <button type="button" onClick={handleConfirmReassign} disabled={isReassigning} className="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-60 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-amber-500/30">
                                        {isReassigning ? 'Memindahkan...' : 'Ya, Pindahkan'}
                                    </button>
                                </div>
                            </motion.div>
                        </div>
                    )}

                    {isPaymentModalOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setIsPaymentModalOpen(false)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-md rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden">
                                <h2 className="text-lg font-bold text-slate-800 dark:text-white mb-4">{editingPaymentId ? 'Edit Hutang Supplier' : 'Tambah Hutang Supplier'}</h2>
                                <form onSubmit={handleSavePayment} className="space-y-4">
                                    {suppliers.length > 1 && (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                                                Supplier <span className="text-rose-500">*</span>
                                            </label>
                                            <select
                                                value={paymentForm.supplier_id || ''}
                                                onChange={e => setPaymentForm({ ...paymentForm, supplier_id: e.target.value })}
                                                required
                                                className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                            >
                                                <option value="">-- Pilih Supplier --</option>
                                                {suppliers.map(s => (
                                                    <option key={s.id} value={s.id}>{s.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
                                    {suppliers.length === 1 && (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                                                Supplier
                                            </label>
                                            <div className="w-full bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2 text-sm text-slate-700 dark:text-slate-300 flex items-center gap-2">
                                                <Building2 className="w-4 h-4 text-indigo-500" />
                                                <span className="font-semibold text-xs">{suppliers[0].name}</span>
                                            </div>
                                        </div>
                                    )}
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Tanggal</label>
                                        <input
                                            type="date"
                                            required
                                            value={paymentForm.payment_date}
                                            onChange={e => setPaymentForm({ ...paymentForm, payment_date: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Nominal (Rp)</label>
                                        <input
                                            type="number"
                                            min="0"
                                            required
                                            value={paymentForm.amount}
                                            onChange={e => setPaymentForm({ ...paymentForm, amount: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                            placeholder="Contoh: 1500000"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Bentuk Hutang / Keterangan</label>
                                        <input
                                            type="text"
                                            required
                                            value={paymentForm.payment_method}
                                            onChange={e => setPaymentForm({ ...paymentForm, payment_method: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50"
                                            placeholder="Contoh: Pinjaman Uang, dsb."
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Catatan</label>
                                        <textarea
                                            value={paymentForm.notes}
                                            onChange={e => setPaymentForm({ ...paymentForm, notes: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/50 resize-none h-20"
                                            placeholder="Opsional..."
                                        ></textarea>
                                    </div>
                                    <div className="flex gap-3 pt-4">
                                        <button type="button" onClick={() => setIsPaymentModalOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                        <button type="submit" className="flex-1 py-2.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-indigo-500/30">Simpan</button>
                                    </div>
                                </form>
                            </motion.div>
                        </div>
                    )}

                    {isDeleteModalOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setIsDeleteModalOpen(false)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-md rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden text-center">
                                <div className="w-16 h-16 bg-rose-100 dark:bg-rose-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <Trash2 className="w-8 h-8 text-rose-500" />
                                </div>
                                <h2 className="text-xl font-bold text-slate-800 dark:text-white mb-2">Hapus Periode?</h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400 mb-6">
                                    Apakah Anda yakin ingin menghapus periode ini? Semua data transaksi dan catatan hutang supplier yang ada di dalamnya akan ikut terhapus. Tindakan ini tidak dapat dibatalkan.
                                </p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setIsDeleteModalOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                    <button onClick={handleDeletePeriod} className="flex-1 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-rose-500/30">Ya, Hapus</button>
                                </div>
                            </motion.div>
                        </div>
                    )}

                    {paymentToDelete && (
                        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setPaymentToDelete(null)}></motion.div>
                            <motion.div initial={{ opacity: 0, scale: 0.95, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.95, y: 20 }} className="relative bg-white dark:bg-slate-800 w-full max-w-sm rounded-3xl p-6 shadow-2xl ring-1 ring-white/10 overflow-hidden text-center">
                                <div className="w-16 h-16 bg-rose-100 dark:bg-rose-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <Trash2 className="w-8 h-8 text-rose-500" />
                                </div>
                                <h2 className="text-xl font-bold text-slate-800 dark:text-white mb-2">Hapus Hutang Supplier?</h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400 mb-6">
                                    Apakah Anda yakin ingin menghapus data hutang sebesar <strong className="text-slate-700 dark:text-slate-300">{formatCurrency(paymentToDelete.amount)}</strong>? Tindakan ini tidak dapat dibatalkan.
                                </p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setPaymentToDelete(null)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                    <button onClick={handleDeletePayment} disabled={isDeletingPayment} className="flex-1 py-2.5 bg-rose-500 hover:bg-rose-600 disabled:opacity-60 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg shadow-rose-500/30">
                                        {isDeletingPayment ? 'Menghapus...' : 'Ya, Hapus'}
                                    </button>
                                </div>
                            </motion.div>
                        </div>
                    )}

                    {/* Supplier Onboarding Modal */}
                    <SupplierOnboardingModal
                        isOpen={isSupplierOnboardingOpen}
                        onClose={() => setIsSupplierOnboardingOpen(false)}
                        onSuccess={async () => {
                            await fetchSuppliers();
                            await fetchPeriods();
                            if (selectedPeriodId) {
                                await fetchPeriodDetails(selectedPeriodId, true);
                            }
                        }}
                    />

                    {/* Manage Suppliers & Product Allocation Modal */}
                    <ManageSuppliersModal
                        isOpen={isManageSupplierOpen}
                        onClose={() => setIsManageSupplierOpen(false)}
                        suppliers={suppliers}
                        onUpdated={async () => {
                            await fetchSuppliers();
                            await fetchPeriods(true, selectedSupplierIdsRef.current);
                            const currentPeriodId = selectedPeriodIdRef.current || selectedPeriodId;
                            if (currentPeriodId) {
                                await fetchPeriodDetails(currentPeriodId, true, selectedSupplierIdsRef.current);
                            }
                        }}
                    />

                    {/* Payment Status Modal */}
                    {isPaymentStatusModalOpen && (
                        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
                            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={() => setIsPaymentStatusModalOpen(false)} className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" />
                            <motion.div initial={{ scale: 0.95, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.95, opacity: 0 }} className="bg-white dark:bg-slate-800 rounded-2xl p-6 w-full max-w-md shadow-2xl relative z-10 border border-slate-200 dark:border-slate-700">
                                <h3 className="text-xl font-bold text-slate-800 dark:text-white mb-2">
                                    {targetPaymentStatus === 'PAID' ? 'Tandai Periode Lunas?' : 'Batal Tandai Lunas?'}
                                </h3>
                                <p className="text-slate-500 dark:text-slate-400 text-sm mb-6">
                                    {targetPaymentStatus === 'PAID' 
                                        ? 'Apakah Anda yakin ingin menandai periode ini sebagai Lunas? Semua tagihan di dalamnya dianggap telah selesai dibayar.' 
                                        : 'Apakah Anda yakin ingin mengembalikan status periode ini menjadi Belum Lunas?'}
                                </p>
                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setIsPaymentStatusModalOpen(false)} className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 rounded-xl text-sm font-semibold transition-colors">Batal</button>
                                    <button 
                                        onClick={handleUpdatePaymentStatus} 
                                        disabled={isUpdatingPaymentStatus} 
                                        className={`flex-1 py-2.5 disabled:opacity-60 text-white rounded-xl text-sm font-semibold transition-colors shadow-lg ${targetPaymentStatus === 'PAID' ? 'bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/30' : 'bg-rose-500 hover:bg-rose-600 shadow-rose-500/30'}`}
                                    >
                                        {isUpdatingPaymentStatus ? 'Menyimpan...' : 'Ya, Lanjutkan'}
                                    </button>
                                </div>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>

            </div>
            <OnboardingTour
                steps={PAYABLE_TOUR_STEPS}
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
