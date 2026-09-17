import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { toast } from 'react-hot-toast';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Building2,
    Users,
    PackageCheck,
    Plus,
    Trash2,
    Search,
    ArrowRight,
    ArrowLeft,
    Check,
    CheckCircle2,
    AlertCircle,
    X,
    Store as StoreIcon
} from 'lucide-react';

export default function SupplierOnboardingModal({ isOpen, onClose, onSuccess }) {
    const [step, setStep] = useState('DECISION'); // 'DECISION', 'INPUT_SUPPLIERS', 'MAP_PRODUCTS'
    const [selectedDecision, setSelectedDecision] = useState(null); // 'SINGLE' or 'MULTIPLE'

    // Single supplier state
    const [singleForm, setSingleForm] = useState({
        name: '',
        contact_person: '',
        phone: ''
    });

    // Multiple suppliers state
    const [supplierNames, setSupplierNames] = useState([]);
    const [newSupplierInput, setNewSupplierInput] = useState('');

    // Mapping state
    const [products, setProducts] = useState([]);
    const [productMappings, setProductMappings] = useState({}); // productId => supplierName
    const [isLoadingProducts, setIsLoadingProducts] = useState(false);
    const [productSearch, setProductSearch] = useState('');
    const [selectedStoreFilter, setSelectedStoreFilter] = useState('ALL');
    const [selectedProductIds, setSelectedProductIds] = useState([]);
    const [bulkSupplierTarget, setBulkSupplierTarget] = useState('');

    const [isSubmitting, setIsSubmitting] = useState(false);

    // Reset when modal opens
    useEffect(() => {
        if (isOpen) {
            setStep('DECISION');
            setSelectedDecision(null);
            setSingleForm({ name: '', contact_person: '', phone: '' });
            setSupplierNames([]);
            setNewSupplierInput('');
            setProductMappings({});
            setSelectedProductIds([]);
        }
    }, [isOpen]);

    // Load products when moving to MAP_PRODUCTS step
    const loadProductsForMapping = async () => {
        setIsLoadingProducts(true);
        try {
            const res = await axios.get('/api/payable/suppliers/products');
            const data = res.data.data || [];
            setProducts(data);

            // Pre-fill mappings if any already assigned
            const initialMap = {};
            data.forEach(p => {
                if (p.supplier_name && supplierNames.includes(p.supplier_name)) {
                    initialMap[p.id] = p.supplier_name;
                } else if (supplierNames.length > 0) {
                    initialMap[p.id] = supplierNames[0]; // default to first supplier
                }
            });
            setProductMappings(initialMap);
        } catch (err) {
            console.error("Failed to load products for mapping", err);
            toast.error("Gagal mengambil data produk toko");
        } finally {
            setIsLoadingProducts(false);
        }
    };

    // --- Actions for Single Supplier ---
    const handleSingleSubmit = async (e) => {
        e.preventDefault();
        if (!singleForm.name.trim()) {
            toast.error("Nama supplier tidak boleh kosong");
            return;
        }

        setIsSubmitting(true);
        try {
            const res = await axios.post('/api/payable/suppliers/onboarding-single', singleForm);
            toast.success(res.data.message || "Supplier berhasil didaftarkan!");
            if (onSuccess) onSuccess();
            if (onClose) onClose();
        } catch (err) {
            console.error("Single onboarding failed", err);
            toast.error(err.response?.data?.message || "Gagal mendaftarkan supplier");
        } finally {
            setIsSubmitting(false);
        }
    };

    // --- Actions for Multiple Suppliers ---
    const handleAddSupplierName = (e) => {
        if (e) e.preventDefault();
        const trimmed = newSupplierInput.trim();
        if (!trimmed) return;
        if (supplierNames.some(s => s.toLowerCase() === trimmed.toLowerCase())) {
            toast.error("Nama supplier sudah ada dalam daftar");
            return;
        }
        setSupplierNames([...supplierNames, trimmed]);
        setNewSupplierInput('');
    };

    const handleRemoveSupplierName = (indexToRemove) => {
        const nameToRemove = supplierNames[indexToRemove];
        setSupplierNames(supplierNames.filter((_, idx) => idx !== indexToRemove));
        
        // Remove from mappings
        const updatedMappings = { ...productMappings };
        Object.keys(updatedMappings).forEach(pId => {
            if (updatedMappings[pId] === nameToRemove) {
                delete updatedMappings[pId];
            }
        });
        setProductMappings(updatedMappings);
    };

    const handleProceedToMapping = async () => {
        if (supplierNames.length < 2) {
            toast.error("Silakan masukkan minimal 2 nama supplier");
            return;
        }
        setStep('MAP_PRODUCTS');
        await loadProductsForMapping();
    };

    // --- Mapping Actions ---
    const handleProductSupplierChange = (productId, supplierName) => {
        setProductMappings(prev => ({
            ...prev,
            [productId]: supplierName
        }));
    };

    const handleToggleSelectProduct = (productId) => {
        setSelectedProductIds(prev =>
            prev.includes(productId) ? prev.filter(id => id !== productId) : [...prev, productId]
        );
    };

    const handleSelectAllFiltered = (filteredList) => {
        const filteredIds = filteredList.map(p => p.id);
        const allSelected = filteredIds.every(id => selectedProductIds.includes(id));
        if (allSelected) {
            setSelectedProductIds(prev => prev.filter(id => !filteredIds.includes(id)));
        } else {
            setSelectedProductIds(prev => Array.from(new Set([...prev, ...filteredIds])));
        }
    };

    const handleApplyBulkSupplier = () => {
        if (!bulkSupplierTarget) {
            toast.error("Pilih supplier target terlebih dahulu");
            return;
        }
        if (selectedProductIds.length === 0) {
            toast.error("Pilih produk yang ingin dialihkan");
            return;
        }

        setProductMappings(prev => {
            const next = { ...prev };
            selectedProductIds.forEach(id => {
                next[id] = bulkSupplierTarget;
            });
            return next;
        });
        toast.success(`${selectedProductIds.length} produk dialihkan ke ${bulkSupplierTarget}`);
        setSelectedProductIds([]);
    };

    const handleMultipleSubmit = async () => {
        // Validate that all products have an assigned supplier
        const unmappedCount = products.filter(p => !productMappings[p.id]).length;
        if (unmappedCount > 0) {
            toast.error(`Masih ada ${unmappedCount} produk yang belum ditentukan supplier-nya`);
            return;
        }

        setIsSubmitting(true);
        try {
            const mappingsPayload = products.map(p => ({
                product_id: p.id,
                supplier_name: productMappings[p.id]
            }));

            const res = await axios.post('/api/payable/suppliers/onboarding-multiple', {
                suppliers: supplierNames,
                mappings: mappingsPayload
            });

            toast.success(res.data.message || "Supplier & pemetaan produk berhasil disimpan!");
            if (onSuccess) onSuccess();
            if (onClose) onClose();
        } catch (err) {
            console.error("Multiple onboarding failed", err);
            toast.error(err.response?.data?.message || "Gagal menyimpan pemetaan supplier");
        } finally {
            setIsSubmitting(false);
        }
    };

    // Filter products
    const availableStores = Array.from(new Set(products.map(p => p.store_name).filter(Boolean)));
    const filteredProducts = products.filter(p => {
        if (selectedStoreFilter !== 'ALL' && p.store_name !== selectedStoreFilter) return false;
        if (!productSearch.trim()) return true;
        const q = productSearch.toLowerCase();
        return (
            (p.product_name && p.product_name.toLowerCase().includes(q)) ||
            (p.product_sku && p.product_sku.toLowerCase().includes(q)) ||
            (p.platform_product_id && String(p.platform_product_id).includes(q))
        );
    });

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
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
                className={`relative bg-white dark:bg-slate-900 w-full ${
                    step === 'MAP_PRODUCTS' ? 'max-w-4xl' : 'max-w-xl'
                } rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden flex flex-col max-h-[90vh] z-10 transition-all duration-300`}
            >
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex-shrink-0 bg-slate-50/50 dark:bg-slate-800/30">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-2xl bg-indigo-500/10 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                            <Building2 className="w-5 h-5" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold text-slate-800 dark:text-white">
                                {step === 'DECISION' && "Konfigurasi Supplier Bisnis"}
                                {step === 'INPUT_SUPPLIERS' && "Daftarkan Supplier"}
                                {step === 'MAP_PRODUCTS' && "Petakan Produk ke Supplier"}
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                {step === 'DECISION' && "Atur pembukuan hutang dagang (HPP) berdasarkan asal supplier"}
                                {step === 'INPUT_SUPPLIERS' && "Tentukan daftar supplier yang memasok produk kamu"}
                                {step === 'MAP_PRODUCTS' && "Tentukan produk mana saja yang dipasok oleh masing-masing supplier"}
                            </p>
                        </div>
                    </div>
                    {onClose && (
                        <button
                            type="button"
                            onClick={onClose}
                            className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    )}
                </div>

                {/* Body Content */}
                <div className="p-6 overflow-y-auto flex-1 custom-scrollbar">
                    {/* STEP 1: DECISION */}
                    {step === 'DECISION' && (
                        <div className="space-y-6">
                            <div className="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 text-center">
                                <h3 className="text-sm font-bold text-indigo-900 dark:text-indigo-200 mb-1">
                                    Apakah semua SKU pada bisnis kamu berasal dari 1 supplier?
                                </h3>
                                <p className="text-xs text-indigo-700/80 dark:text-indigo-300/80 max-w-md mx-auto">
                                    Pilih opsi yang sesuai dengan alur rantai pasok (*supply chain*) bisnis kamu saat ini.
                                </p>
                            </div>

                            {/* Options */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {/* Option A: 1 Supplier */}
                                <div
                                    onClick={() => setSelectedDecision('SINGLE')}
                                    className={`p-5 rounded-2xl border-2 cursor-pointer transition-all ${
                                        selectedDecision === 'SINGLE'
                                            ? 'border-indigo-600 dark:border-blue-500 bg-indigo-50/40 dark:bg-blue-950/20 shadow-md ring-2 ring-indigo-500/20'
                                            : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-800/40'
                                    }`}
                                >
                                    <div className="flex items-center justify-between mb-3">
                                        <div className="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                                            <PackageCheck className="w-5 h-5" />
                                        </div>
                                        {selectedDecision === 'SINGLE' && (
                                            <span className="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center">
                                                <Check className="w-3 h-3 stroke-[3]" />
                                            </span>
                                        )}
                                    </div>
                                    <h4 className="text-sm font-bold text-slate-800 dark:text-white mb-1">
                                        Ya, 1 Supplier
                                    </h4>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                        Semua produk & SKU dipasok oleh satu supplier utama. Sistem akan otomatis mendaftarkan seluruh SKU ke supplier ini.
                                    </p>
                                </div>

                                {/* Option B: Multi Supplier */}
                                <div
                                    onClick={() => setSelectedDecision('MULTIPLE')}
                                    className={`p-5 rounded-2xl border-2 cursor-pointer transition-all ${
                                        selectedDecision === 'MULTIPLE'
                                            ? 'border-indigo-600 dark:border-blue-500 bg-indigo-50/40 dark:bg-blue-950/20 shadow-md ring-2 ring-indigo-500/20'
                                            : 'border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-800/40'
                                    }`}
                                >
                                    <div className="flex items-center justify-between mb-3">
                                        <div className="w-10 h-10 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                                            <Users className="w-5 h-5" />
                                        </div>
                                        {selectedDecision === 'MULTIPLE' && (
                                            <span className="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center">
                                                <Check className="w-3 h-3 stroke-[3]" />
                                            </span>
                                        )}
                                    </div>
                                    <h4 className="text-sm font-bold text-slate-800 dark:text-white mb-1">
                                        Lebih dari 1 Supplier
                                    </h4>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                        Produk dipasok oleh beberapa supplier berbeda. Kamu bisa memetakan produk A ke supplier X, dan produk B ke supplier Y.
                                    </p>
                                </div>
                            </div>

                            {/* If SINGLE chosen, show name input right away */}
                            {selectedDecision === 'SINGLE' && (
                                <motion.form
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: 'auto' }}
                                    onSubmit={handleSingleSubmit}
                                    className="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-4"
                                >
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                            Nama Supplier <span className="text-rose-500">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            required
                                            placeholder="Contoh: CV Makmur Sentosa / Gudang Pusat"
                                            value={singleForm.name}
                                            onChange={e => setSingleForm({ ...singleForm, name: e.target.value })}
                                            className="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/40"
                                        />
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                                                Kontak / PIC (Opsional)
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="Nama kontak..."
                                                value={singleForm.contact_person}
                                                onChange={e => setSingleForm({ ...singleForm, contact_person: e.target.value })}
                                                className="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/40"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5">
                                                Nomor Telepon (Opsional)
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="0812..."
                                                value={singleForm.phone}
                                                onChange={e => setSingleForm({ ...singleForm, phone: e.target.value })}
                                                className="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white rounded-xl px-3.5 py-2 text-xs outline-none focus:ring-2 focus:ring-indigo-500/40"
                                            />
                                        </div>
                                    </div>
                                    <div className="pt-2 flex justify-end">
                                        <button
                                            type="submit"
                                            disabled={isSubmitting}
                                            className="px-6 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-all shadow-md shadow-indigo-500/20 disabled:opacity-60 cursor-pointer"
                                        >
                                            {isSubmitting ? "Menyimpan..." : "Simpan & Lanjutkan ke Payable"}
                                        </button>
                                    </div>
                                </motion.form>
                            )}

                            {/* If MULTIPLE chosen, show Next button */}
                            {selectedDecision === 'MULTIPLE' && (
                                <motion.div
                                    initial={{ opacity: 0, y: 10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    className="pt-2 flex justify-end"
                                >
                                    <button
                                        type="button"
                                        onClick={() => setStep('INPUT_SUPPLIERS')}
                                        className="flex items-center gap-2 px-6 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-all shadow-md shadow-indigo-500/20 cursor-pointer"
                                    >
                                        <span>Lanjut: Input Daftar Supplier</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </button>
                                </motion.div>
                            )}
                        </div>
                    )}

                    {/* STEP 2: INPUT_SUPPLIERS */}
                    {step === 'INPUT_SUPPLIERS' && (
                        <div className="space-y-6">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-2">
                                    Masukkan Nama Supplier (Minimal 2 supplier)
                                </label>
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        placeholder="Ketik nama supplier (misal: Supplier Sepatu Bandung)..."
                                        value={newSupplierInput}
                                        onChange={e => setNewSupplierInput(e.target.value)}
                                        onKeyDown={e => { if (e.key === 'Enter') handleAddSupplierName(e); }}
                                        className="flex-1 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-white rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500/40"
                                    />
                                    <button
                                        type="button"
                                        onClick={handleAddSupplierName}
                                        className="flex items-center gap-1.5 px-4 py-2.5 bg-indigo-50 dark:bg-indigo-950/50 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 font-bold rounded-xl text-sm border border-indigo-200 dark:border-indigo-800/60 transition-colors cursor-pointer"
                                    >
                                        <Plus className="w-4 h-4" />
                                        <span>Tambah</span>
                                    </button>
                                </div>
                            </div>

                            {/* Supplier Chips */}
                            <div>
                                <h4 className="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-2">
                                    Daftar Supplier ({supplierNames.length}):
                                </h4>
                                {supplierNames.length === 0 ? (
                                    <div className="p-6 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-800 text-center text-xs text-slate-400">
                                        Belum ada supplier yang ditambahkan. Silakan ketik nama supplier di atas lalu tekan Tambah.
                                    </div>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        {supplierNames.map((name, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-center gap-2 pl-3 pr-2 py-1.5 bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800/50 rounded-xl text-xs font-semibold text-indigo-900 dark:text-indigo-200"
                                            >
                                                <span>{name}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => handleRemoveSupplierName(idx)}
                                                    className="p-1 hover:bg-indigo-200/50 dark:hover:bg-indigo-900/60 rounded-lg text-indigo-500 hover:text-rose-600 transition-colors"
                                                    title="Hapus"
                                                >
                                                    <X className="w-3.5 h-3.5" />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Buttons */}
                            <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex justify-between">
                                <button
                                    type="button"
                                    onClick={() => setStep('DECISION')}
                                    className="flex items-center gap-1.5 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                                >
                                    <ArrowLeft className="w-4 h-4" />
                                    <span>Kembali</span>
                                </button>
                                <button
                                    type="button"
                                    disabled={supplierNames.length < 2}
                                    onClick={handleProceedToMapping}
                                    className="flex items-center gap-2 px-6 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 disabled:opacity-50 text-white rounded-xl text-sm font-semibold transition-all shadow-md shadow-indigo-500/20 cursor-pointer"
                                >
                                    <span>Lanjut: Petakan Produk</span>
                                    <ArrowRight className="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    )}

                    {/* STEP 3: MAP_PRODUCTS */}
                    {step === 'MAP_PRODUCTS' && (
                        <div className="space-y-4">
                            {/* Filter and Bulk Action Bar */}
                            <div className="flex flex-col sm:flex-row gap-2.5 items-stretch sm:items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                {/* Search */}
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

                                {/* Store Filter */}
                                {availableStores.length > 1 && (
                                    <select
                                        value={selectedStoreFilter}
                                        onChange={e => setSelectedStoreFilter(e.target.value)}
                                        className="bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs rounded-xl px-3 py-1.5 text-slate-700 dark:text-slate-300 outline-none"
                                    >
                                        <option value="ALL">Semua Toko ({products.length})</option>
                                        {availableStores.map(sName => (
                                            <option key={sName} value={sName}>{sName}</option>
                                        ))}
                                    </select>
                                )}

                                {/* Bulk Action */}
                                <div className="flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800/60 p-1 rounded-xl border border-slate-200 dark:border-slate-700">
                                    <select
                                        value={bulkSupplierTarget}
                                        onChange={e => setBulkSupplierTarget(e.target.value)}
                                        className="bg-transparent text-xs text-slate-700 dark:text-slate-300 px-2 py-1 outline-none"
                                    >
                                        <option value="">Pilih Supplier Target</option>
                                        {supplierNames.map(sName => (
                                            <option key={sName} value={sName}>{sName}</option>
                                        ))}
                                    </select>
                                    <button
                                        type="button"
                                        onClick={handleApplyBulkSupplier}
                                        disabled={selectedProductIds.length === 0 || !bulkSupplierTarget}
                                        className="px-2.5 py-1 bg-[#304674] dark:bg-blue-600 hover:bg-[#253659] disabled:opacity-50 text-white text-[11px] font-bold rounded-lg transition-colors cursor-pointer"
                                    >
                                        Terapkan ({selectedProductIds.length})
                                    </button>
                                </div>
                            </div>

                            {/* Products Table */}
                            {isLoadingProducts ? (
                                <div className="py-12 text-center text-xs text-slate-400">
                                    Memuat daftar produk...
                                </div>
                            ) : filteredProducts.length === 0 ? (
                                <div className="py-12 text-center text-xs text-slate-400">
                                    Tidak ada produk yang sesuai dengan filter.
                                </div>
                            ) : (
                                <div className="overflow-x-auto max-h-[380px] border border-slate-100 dark:border-slate-800 rounded-2xl">
                                    <table className="w-full text-left text-xs border-collapse">
                                        <thead className="bg-slate-50 dark:bg-slate-800/80 sticky top-0 z-10 text-[11px] font-bold text-slate-500 uppercase">
                                            <tr>
                                                <th className="p-3 w-10 text-center">
                                                    <input
                                                        type="checkbox"
                                                        checked={filteredProducts.length > 0 && filteredProducts.every(p => selectedProductIds.includes(p.id))}
                                                        onChange={() => handleSelectAllFiltered(filteredProducts)}
                                                        className="rounded text-indigo-600 cursor-pointer"
                                                    />
                                                </th>
                                                <th className="p-3">Produk &amp; Toko</th>
                                                <th className="p-3">SKU</th>
                                                <th className="p-3 w-48">Pilih Supplier</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                                            {filteredProducts.map(product => {
                                                const isChecked = selectedProductIds.includes(product.id);
                                                const assignedSupplier = productMappings[product.id] || '';

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
                                                                    <div className="flex items-center gap-1.5 mt-0.5">
                                                                        <span className="text-[10px] px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-500">
                                                                            {product.store_name || product.platform}
                                                                        </span>
                                                                        {product.variants_count > 0 && (
                                                                            <span className="text-[10px] text-slate-400">
                                                                                {product.variants_count} varian
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td className="p-3 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                                            {product.product_sku || (
                                                                <span className="text-slate-400 italic">Berdasarkan ID</span>
                                                            )}
                                                        </td>
                                                        <td className="p-3">
                                                            <select
                                                                value={assignedSupplier}
                                                                onChange={e => handleProductSupplierChange(product.id, e.target.value)}
                                                                className={`w-full text-xs font-semibold py-1.5 px-2.5 rounded-xl border transition-colors outline-none cursor-pointer ${
                                                                    assignedSupplier
                                                                        ? 'bg-white dark:bg-slate-800 border-indigo-200 dark:border-indigo-800/80 text-indigo-900 dark:text-indigo-200 font-bold'
                                                                        : 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900 text-rose-600 dark:text-rose-400'
                                                                }`}
                                                            >
                                                                <option value="">-- Pilih Supplier --</option>
                                                                {supplierNames.map(sName => (
                                                                    <option key={sName} value={sName}>{sName}</option>
                                                                ))}
                                                            </select>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {/* Footer Buttons */}
                            <div className="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                <button
                                    type="button"
                                    onClick={() => setStep('INPUT_SUPPLIERS')}
                                    className="flex items-center gap-1.5 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
                                >
                                    <ArrowLeft className="w-4 h-4" />
                                    <span>Kembali</span>
                                </button>
                                <button
                                    type="button"
                                    disabled={isSubmitting}
                                    onClick={handleMultipleSubmit}
                                    className="flex items-center gap-2 px-6 py-2.5 bg-[#304674] hover:bg-[#253659] dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition-all shadow-md shadow-indigo-500/20 disabled:opacity-50 cursor-pointer"
                                >
                                    <span>{isSubmitting ? "Menyimpan..." : "Selesai & Masuk ke Rekap Payable"}</span>
                                    <Check className="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </motion.div>
        </div>
    );
}
