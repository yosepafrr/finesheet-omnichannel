import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import axios from 'axios';
import { toast } from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Building2,
    Plus,
    Trash2,
    Edit2,
    Search,
    X,
    Check,
    Store as StoreIcon,
    Package,
    ArrowRight,
    ChevronDown,
    AlertTriangle
} from 'lucide-react';

export default function ManageSuppliersModal({ isOpen, onClose, suppliers = [], onUpdated }) {
    const [localSuppliers, setLocalSuppliers] = useState(suppliers);
    const [isDeletingId, setIsDeletingId] = useState(null);
    const [supplierToDelete, setSupplierToDelete] = useState(null);
    const [activeTab, setActiveTab] = useState('LIST'); // 'LIST' or 'PRODUCTS'

    // Custom dropdown states for Alokasi Produk tab
    const [isStoreDropdownOpen, setIsStoreDropdownOpen] = useState(false);
    const [isSupplierFilterDropdownOpen, setIsSupplierFilterDropdownOpen] = useState(false);
    const [isTargetSupplierDropdownOpen, setIsTargetSupplierDropdownOpen] = useState(false);

    const storeDropdownRef = useRef(null);
    const supplierFilterDropdownRef = useRef(null);
    const targetSupplierDropdownRef = useRef(null);

    useEffect(() => {
        setLocalSuppliers(suppliers);
    }, [suppliers]);
    
    // Supplier add / edit state
    const [isEditingSupplier, setIsEditingSupplier] = useState(false);
    const [editingSupplierId, setEditingSupplierId] = useState(null);
    const [supplierForm, setSupplierForm] = useState({
        name: '',
        contact_person: '',
        phone: '',
        address: '',
        notes: '',
        first_period_start: '',
        period_length_days: 14
    });
    const [isSavingSupplier, setIsSavingSupplier] = useState(false);

    // Products mapping tab state
    const [products, setProducts] = useState([]);
    const [isLoadingProducts, setIsLoadingProducts] = useState(false);
    const [productSearch, setProductSearch] = useState('');
    const [selectedStoreFilter, setSelectedStoreFilter] = useState('ALL');
    const [selectedSupplierFilter, setSelectedSupplierFilter] = useState('ALL');
    const [selectedProductIds, setSelectedProductIds] = useState([]);
    const [targetSupplierId, setTargetSupplierId] = useState('');
    const [isAssigning, setIsAssigning] = useState(false);
    const [isFilteringProducts, setIsFilteringProducts] = useState(false);
    const productFilterTimeoutRef = useRef(null);

    useEffect(() => {
        function handleClickOutside(event) {
            if (storeDropdownRef.current && !storeDropdownRef.current.contains(event.target)) {
                setIsStoreDropdownOpen(false);
            }
            if (supplierFilterDropdownRef.current && !supplierFilterDropdownRef.current.contains(event.target)) {
                setIsSupplierFilterDropdownOpen(false);
            }
            if (targetSupplierDropdownRef.current && !targetSupplierDropdownRef.current.contains(event.target)) {
                setIsTargetSupplierDropdownOpen(false);
            }
        }
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    useEffect(() => {
        if (isOpen) {
            setActiveTab('LIST');
            setIsEditingSupplier(false);
            setEditingSupplierId(null);
            setSupplierForm({ name: '', contact_person: '', phone: '', address: '', notes: '', first_period_start: '', period_length_days: 14 });
            setSelectedProductIds([]);
            loadProducts();
        }
    }, [isOpen]);

    useEffect(() => {
        if (!isOpen || activeTab !== 'PRODUCTS' || isLoadingProducts) return;
        setIsFilteringProducts(true);
        if (productFilterTimeoutRef.current) {
            clearTimeout(productFilterTimeoutRef.current);
        }
        productFilterTimeoutRef.current = setTimeout(() => {
            setIsFilteringProducts(false);
        }, 220);

        return () => {
            if (productFilterTimeoutRef.current) {
                clearTimeout(productFilterTimeoutRef.current);
            }
        };
    }, [activeTab, isOpen, isLoadingProducts, productSearch, selectedStoreFilter, selectedSupplierFilter]);

    const loadProducts = async () => {
        setIsLoadingProducts(true);
        try {
            const res = await axios.get('/api/payable/suppliers/products');
            setProducts(res.data.data || []);
        } catch (err) {
            console.error("Failed to load products", err);
        } finally {
            setIsLoadingProducts(false);
        }
    };

    const handleSaveSupplier = async (e) => {
        e.preventDefault();
        if (!supplierForm.name.trim()) {
            toast.error("Nama supplier tidak boleh kosong");
            return;
        }

        setIsSavingSupplier(true);
        try {
            if (editingSupplierId) {
                const res = await axios.put(`/api/payable/suppliers/${editingSupplierId}`, supplierForm);
                toast.success("Supplier berhasil diperbarui");
                if (res.data?.data) {
                    setLocalSuppliers(prev => prev.map(s => s.id === editingSupplierId ? res.data.data : s));
                }
            } else {
                const res = await axios.post('/api/payable/suppliers', supplierForm);
                toast.success("Supplier baru berhasil ditambahkan");
                if (res.data?.data) {
                    setLocalSuppliers(prev => [...prev, res.data.data]);
                }
            }
            setIsEditingSupplier(false);
            setEditingSupplierId(null);
            setSupplierForm({ name: '', contact_person: '', phone: '', address: '', notes: '', first_period_start: '', period_length_days: 14 });
            if (onUpdated) await onUpdated();
            loadProducts();
        } catch (err) {
            toast.error(err.response?.data?.message || "Gagal menyimpan supplier");
        } finally {
            setIsSavingSupplier(false);
        }
    };

    const handleStartEdit = (s) => {
        setEditingSupplierId(s.id);
        setSupplierForm({
            name: s.name || '',
            contact_person: s.contact_person || '',
            phone: s.phone || '',
            address: s.address || '',
            notes: s.notes || '',
            first_period_start: s.first_period_start ? s.first_period_start.slice(0, 10) : '',
            period_length_days: s.period_length_days || 14
        });
        setIsEditingSupplier(true);
    };

    const handleConfirmDelete = async () => {
        if (!supplierToDelete) return;
        const { id, name } = supplierToDelete;

        setIsDeletingId(id);
        try {
            const res = await axios.delete(`/api/payable/suppliers/${id}`);
            toast.success(res.data?.message || `Supplier '${name}' berhasil dihapus`);
            // Optimistically remove from local list immediately
            setLocalSuppliers(prev => prev.filter(s => s.id !== id));
            setSupplierToDelete(null);
            if (onUpdated) await onUpdated();
            loadProducts();
        } catch (err) {
            console.error("Delete supplier error:", err);
            toast.error(err.response?.data?.message || "Gagal menghapus supplier");
        } finally {
            setIsDeletingId(null);
        }
    };

    const handleToggleSelectProduct = (id) => {
        setSelectedProductIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const handleSelectAllFiltered = (filteredList) => {
        const ids = filteredList.map(p => p.id);
        const allSelected = ids.every(id => selectedProductIds.includes(id));
        if (allSelected) {
            setSelectedProductIds(prev => prev.filter(id => !ids.includes(id)));
        } else {
            setSelectedProductIds(prev => Array.from(new Set([...prev, ...ids])));
        }
    };

    const handleAssignProducts = async () => {
        if (!targetSupplierId || selectedProductIds.length === 0) {
            toast.error("Pilih supplier target dan minimal 1 produk");
            return;
        }

        setIsAssigning(true);
        try {
            const res = await axios.post('/api/payable/suppliers/assign-products', {
                product_ids: selectedProductIds,
                supplier_id: parseInt(targetSupplierId)
            });
            toast.success(res.data.message || "Produk berhasil dialihkan ke supplier");
            setSelectedProductIds([]);
            setTargetSupplierId('');
            loadProducts();
            if (onUpdated) onUpdated();
        } catch (err) {
            toast.error("Gagal mengubah supplier produk");
        } finally {
            setIsAssigning(false);
        }
    };

    // Filter products
    const availableStores = Array.from(new Set(products.map(p => p.store_name).filter(Boolean)));
    const filteredProducts = products.filter(p => {
        if (selectedStoreFilter !== 'ALL' && p.store_name !== selectedStoreFilter) return false;
        if (selectedSupplierFilter !== 'ALL') {
            if (selectedSupplierFilter === 'UNASSIGNED' && p.supplier_id) return false;
            if (selectedSupplierFilter !== 'UNASSIGNED' && String(p.supplier_id) !== selectedSupplierFilter) return false;
        }
        if (!productSearch.trim()) return true;
        const q = productSearch.toLowerCase();
        return (
            (p.product_name && p.product_name.toLowerCase().includes(q)) ||
            (p.product_sku && p.product_sku.toLowerCase().includes(q)) ||
            (p.platform_product_id && String(p.platform_product_id).includes(q))
        );
    });

    const showProductsSkeleton = isLoadingProducts || isFilteringProducts;

    const dropdownButtonClass = "flex items-center justify-between gap-2 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2 pl-3 pr-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 shadow-sm text-xs transition-all hover:border-gray-300 dark:hover:border-slate-600 cursor-pointer";
    const dropdownPanelClass = "absolute right-0 mt-2 w-full min-w-[240px] bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden z-[160] origin-top";
    const dropdownOptionClass = (active) => `w-full flex items-center justify-between text-left px-3 py-2 text-xs font-medium rounded-xl transition-colors cursor-pointer ${
        active
            ? "bg-[#304674] text-white dark:bg-blue-600 font-semibold"
            : "text-gray-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800"
    }`;
    const checkBoxClass = (active) => `w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 ${
        active
            ? "bg-indigo-500 border-indigo-500 text-white"
            : "border-slate-300 dark:border-slate-600"
    }`;
    const renderProductSkeletonRows = () => (
        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 animate-pulse">
            {[...Array(7)].map((_, index) => (
                <tr key={index}>
                    <td className="p-3 text-center">
                        <div className="w-4 h-4 mx-auto rounded bg-slate-200 dark:bg-slate-700" />
                    </td>
                    <td className="p-3">
                        <div className="flex items-center gap-2.5 max-w-[280px]">
                            <div className="w-9 h-9 rounded-lg bg-slate-200 dark:bg-slate-700 flex-shrink-0" />
                            <div className="min-w-0 flex-1 space-y-2">
                                <div className="h-3 w-36 bg-slate-200 dark:bg-slate-700 rounded-full" />
                                <div className="h-2.5 w-20 bg-slate-100 dark:bg-slate-700/60 rounded-full" />
                            </div>
                        </div>
                    </td>
                    <td className="p-3">
                        <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded-full" />
                    </td>
                    <td className="p-3">
                        <div className="h-6 w-28 bg-slate-200 dark:bg-slate-700 rounded-full" />
                    </td>
                </tr>
            ))}
        </tbody>
    );

    if (!isOpen) return null;

    return createPortal((
        <div className="fixed inset-0 z-[130] flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
            {/* Backdrop */}
            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 bg-slate-900/60 backdrop-blur-md"
                onClick={onClose}
            />

            {/* Modal Dialog */}
            <motion.div
                initial={{ opacity: 0, scale: 0.95, y: 15 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: 15 }}
                className="relative bg-white dark:bg-slate-900 w-full max-w-3xl rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[90vh] z-10"
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex-shrink-0 bg-slate-50/50 dark:bg-slate-800/30">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <Building2 className="w-5 h-5" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-slate-800 dark:text-white">
                                Kelola Data Supplier
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Atur daftar supplier dan alokasi produk toko kamu
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Tabs */}
                <div className="flex border-b border-slate-100 dark:border-slate-800 px-6 bg-slate-50/30 dark:bg-slate-800/10">
                    <button
                        type="button"
                        onClick={() => setActiveTab('LIST')}
                        className={`pb-3 pt-3 text-xs font-bold border-b-2 transition-colors cursor-pointer mr-6 ${
                            activeTab === 'LIST'
                                ? 'border-[#304674] dark:border-blue-500 text-[#304674] dark:text-blue-400'
                                : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400'
                        }`}
                    >
                        Daftar Supplier ({localSuppliers.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => { setActiveTab('PRODUCTS'); loadProducts(); }}
                        className={`pb-3 pt-3 text-xs font-bold border-b-2 transition-colors cursor-pointer ${
                            activeTab === 'PRODUCTS'
                                ? 'border-[#304674] dark:border-blue-500 text-[#304674] dark:text-blue-400'
                                : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400'
                        }`}
                    >
                        Alokasi Produk ({products.length})
                    </button>
                </div>

                {/* Content */}
                <div className="p-6 overflow-y-auto flex-1 custom-scrollbar">
                    {/* TAB 1: LIST */}
                    {activeTab === 'LIST' && (
                        <div className="space-y-6">
                            {/* Form Add / Edit */}
                            {isEditingSupplier ? (
                                <motion.form
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: 'auto' }}
                                    onSubmit={handleSaveSupplier}
                                    className="p-5 rounded-2xl bg-indigo-50/40 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 space-y-4"
                                >
                                    <div className="flex justify-between items-center">
                                        <h3 className="text-xs font-bold text-indigo-900 dark:text-indigo-200">
                                            {editingSupplierId ? "Edit Data Supplier" : "Tambah Supplier Baru"}
                                        </h3>
                                        <button
                                            type="button"
                                            onClick={() => setIsEditingSupplier(false)}
                                            className="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                                        >
                                            Batal
                                        </button>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nama Supplier *</label>
                                            <input
                                                type="text"
                                                required
                                                placeholder="Contoh: Supplier Sepatu"
                                                value={supplierForm.name}
                                                onChange={e => setSupplierForm({ ...supplierForm, name: e.target.value })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">PIC / Kontak</label>
                                            <input
                                                type="text"
                                                placeholder="Nama kontak..."
                                                value={supplierForm.contact_person}
                                                onChange={e => setSupplierForm({ ...supplierForm, contact_person: e.target.value })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Nomor Telepon</label>
                                            <input
                                                type="text"
                                                placeholder="0812..."
                                                value={supplierForm.phone}
                                                onChange={e => setSupplierForm({ ...supplierForm, phone: e.target.value })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Catatan</label>
                                            <input
                                                type="text"
                                                placeholder="Catatan rekening dll..."
                                                value={supplierForm.notes}
                                                onChange={e => setSupplierForm({ ...supplierForm, notes: e.target.value })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Tanggal Mulai Periode Pertama</label>
                                            <input
                                                type="date"
                                                value={supplierForm.first_period_start}
                                                onChange={e => setSupplierForm({ ...supplierForm, first_period_start: e.target.value })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Durasi Periode (Hari)</label>
                                            <input
                                                type="number"
                                                min="1"
                                                value={supplierForm.period_length_days}
                                                onChange={e => setSupplierForm({ ...supplierForm, period_length_days: parseInt(e.target.value) || 14 })}
                                                className="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/30"
                                            />
                                        </div>
                                    </div>
                                    <div className="flex justify-end gap-2 pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setIsEditingSupplier(false)}
                                            className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 rounded-xl hover:bg-slate-200 transition-colors"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={isSavingSupplier}
                                            className="px-4 py-2 text-xs font-semibold text-white bg-[#304674] dark:bg-blue-600 hover:bg-[#253659] rounded-xl transition-colors shadow-sm"
                                        >
                                            {isSavingSupplier ? "Menyimpan..." : "Simpan Supplier"}
                                        </button>
                                    </div>
                                </motion.form>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setEditingSupplierId(null);
                                        setSupplierForm({ name: '', contact_person: '', phone: '', address: '', notes: '', first_period_start: '', period_length_days: 14 });
                                        setIsEditingSupplier(true);
                                    }}
                                    className="w-full py-3 px-4 border-2 border-dashed border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 rounded-2xl flex items-center justify-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors cursor-pointer"
                                >
                                    <Plus className="w-4 h-4" />
                                    <span>Tambah Supplier Baru</span>
                                </button>
                            )}

                            {/* Supplier List */}
                            <div className="space-y-3">
                                {localSuppliers.map(s => (
                                    <div
                                        key={s.id}
                                        className="p-4 rounded-2xl bg-white dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between shadow-sm hover:border-indigo-300 dark:hover:border-indigo-700 transition-colors"
                                    >
                                        <div className="flex items-center gap-3.5">
                                            <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                                                <Building2 className="w-5 h-5" />
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <h4 className="text-sm font-bold text-slate-800 dark:text-white">
                                                        {s.name}
                                                    </h4>
                                                    <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                                                        {s.products_count || 0} produk
                                                    </span>
                                                </div>
                                                <p className="text-xs text-slate-400 mt-0.5">
                                                    {s.contact_person ? `PIC: ${s.contact_person}` : ''}
                                                    {s.phone ? ` • ${s.phone}` : ''}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => handleStartEdit(s)}
                                                className="p-2 rounded-xl text-slate-400 hover:text-indigo-600 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                                                title="Edit Supplier"
                                            >
                                                <Edit2 className="w-4 h-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setSupplierToDelete(s)}
                                                className="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 dark:hover:text-rose-400 transition-colors cursor-pointer"
                                                title="Hapus Supplier"
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* TAB 2: PRODUCTS ALLOCATION */}
                    {activeTab === 'PRODUCTS' && (
                        <div className="space-y-4">
                            {/* Filter Bar */}
                            <div className="flex flex-col sm:flex-row gap-2.5 items-stretch sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div className="relative flex-1 max-w-xs">
                                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" />
                                    <input
                                        type="text"
                                        placeholder="Cari produk / SKU..."
                                        value={productSearch}
                                        onChange={e => setProductSearch(e.target.value)}
                                        className="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-800 dark:text-white outline-none focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 items-center">
                                    {availableStores.length > 1 && (
                                        <div className="relative" ref={storeDropdownRef}>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setIsStoreDropdownOpen(!isStoreDropdownOpen);
                                                    setIsSupplierFilterDropdownOpen(false);
                                                }}
                                                className={`${dropdownButtonClass} min-w-[150px]`}
                                            >
                                                <div className="flex items-center gap-1.5 truncate">
                                                    <StoreIcon className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                                    <span className="truncate">{selectedStoreFilter === 'ALL' ? 'Semua Toko' : selectedStoreFilter}</span>
                                                </div>
                                                <ChevronDown className={`w-3.5 h-3.5 text-slate-400 transition-transform duration-200 shrink-0 ${isStoreDropdownOpen ? 'rotate-180' : ''}`} />
                                            </button>

                                            <AnimatePresence>
                                                {isStoreDropdownOpen && (
                                                    <motion.div
                                                        initial={{ opacity: 0, y: 6, scale: 0.96 }}
                                                        animate={{ opacity: 1, y: 0, scale: 1 }}
                                                        exit={{ opacity: 0, y: 6, scale: 0.96 }}
                                                        transition={{ duration: 0.15, ease: 'easeOut' }}
                                                        className={dropdownPanelClass}
                                                    >
                                                        <div className="p-2 border-b border-slate-100 dark:border-slate-800">
                                                            <span className="text-xs font-bold text-slate-600 dark:text-slate-300 px-2">Filter Toko</span>
                                                        </div>
                                                        <div className="max-h-56 overflow-y-auto p-2 space-y-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setSelectedStoreFilter('ALL');
                                                                setIsStoreDropdownOpen(false);
                                                            }}
                                                            className={dropdownOptionClass(selectedStoreFilter === 'ALL')}
                                                        >
                                                            <div className="flex items-center gap-2 min-w-0">
                                                                <div className={checkBoxClass(selectedStoreFilter === 'ALL')}>
                                                                    {selectedStoreFilter === 'ALL' && <Check className="w-3 h-3 stroke-[3]" />}
                                                                </div>
                                                                <span>Semua Toko</span>
                                                            </div>
                                                            <span className={`text-[10px] font-semibold ${selectedStoreFilter === 'ALL' ? 'text-white/80' : 'text-slate-400'}`}>{availableStores.length} toko</span>
                                                        </button>
                                                        <div className="h-px bg-slate-100 dark:bg-slate-800 my-1" />
                                                        {availableStores.map(sName => (
                                                            <button
                                                                key={sName}
                                                                type="button"
                                                                onClick={() => {
                                                                    setSelectedStoreFilter(sName);
                                                                    setIsStoreDropdownOpen(false);
                                                                }}
                                                                className={dropdownOptionClass(selectedStoreFilter === sName)}
                                                            >
                                                                <div className="flex items-center gap-2 min-w-0">
                                                                    <div className={checkBoxClass(selectedStoreFilter === sName)}>
                                                                        {selectedStoreFilter === sName && <Check className="w-3 h-3 stroke-[3]" />}
                                                                    </div>
                                                                    <span className="truncate">{sName}</span>
                                                                </div>
                                                            </button>
                                                        ))}
                                                        </div>
                                                    </motion.div>
                                                )}
                                            </AnimatePresence>
                                        </div>
                                    )}

                                    <div className="relative" ref={supplierFilterDropdownRef}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setIsSupplierFilterDropdownOpen(!isSupplierFilterDropdownOpen);
                                                setIsStoreDropdownOpen(false);
                                            }}
                                            className={`${dropdownButtonClass} min-w-[170px]`}
                                        >
                                            <div className="flex items-center gap-1.5 truncate">
                                                <Building2 className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
                                                <span className="truncate">
                                                    {selectedSupplierFilter === 'ALL'
                                                        ? 'Semua Alokasi'
                                                        : selectedSupplierFilter === 'UNASSIGNED'
                                                            ? 'Belum Ditentukan'
                                                            : localSuppliers.find(s => String(s.id) === selectedSupplierFilter)?.name || 'Filter Supplier'}
                                                </span>
                                            </div>
                                            <ChevronDown className={`w-3.5 h-3.5 text-slate-400 transition-transform duration-200 shrink-0 ${isSupplierFilterDropdownOpen ? 'rotate-180' : ''}`} />
                                        </button>

                                        <AnimatePresence>
                                            {isSupplierFilterDropdownOpen && (
                                                <motion.div
                                                    initial={{ opacity: 0, y: 6, scale: 0.96 }}
                                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                                    exit={{ opacity: 0, y: 6, scale: 0.96 }}
                                                    transition={{ duration: 0.15, ease: 'easeOut' }}
                                                    className={dropdownPanelClass}
                                                >
                                                    <div className="p-2 border-b border-slate-100 dark:border-slate-800">
                                                        <span className="text-xs font-bold text-slate-600 dark:text-slate-300 px-2">Filter Alokasi</span>
                                                    </div>
                                                    <div className="max-h-60 overflow-y-auto p-2 space-y-1">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setSelectedSupplierFilter('ALL');
                                                            setIsSupplierFilterDropdownOpen(false);
                                                        }}
                                                        className={dropdownOptionClass(selectedSupplierFilter === 'ALL')}
                                                    >
                                                        <div className="flex items-center gap-2 min-w-0">
                                                            <div className={checkBoxClass(selectedSupplierFilter === 'ALL')}>
                                                                {selectedSupplierFilter === 'ALL' && <Check className="w-3 h-3 stroke-[3]" />}
                                                            </div>
                                                            <span>Semua Alokasi</span>
                                                        </div>
                                                        <span className={`text-[10px] font-semibold ${selectedSupplierFilter === 'ALL' ? 'text-white/80' : 'text-slate-400'}`}>{products.length} SKU</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setSelectedSupplierFilter('UNASSIGNED');
                                                            setIsSupplierFilterDropdownOpen(false);
                                                        }}
                                                        className={dropdownOptionClass(selectedSupplierFilter === 'UNASSIGNED')}
                                                    >
                                                        <div className="flex items-center gap-2 min-w-0">
                                                            <div className={checkBoxClass(selectedSupplierFilter === 'UNASSIGNED')}>
                                                                {selectedSupplierFilter === 'UNASSIGNED' && <Check className="w-3 h-3 stroke-[3]" />}
                                                            </div>
                                                            <span>Belum Ditentukan</span>
                                                        </div>
                                                    </button>
                                                    <div className="h-px bg-slate-100 dark:bg-slate-800 my-1" />
                                                    {localSuppliers.map(s => (
                                                        <button
                                                            key={s.id}
                                                            type="button"
                                                            onClick={() => {
                                                                setSelectedSupplierFilter(String(s.id));
                                                                setIsSupplierFilterDropdownOpen(false);
                                                            }}
                                                            className={dropdownOptionClass(selectedSupplierFilter === String(s.id))}
                                                        >
                                                            <div className="flex items-center gap-2 min-w-0">
                                                                <div className={checkBoxClass(selectedSupplierFilter === String(s.id))}>
                                                                    {selectedSupplierFilter === String(s.id) && <Check className="w-3 h-3 stroke-[3]" />}
                                                                </div>
                                                                <span className="truncate">{s.name}</span>
                                                            </div>
                                                            {s.products_count !== undefined && (
                                                                <span className={`text-[10px] font-medium px-1.5 py-0.5 rounded flex-shrink-0 ${
                                                                    selectedSupplierFilter === String(s.id)
                                                                        ? 'bg-white/20 text-white'
                                                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-400'
                                                                }`}>
                                                                    {s.products_count} SKU
                                                                </span>
                                                            )}
                                                        </button>
                                                    ))}
                                                    </div>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </div>
                                </div>
                            </div>

                            {/* Bulk Action Bar */}
                            <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between p-2 rounded-xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 gap-2">
                                <span className="text-xs font-semibold text-indigo-900 dark:text-indigo-200 pl-2">
                                    {selectedProductIds.length} produk terpilih
                                </span>
                                <div className="flex items-center gap-2">
                                    <div className="relative flex-1 sm:flex-initial" ref={targetSupplierDropdownRef}>
                                        <button
                                            type="button"
                                            onClick={() => setIsTargetSupplierDropdownOpen(!isTargetSupplierDropdownOpen)}
                                            className={`${dropdownButtonClass} sm:w-auto min-w-[190px]`}
                                        >
                                            <div className="flex items-center gap-1.5 truncate">
                                                <Building2 className="w-3.5 h-3.5 text-[#304674] dark:text-blue-400 shrink-0" />
                                                <span className="truncate">
                                                    {targetSupplierId
                                                        ? localSuppliers.find(s => String(s.id) === String(targetSupplierId))?.name || 'Pilih Supplier'
                                                        : 'Pilih Supplier Tujuan...'}
                                                </span>
                                            </div>
                                            <ChevronDown className={`w-3.5 h-3.5 text-slate-400 transition-transform duration-200 shrink-0 ${isTargetSupplierDropdownOpen ? 'rotate-180' : ''}`} />
                                        </button>

                                        <AnimatePresence>
                                            {isTargetSupplierDropdownOpen && (
                                                <motion.div
                                                    initial={{ opacity: 0, y: 6, scale: 0.96 }}
                                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                                    exit={{ opacity: 0, y: 6, scale: 0.96 }}
                                                    transition={{ duration: 0.15, ease: 'easeOut' }}
                                                    className="absolute right-0 bottom-full mb-2 sm:bottom-auto sm:top-full sm:mt-2 w-full min-w-[240px] bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-100 dark:border-slate-800 overflow-hidden z-[160] origin-bottom sm:origin-top"
                                                >
                                                    <div className="p-2 border-b border-slate-100 dark:border-slate-800">
                                                        <span className="text-xs font-bold text-slate-600 dark:text-slate-300 px-2">Supplier Tujuan</span>
                                                    </div>
                                                    <div className="max-h-56 overflow-y-auto p-2 space-y-1">
                                                    {localSuppliers.length === 0 ? (
                                                        <div className="p-3 text-center text-xs text-slate-400">Tidak ada supplier</div>
                                                    ) : (
                                                        localSuppliers.map(s => (
                                                            <button
                                                                key={s.id}
                                                                type="button"
                                                                onClick={() => {
                                                                    setTargetSupplierId(String(s.id));
                                                                    setIsTargetSupplierDropdownOpen(false);
                                                                }}
                                                                className={dropdownOptionClass(String(targetSupplierId) === String(s.id))}
                                                            >
                                                                <div className="flex items-center gap-2 min-w-0">
                                                                    <div className={checkBoxClass(String(targetSupplierId) === String(s.id))}>
                                                                        {String(targetSupplierId) === String(s.id) && <Check className="w-3 h-3 stroke-[3]" />}
                                                                    </div>
                                                                    <span className="truncate">{s.name}</span>
                                                                </div>
                                                            </button>
                                                        ))
                                                    )}
                                                    </div>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </div>
                                    <button
                                        type="button"
                                        disabled={selectedProductIds.length === 0 || !targetSupplierId || isAssigning}
                                        onClick={handleAssignProducts}
                                        className="px-3 py-1.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-xl text-xs font-semibold disabled:opacity-50 transition-colors cursor-pointer shrink-0"
                                    >
                                        {isAssigning ? "Menyimpan..." : "Alihkan Supplier"}
                                    </button>
                                </div>
                            </div>

                            {/* Table */}
                            <div className="overflow-x-auto max-h-[380px] border border-slate-100 dark:border-slate-800 rounded-2xl">
                                <table className="w-full text-left text-xs border-collapse">
                                    <thead className="bg-slate-50 dark:bg-slate-800/80 sticky top-0 z-10 text-[11px] font-bold text-slate-500 uppercase">
                                        <tr>
                                            <th className="p-3 w-10 text-center">
                                                <input
                                                    type="checkbox"
                                                    checked={!showProductsSkeleton && filteredProducts.length > 0 && filteredProducts.every(p => selectedProductIds.includes(p.id))}
                                                    onChange={() => handleSelectAllFiltered(filteredProducts)}
                                                    disabled={showProductsSkeleton}
                                                    className="rounded text-indigo-600 cursor-pointer disabled:opacity-50"
                                                />
                                            </th>
                                            <th className="p-3">Produk &amp; Toko</th>
                                            <th className="p-3">SKU</th>
                                            <th className="p-3">Supplier Saat Ini</th>
                                        </tr>
                                    </thead>
                                    {showProductsSkeleton ? renderProductSkeletonRows() : (
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                                            {filteredProducts.map(product => {
                                                const isChecked = selectedProductIds.includes(product.id);
                                                return (
                                                    <tr key={product.id} className={`hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors ${isChecked ? 'bg-indigo-50/30 dark:bg-indigo-950/20' : ''}`}>
                                                        <td className="p-3 text-center">
                                                            <input
                                                                type="checkbox"
                                                                checked={isChecked}
                                                                onChange={() => handleToggleSelectProduct(product.id)}
                                                                className="rounded text-indigo-600 cursor-pointer"
                                                            />
                                                        </td>
                                                        <td className="p-3">
                                                            <div className="flex items-center gap-2.5 max-w-[280px]">
                                                                {product.image ? (
                                                                    <img src={product.image} className="w-9 h-9 rounded-lg object-cover flex-shrink-0 border border-slate-200 dark:border-slate-700" alt="" />
                                                                ) : (
                                                                    <div className="w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center flex-shrink-0 text-slate-400">
                                                                        <StoreIcon className="w-4 h-4" />
                                                                    </div>
                                                                )}
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="font-semibold text-slate-800 dark:text-white truncate" title={product.product_name}>
                                                                        {product.product_name}
                                                                    </p>
                                                                    <span className="text-[10px] px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-500">
                                                                        {product.store_name || product.platform}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="p-3 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                                            {product.product_sku || (
                                                                <span className="text-slate-400 italic">ID: {product.platform_product_id}</span>
                                                            )}
                                                        </td>
                                                        <td className="p-3">
                                                            {product.supplier_name ? (
                                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 border border-blue-200/50">
                                                                    <Building2 className="w-3.5 h-3.5" />
                                                                    <span>{product.supplier_name}</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-block px-2 py-0.5 rounded text-[10px] font-semibold text-rose-500 bg-rose-50 dark:bg-rose-950/30 border border-rose-200/50">
                                                                    Belum Ditentukan
                                                                </span>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    )}
                                </table>
                            </div>
                            {!showProductsSkeleton && filteredProducts.length === 0 && (
                                <div className="py-8 text-center text-xs text-slate-400">
                                    Tidak ada produk yang cocok dengan filter.
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </motion.div>

            {/* Custom Delete Supplier Confirmation Modal */}
            {typeof document !== 'undefined' && createPortal(
                <AnimatePresence>
                    {supplierToDelete && (
                        <div className="fixed inset-0 z-[520] flex items-center justify-center p-4 overflow-y-auto">
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm"
                                onClick={() => !isDeletingId && setSupplierToDelete(null)}
                            />
                            <motion.div
                                initial={{ opacity: 0, scale: 0.95, y: 15 }}
                                animate={{ opacity: 1, scale: 1, y: 0 }}
                                exit={{ opacity: 0, scale: 0.95, y: 15 }}
                                className="relative bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl p-6 shadow-2xl border border-slate-100 dark:border-slate-800 text-center z-10"
                            >
                                <div className="w-16 h-16 bg-rose-50 dark:bg-rose-950/40 rounded-2xl flex items-center justify-center mx-auto mb-4 text-rose-600 dark:text-rose-400 border border-rose-100 dark:border-rose-900/40 shadow-sm">
                                    <Trash2 className="w-8 h-8" />
                                </div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-2">
                                    Hapus Supplier?
                                </h3>
                                <p className="text-sm text-slate-600 dark:text-slate-300 mb-3">
                                    Apakah Anda yakin ingin menghapus supplier <span className="font-semibold text-rose-600 dark:text-rose-400">"{supplierToDelete.name}"</span>?
                                </p>
                                <div className="text-xs text-slate-500 dark:text-slate-400 mb-6 bg-slate-50 dark:bg-slate-800/60 p-3.5 rounded-xl border border-slate-100 dark:border-slate-800 leading-relaxed text-left flex items-start gap-2.5">
                                    <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
                                    <span>
                                        Produk yang sebelumnya dialokasikan ke supplier ini akan otomatis dilepas statusnya menjadi <strong className="text-slate-700 dark:text-slate-300 font-semibold">Belum Ditentukan (Unassigned)</strong>. Tindakan ini tidak dapat dibatalkan.
                                    </span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <button
                                        type="button"
                                        disabled={isDeletingId}
                                        onClick={() => setSupplierToDelete(null)}
                                        className="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors disabled:opacity-50 cursor-pointer"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="button"
                                        disabled={isDeletingId}
                                        onClick={handleConfirmDelete}
                                        className="flex-1 px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 active:bg-rose-800 text-white text-sm font-semibold shadow-md shadow-rose-500/20 transition-colors disabled:opacity-50 flex items-center justify-center gap-2 cursor-pointer"
                                    >
                                        {isDeletingId ? (
                                            <>
                                                <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                                <span>Menghapus...</span>
                                            </>
                                        ) : (
                                            <span>Ya, Hapus</span>
                                        )}
                                    </button>
                                </div>
                            </motion.div>
                        </div>
                    )}
                </AnimatePresence>,
                document.body
            )}
        </div>
    ), document.body);
}
