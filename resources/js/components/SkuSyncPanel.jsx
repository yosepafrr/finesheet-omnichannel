import React, { useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { motion, AnimatePresence } from "framer-motion";
import axios from "axios";

export default function SkuSyncPanel({ search = "" }) {
    const [groups, setGroups] = useState([]);
    const [detected, setDetected] = useState([]);
    const [loading, setLoading] = useState(false);
    const [detecting, setDetecting] = useState(false);
    const [error, setError] = useState(null);

    const [modalOpen, setModalOpen] = useState(false);
    const [selectedDetection, setSelectedDetection] = useState(null);
    const [masterStock, setMasterStock] = useState(0);
    const [hasDetectedEmpty, setHasDetectedEmpty] = useState(false);

    // Custom Modals State
    const [deleteModal, setDeleteModal] = useState({ open: false, id: null });
    const [pushModal, setPushModal] = useState({ open: false, id: null });
    const [successModal, setSuccessModal] = useState({ open: false, message: "" });

    // Bulk selection state
    const [selectedSkus, setSelectedSkus] = useState([]);
    const [isCreatingBulk, setIsCreatingBulk] = useState(false);

    // Edit state
    const [editStockModal, setEditStockModal] = useState({ open: false, id: null, stock: 0 });

    useEffect(() => {
        fetchGroups();
    }, []);

    const fetchGroups = async () => {
        setLoading(true);
        try {
            const res = await axios.get("/api/sku-sync/groups");
            setGroups(res.data);
        } catch (err) {
            setError("Gagal memuat grup sinkronisasi.");
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleDetect = async () => {
        setDetecting(true);
        setHasDetectedEmpty(false);
        setSelectedSkus([]);
        try {
            const res = await axios.get("/api/sku-sync/detect");
            setDetected(res.data);
            if (res.data.length === 0) {
                setHasDetectedEmpty(true);
            }
        } catch (err) {
            alert("Gagal mendeteksi SKU.");
            console.error(err);
        } finally {
            setDetecting(false);
        }
    };

    const openCreateModal = (item) => {
        setSelectedDetection(item);
        setMasterStock(item.items[0]?.stock || 0); // Default to first item's stock
        setModalOpen(true);
    };

    const handleCreateGroup = async () => {
        if (!selectedDetection) return;
        
        try {
            const members = selectedDetection.items.map(item => ({
                product_id: item.product_id,
                variant_product_id: item.id
            }));

            await axios.post("/api/sku-sync/groups", {
                sku: selectedDetection.sku,
                master_stock: masterStock,
                members: members
            });
            
            setModalOpen(false);
            setSuccessModal({ open: true, message: `Berhasil membuat grup untuk SKU ${selectedDetection.sku}!` });
            setDetected(prev => prev.filter(d => d.sku !== selectedDetection.sku));
            fetchGroups();
        } catch (err) {
            const errorMsg = err.response?.data?.error || err.response?.data?.message || err.message || "Gagal membuat grup.";
            alert("Error: " + errorMsg);
            console.error(err.response?.data || err);
        }
    };

    const handleCreateBulk = async () => {
        if (selectedSkus.length === 0) return;
        setIsCreatingBulk(true);
        try {
            const groupsToCreate = selectedSkus.map(sku => {
                const d = detected.find(item => item.sku === sku);
                return {
                    sku: d.sku,
                    master_stock: d.items[0]?.stock || 0,
                    members: d.items.map(item => ({
                        product_id: item.product_id,
                        variant_product_id: item.id
                    }))
                };
            });

            await axios.post("/api/sku-sync/groups/bulk", { groups: groupsToCreate });
            
            setSuccessModal({ open: true, message: `Berhasil membuat ${groupsToCreate.length} grup sinkronisasi sekaligus!` });
            setDetected(prev => prev.filter(d => !selectedSkus.includes(d.sku)));
            setSelectedSkus([]);
            fetchGroups();
        } catch (err) {
            const errorMsg = err.response?.data?.error || err.response?.data?.message || err.message || "Gagal membuat grup massal.";
            alert("Error: " + errorMsg);
            console.error(err.response?.data || err);
        } finally {
            setIsCreatingBulk(false);
        }
    };

    const handleDelete = async () => {
        const id = deleteModal.id;
        if (!id) return;
        setDeleteModal({ open: false, id: null });
        try {
            await axios.delete(`/api/sku-sync/groups/${id}`);
            fetchGroups();
        } catch (err) {
            alert("Gagal menghapus grup.");
            console.error(err);
        }
    };

    const handlePush = async () => {
        const id = pushModal.id;
        if (!id) return;
        setPushModal({ open: false, id: null });
        try {
            await axios.post(`/api/sku-sync/groups/${id}/push`);
            setSuccessModal({ open: true, message: "Berhasil push stok ke marketplace!" });
            fetchGroups();
        } catch (err) {
            alert("Gagal push stok.");
            console.error(err);
        }
    };

    const handleEditStock = async () => {
        const { id, stock } = editStockModal;
        if (!id) return;
        try {
            await axios.put(`/api/sku-sync/groups/${id}`, { master_stock: stock });
            setEditStockModal({ open: false, id: null, stock: 0 });
            setSuccessModal({ open: true, message: "Berhasil mengubah master stock!" });
            fetchGroups();
        } catch (err) {
            alert("Gagal mengubah master stock.");
            console.error(err);
        }
    };
    
    const handleToggle = async (id) => {
        try {
            await axios.put(`/api/sku-sync/groups/${id}/toggle`);
            fetchGroups();
        } catch (err) {
            alert("Gagal mengubah status grup.");
            console.error(err);
        }
    }
    const filteredDetected = detected.filter(d => d.sku.toLowerCase().includes(search.toLowerCase()));
    const filteredGroups = groups.filter(g => g.sku.toLowerCase().includes(search.toLowerCase()));

    return (
        <div className="space-y-6">
            <div className="flex justify-between items-center bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700">
                <div>
                    <h2 className="text-lg font-bold text-gray-800 dark:text-white">Sinkronisasi Stok</h2>
                    <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">Gabungkan SKU yang sama dari berbagai toko agar stok saling terhubung.</p>
                </div>
                <button
                    onClick={handleDetect}
                    disabled={detecting}
                    className="px-4 py-2 bg-blue-50 dark:bg-blue-900/30 text-[#304674] dark:text-blue-400 text-sm font-semibold rounded-xl hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors flex items-center gap-2"
                >
                    <span className={`material-symbols-rounded text-lg ${detecting ? 'animate-spin' : ''}`}>
                        search
                    </span>
                    {detecting ? "Mendeteksi..." : "Deteksi SKU Sama"}
                </button>
            </div>

            {filteredDetected.length > 0 && (
                <div className="bg-amber-50 dark:bg-amber-900/20 p-6 rounded-2xl border border-amber-200 dark:border-amber-800/50">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-4">
                        <h3 className="font-semibold text-amber-800 dark:text-amber-500 flex items-center gap-2">
                            <span className="material-symbols-rounded">lightbulb</span>
                            Ditemukan {filteredDetected.length} SKU yang sama di beberapa toko
                        </h3>
                        <div className="flex items-center gap-3">
                            <label className="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-500 font-medium cursor-pointer">
                                <input 
                                    type="checkbox" 
                                    className="rounded border-amber-300 text-amber-600 focus:ring-amber-500 bg-white/50"
                                    checked={selectedSkus.length === filteredDetected.length && filteredDetected.length > 0}
                                    onChange={(e) => {
                                        if (e.target.checked) setSelectedSkus(filteredDetected.map(d => d.sku));
                                        else setSelectedSkus([]);
                                    }}
                                />
                                Pilih Semua
                            </label>
                            <button
                                onClick={handleCreateBulk}
                                disabled={selectedSkus.length === 0 || isCreatingBulk}
                                className="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:bg-amber-300 dark:disabled:bg-amber-800/50 disabled:cursor-not-allowed text-white text-sm font-bold rounded-xl transition-colors shadow-sm"
                            >
                                {isCreatingBulk ? "Memproses..." : `Buat Massal (${selectedSkus.length})`}
                            </button>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        {filteredDetected.map(d => (
                            <div key={d.sku} className={`bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border transition-colors flex justify-between items-center ${selectedSkus.includes(d.sku) ? 'border-amber-400 ring-1 ring-amber-400' : 'border-amber-100 dark:border-amber-900/30'}`}>
                                <div className="flex items-start gap-3">
                                    <input 
                                        type="checkbox" 
                                        className="mt-1 rounded border-amber-300 text-amber-600 focus:ring-amber-500 cursor-pointer"
                                        checked={selectedSkus.includes(d.sku)}
                                        onChange={(e) => {
                                            if (e.target.checked) setSelectedSkus(prev => [...prev, d.sku]);
                                            else setSelectedSkus(prev => prev.filter(s => s !== d.sku));
                                        }}
                                    />
                                    <div>
                                        <div className="font-bold text-gray-800 dark:text-white cursor-pointer" onClick={() => {
                                            if (selectedSkus.includes(d.sku)) setSelectedSkus(prev => prev.filter(s => s !== d.sku));
                                            else setSelectedSkus(prev => [...prev, d.sku]);
                                        }}>{d.sku}</div>
                                        <div className="text-xs text-gray-500 dark:text-slate-400 mt-1">
                                            Ada di {d.stores_count} toko
                                        </div>
                                    </div>
                                </div>
                                <button
                                    onClick={() => openCreateModal(d)}
                                    title="Buat satu grup (kustomisasi)"
                                    className="px-3 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-800 dark:bg-amber-900/50 dark:hover:bg-amber-800/80 dark:text-amber-200 text-xs font-semibold rounded-lg transition-colors whitespace-nowrap"
                                >
                                    Buat (1)
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {hasDetectedEmpty && detected.length === 0 && (
                <motion.div 
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="relative overflow-hidden bg-gradient-to-br from-blue-50 to-white dark:from-slate-800 dark:to-slate-900 rounded-3xl p-10 border border-blue-100 dark:border-slate-700 shadow-sm flex flex-col items-center justify-center text-center"
                >
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgba(48,70,116,0.22)_1px,transparent_0)] bg-[length:18px_18px] opacity-30 pointer-events-none"></div>
                    <motion.div 
                        initial={{ scale: 0.8 }}
                        animate={{ scale: 1 }}
                        transition={{ type: "spring", bounce: 0.5 }}
                        className="w-20 h-20 bg-white dark:bg-slate-800 shadow-md shadow-blue-100 dark:shadow-none rounded-full flex items-center justify-center mb-6 relative z-10"
                    >
                        <span className="material-symbols-rounded text-4xl text-green-500">task_alt</span>
                    </motion.div>
                    <h3 className="text-2xl font-black text-[#304674] dark:text-white mb-3 relative z-10">Semua SKU Tersinkronisasi!</h3>
                    <p className="text-base text-gray-500 dark:text-slate-400 max-w-lg relative z-10 leading-relaxed">
                        Tidak ada SKU identik baru yang ditemukan. Jika Anda merasa ada SKU yang terlewat, pastikan <strong className="text-[#304674] dark:text-blue-400">SKU Induk / Model SKU</strong> di masing-masing platform ditulis persis sama.
                    </p>
                </motion.div>
            )}

            {/* List Groups */}
            <div>
                <h3 className="font-bold text-gray-800 dark:text-white mb-4">Grup Sinkronisasi Aktif</h3>
                {loading ? (
                    <div className="text-sm text-gray-500">Memuat data...</div>
                ) : groups.length === 0 ? (
                    <div className="text-center p-10 bg-white dark:bg-slate-800 rounded-2xl border border-dashed border-gray-300 dark:border-slate-600">
                        <p className="text-gray-500 dark:text-slate-400">Belum ada grup sinkronisasi.</p>
                        <button onClick={handleDetect} className="mt-2 text-[#304674] dark:text-blue-400 text-sm font-medium hover:underline">Deteksi sekarang</button>
                    </div>
                ) : filteredGroups.length === 0 ? (
                    <div className="text-center p-10 bg-white dark:bg-slate-800 rounded-2xl border border-dashed border-gray-300 dark:border-slate-600">
                        <p className="text-gray-500 dark:text-slate-400">Tidak ada grup sinkronisasi yang cocok dengan pencarian.</p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {filteredGroups.map(group => (
                            <div key={group.id} className="bg-white dark:bg-slate-800 p-5 rounded-2xl shadow-sm border border-gray-200 dark:border-slate-700">
                                <div className="flex justify-between items-start mb-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h4 className="font-bold text-lg text-gray-800 dark:text-white">{group.sku}</h4>
                                            {!group.is_active && (
                                                <span className="text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">Nonaktif</span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs text-gray-500 mt-1">
                                            <span>Master Stock: <span className="font-bold text-gray-700 dark:text-gray-300">{group.master_stock}</span></span>
                                            <button 
                                                onClick={() => setEditStockModal({ open: true, id: group.id, stock: group.master_stock })} 
                                                title="Edit Master Stock"
                                                className="text-blue-500 hover:text-blue-700 dark:hover:text-blue-400 p-0.5 rounded-md hover:bg-blue-50 dark:hover:bg-slate-700 transition"
                                            >
                                                <span className="material-symbols-rounded text-[14px]">edit</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <button onClick={() => setPushModal({ open: true, id: group.id })} title="Push ke Marketplace" className="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition">
                                            <span className="material-symbols-rounded text-lg">sync_saved_locally</span>
                                        </button>
                                        <button onClick={() => handleToggle(group.id)} title={group.is_active ? "Nonaktifkan" : "Aktifkan"} className="p-1.5 text-orange-600 hover:bg-orange-50 rounded-lg transition">
                                            <span className="material-symbols-rounded text-lg">{group.is_active ? 'pause' : 'play_arrow'}</span>
                                        </button>
                                        <button onClick={() => setDeleteModal({ open: true, id: group.id })} title="Hapus Grup" className="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition">
                                            <span className="material-symbols-rounded text-lg">delete</span>
                                        </button>
                                    </div>
                                </div>
                                <div className="space-y-2 mt-4 pt-4 border-t border-gray-100 dark:border-slate-700">
                                    <div className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Toko Terhubung</div>
                                    {group.members.map(member => (
                                        <div key={member.id} className="flex justify-between items-center text-sm">
                                            <div className="flex items-center gap-2">
                                                <span className="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                                <span className="text-gray-700 dark:text-gray-300">{member.store?.store_name}</span>
                                                <span className="text-xs text-gray-400">({member.store?.platform})</span>
                                            </div>
                                            <div className="text-xs text-gray-500 truncate max-w-[120px]">
                                                {member.variant ? member.variant.variant_name : member.product?.product_name}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Create Modal */}
            {typeof document !== 'undefined' && createPortal(
                <AnimatePresence>
                    {modalOpen && selectedDetection && (
                        <motion.div 
                            initial={{ opacity: 0 }} 
                            animate={{ opacity: 1 }} 
                            exit={{ opacity: 0 }} 
                            className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
                        >
                            <motion.div 
                                initial={{ scale: 0.95, opacity: 0, y: 20 }}
                                animate={{ scale: 1, opacity: 1, y: 0 }}
                                exit={{ scale: 0.95, opacity: 0, y: 20 }}
                                className="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl shadow-2xl overflow-hidden border border-slate-100 dark:border-slate-800"
                            >
                                <div className="p-6">
                                    <h3 className="text-xl font-bold text-gray-800 dark:text-white mb-2">Buat Grup Sinkronisasi</h3>
                                    <p className="text-sm text-gray-500 dark:text-slate-400 mb-6">
                                        SKU: <span className="font-bold text-gray-700 dark:text-gray-300">{selectedDetection.sku}</span>
                                    </p>
                                    
                                    <div className="mb-6">
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Master Stock Awal</label>
                                        <input
                                            type="number"
                                            value={masterStock}
                                            onChange={(e) => setMasterStock(parseInt(e.target.value) || 0)}
                                            className="w-full px-4 py-2 border border-gray-200 dark:border-slate-700 rounded-xl bg-gray-50 dark:bg-slate-800 text-gray-800 dark:text-white focus:ring-[#304674]"
                                        />
                                        <p className="text-xs text-gray-500 mt-2">Stok ini akan langsung di-push ke semua toko di bawah ini.</p>
                                    </div>

                                    <div className="mb-6">
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Toko yang akan disinkronkan:</label>
                                        <div className="space-y-2 max-h-40 overflow-y-auto">
                                            {selectedDetection.items.map((item, idx) => (
                                                <div key={idx} className="p-3 bg-gray-50 dark:bg-slate-800 rounded-xl border border-gray-100 dark:border-slate-700">
                                                    <div className="text-sm font-bold text-gray-800 dark:text-white">{item.store_name} <span className="text-xs font-normal text-gray-500">({item.platform})</span></div>
                                                    <div className="text-xs text-gray-500 mt-1">{item.product_name} - {item.variant_name}</div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                    
                                    <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-slate-800">
                                        <button
                                            onClick={() => setModalOpen(false)}
                                            className="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:hover:bg-slate-800 dark:text-slate-300 rounded-xl transition"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            onClick={handleCreateGroup}
                                            className="px-4 py-2 text-sm font-bold text-white bg-[#304674] hover:bg-[#243558] rounded-xl transition shadow-lg shadow-blue-900/20"
                                        >
                                            Simpan & Push Stok
                                        </button>
                                    </div>
                                </div>
                            </motion.div>
                        </motion.div>
                    )}
                    
                    {/* Push Confirm Modal */}
                    {pushModal.open && (
                        <motion.div 
                            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} 
                            className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
                        >
                            <motion.div 
                                initial={{ scale: 0.95, opacity: 0, y: 20 }} animate={{ scale: 1, opacity: 1, y: 0 }} exit={{ scale: 0.95, opacity: 0, y: 20 }}
                                className="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 text-center border border-slate-100 dark:border-slate-800"
                            >
                                <div className="w-16 h-16 bg-blue-50 dark:bg-blue-900/20 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <span className="material-symbols-rounded text-3xl">sync</span>
                                </div>
                                <h3 className="text-lg font-bold text-gray-800 dark:text-white mb-2">Push Stok ke Marketplace?</h3>
                                <p className="text-sm text-gray-500 dark:text-slate-400 mb-6">
                                    Aksi ini akan menimpa stok yang ada di marketplace dengan master stok saat ini. Lanjutkan?
                                </p>
                                <div className="flex gap-3">
                                    <button onClick={() => setPushModal({ open: false, id: null })} className="flex-1 py-2.5 text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 rounded-xl transition">Batal</button>
                                    <button onClick={handlePush} className="flex-1 py-2.5 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-lg shadow-blue-500/30">Ya, Push</button>
                                </div>
                            </motion.div>
                        </motion.div>
                    )}

                    {/* Delete Confirm Modal */}
                    {deleteModal.open && (
                        <motion.div 
                            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} 
                            className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
                        >
                            <motion.div 
                                initial={{ scale: 0.95, opacity: 0, y: 20 }} animate={{ scale: 1, opacity: 1, y: 0 }} exit={{ scale: 0.95, opacity: 0, y: 20 }}
                                className="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 text-center border border-slate-100 dark:border-slate-800"
                            >
                                <div className="w-16 h-16 bg-red-50 dark:bg-red-900/20 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <span className="material-symbols-rounded text-3xl">delete_forever</span>
                                </div>
                                <h3 className="text-lg font-bold text-gray-800 dark:text-white mb-2">Hapus Grup?</h3>
                                <p className="text-sm text-gray-500 dark:text-slate-400 mb-6">
                                    Grup sinkronisasi ini akan dihapus permanen. Stok di marketplace tidak akan terpengaruh.
                                </p>
                                <div className="flex gap-3">
                                    <button onClick={() => setDeleteModal({ open: false, id: null })} className="flex-1 py-2.5 text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 rounded-xl transition">Batal</button>
                                    <button onClick={handleDelete} className="flex-1 py-2.5 text-sm font-bold text-white bg-red-600 hover:bg-red-700 rounded-xl transition shadow-lg shadow-red-500/30">Hapus</button>
                                </div>
                            </motion.div>
                        </motion.div>
                    )}

                    {/* Edit Stock Modal */}
                    {editStockModal.open && (
                        <motion.div 
                            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} 
                            className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
                        >
                            <motion.div 
                                initial={{ scale: 0.95, opacity: 0, y: 20 }} animate={{ scale: 1, opacity: 1, y: 0 }} exit={{ scale: 0.95, opacity: 0, y: 20 }}
                                className="bg-white dark:bg-slate-900 w-full max-w-sm rounded-3xl shadow-2xl p-6 border border-slate-100 dark:border-slate-800"
                            >
                                <h3 className="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
                                    <span className="material-symbols-rounded text-[#304674] dark:text-blue-400">edit_square</span>
                                    Edit Master Stock
                                </h3>
                                <div className="mb-6">
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Master Stock Baru</label>
                                    <input
                                        type="number"
                                        value={editStockModal.stock}
                                        onChange={(e) => setEditStockModal(prev => ({ ...prev, stock: parseInt(e.target.value) || 0 }))}
                                        className="w-full px-4 py-2 border border-gray-200 dark:border-slate-700 rounded-xl bg-gray-50 dark:bg-slate-800 text-gray-800 dark:text-white focus:ring-[#304674]"
                                    />
                                    <p className="text-xs text-gray-500 mt-2">Stok ini akan otomatis di-push ke seluruh marketplace yang tergabung di grup ini.</p>
                                </div>
                                <div className="flex justify-end gap-3">
                                    <button onClick={() => setEditStockModal({ open: false, id: null, stock: 0 })} className="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 rounded-xl transition">Batal</button>
                                    <button onClick={handleEditStock} className="px-4 py-2 text-sm font-bold text-white bg-[#304674] hover:bg-[#243558] rounded-xl transition shadow-lg shadow-blue-900/20">Simpan & Push</button>
                                </div>
                            </motion.div>
                        </motion.div>
                    )}

                    {/* Success Modal */}
                    {successModal.open && (
                        <motion.div 
                            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} 
                            className="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
                        >
                            <motion.div 
                                initial={{ scale: 0.8, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.8, opacity: 0 }}
                                className="bg-white dark:bg-slate-900 w-full max-w-xs rounded-3xl shadow-2xl p-6 text-center border border-slate-100 dark:border-slate-800"
                            >
                                <div className="w-16 h-16 bg-green-50 dark:bg-green-900/20 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <span className="material-symbols-rounded text-3xl">check</span>
                                </div>
                                <h3 className="text-lg font-bold text-gray-800 dark:text-white mb-2">Berhasil!</h3>
                                <p className="text-sm text-gray-500 dark:text-slate-400 mb-6">
                                    {successModal.message}
                                </p>
                                <button onClick={() => setSuccessModal({ open: false, message: "" })} className="w-full py-2.5 text-sm font-bold text-white bg-green-500 hover:bg-green-600 rounded-xl transition shadow-lg shadow-green-500/30">Tutup</button>
                            </motion.div>
                        </motion.div>
                    )}
                </AnimatePresence>
            , document.body)}
        </div>
    );
}
