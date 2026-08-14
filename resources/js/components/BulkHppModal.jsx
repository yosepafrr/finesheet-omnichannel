import React, { useState, useEffect, useRef, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { motion, AnimatePresence } from 'framer-motion';
import axios from 'axios';
import HppEditor from './HppEditor';

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

export default function BulkHppModal({ isOpen, onClose, product, onSave }) {
    const [selectedVariants, setSelectedVariants] = useState([]);
    const [hppValue, setHppValue] = useState("");
    const [isDropdownOpen, setIsDropdownOpen] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const dropdownRef = useRef(null);

    // Reset state when modal opens for a new product
    useEffect(() => {
        if (isOpen && product) {
            setSelectedVariants(product.variants.map(v => v.id)); // Default select all
            setHppValue("");
            setIsDropdownOpen(false);
        }
    }, [isOpen, product?.id]);

    // Handle outside click for dropdown
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setIsDropdownOpen(false);
            }
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    const variants = product?.variants || [];
    const isAllSelected = selectedVariants.length === variants.length && variants.length > 0;

    const variantTiers = useMemo(() => {
        if (!product || !product.variants) return [];
        
        const allTiers = product.variants.map(v => {
            const nameStr = v.model_name || v.variant_name || "";
            return nameStr.split(/,|\s+-\s+/).map(s => s.trim());
        });
        
        const maxTiers = Math.max(...allTiers.map(t => t.length), 0);
        
        const tiers = [];
        for (let i = 0; i < maxTiers; i++) {
            const values = new Set();
            allTiers.forEach(t => {
                if (t[i]) values.add(t[i]);
            });
            tiers.push({
                name: `Variant ${i + 1}`,
                options: Array.from(values).filter(v => v !== "")
            });
        }
        
        return tiers;
    }, [product]);

    const handleToggleAll = () => {
        if (isAllSelected) {
            setSelectedVariants([]);
        } else {
            setSelectedVariants(variants.map(v => v.id));
        }
    };

    const handleToggleTierOption = (tierIndex, optionValue) => {
        const matchingVariants = variants.filter(v => {
            const nameStr = v.model_name || v.variant_name || "";
            const parts = nameStr.split(/,|\s+-\s+/).map(s => s.trim());
            return parts[tierIndex] === optionValue;
        }).map(v => v.id);

        const areAllSelected = matchingVariants.every(id => selectedVariants.includes(id));
        
        if (areAllSelected) {
            setSelectedVariants(prev => prev.filter(id => !matchingVariants.includes(id)));
        } else {
            setSelectedVariants(prev => {
                const newSelection = new Set([...prev, ...matchingVariants]);
                return Array.from(newSelection);
            });
        }
    };

    const handleApply = async () => {
        if (selectedVariants.length === 0) return;
        
        setIsSaving(true);
        try {
            await axios.put('/api/variants/bulk/hpp', { 
                variant_ids: selectedVariants, 
                hpp: Number(hppValue) 
            });
            onSave(); // Refresh data
            // Do not close modal automatically so user can see the updated table
            setHppValue("");
        } catch (err) {
            console.error("Failed to bulk update HPP", err);
        } finally {
            setIsSaving(false);
        }
    };

    return createPortal(
        <AnimatePresence>
            {isOpen && product && (
                <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                <motion.div
                    initial={{ opacity: 0, scale: 0.95 }}
                    animate={{ opacity: 1, scale: 1 }}
                    exit={{ opacity: 0, scale: 0.95 }}
                    className="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-4xl overflow-hidden flex flex-col max-h-[90vh]"
                >
                    {/* Header */}
                    <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 bg-gray-50/50 dark:bg-slate-900/30">
                        <h2 className="text-lg font-bold text-gray-800 dark:text-white">
                            Edit HPP Massal - {product.product_name}
                        </h2>
                        <button onClick={onClose} className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors">
                            <span className="material-symbols-rounded">close</span>
                        </button>
                    </div>

                    {/* Body */}
                    <div className="p-6 overflow-y-auto">
                        <div className="mb-4">
                            <h3 className="text-sm font-bold text-gray-700 dark:text-slate-300 mb-2">Daftar varian</h3>
                            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                                {/* Custom Dropdown */}
                                <div className="relative w-full sm:w-64" ref={dropdownRef}>
                                    <button 
                                        onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                                        className="w-full flex items-center justify-between bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-slate-300 focus:ring-2 focus:ring-[#304674] outline-none"
                                    >
                                        <span>
                                            {isAllSelected ? "Semua" : `${selectedVariants.length} varian terpilih`}
                                        </span>
                                        <span className="material-symbols-rounded text-gray-400 text-sm transition-transform" style={{ transform: isDropdownOpen ? 'rotate(180deg)' : 'none' }}>
                                            expand_more
                                        </span>
                                    </button>

                                    {/* Dropdown Menu */}
                                    {isDropdownOpen && (
                                        <div className="absolute top-full left-0 mt-1 w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg z-10 max-h-64 flex flex-col">
                                            <div className="px-4 py-3 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-900/50">
                                                <label className="flex items-center gap-3 cursor-pointer">
                                                    <input 
                                                        type="checkbox"
                                                        checked={isAllSelected}
                                                        onChange={handleToggleAll}
                                                        className="w-4 h-4 rounded border-gray-300 text-[#304674] focus:ring-[#304674]"
                                                    />
                                                    <span className="text-sm font-bold text-gray-700 dark:text-slate-300">Pilih Semua</span>
                                                </label>
                                            </div>
                                            <div className="py-2 px-1 overflow-y-auto">
                                                {variantTiers.map((tier, tIdx) => (
                                                    <div key={tIdx} className="mb-3 last:mb-0">
                                                        <div className="px-3 text-[11px] font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-1">{tier.name}</div>
                                                        {tier.options.map((opt, oIdx) => {
                                                            const matchingVariants = variants.filter(v => {
                                                                const nameStr = v.model_name || v.variant_name || "";
                                                                const parts = nameStr.split(/,|\s+-\s+/).map(s => s.trim());
                                                                return parts[tIdx] === opt;
                                                            }).map(v => v.id);
                                                            
                                                            const isChecked = matchingVariants.length > 0 && matchingVariants.every(id => selectedVariants.includes(id));
                                                            const isIndeterminate = matchingVariants.some(id => selectedVariants.includes(id)) && !isChecked;

                                                            return (
                                                                <label key={oIdx} className="flex items-center gap-3 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer rounded mx-1">
                                                                    <input 
                                                                        type="checkbox"
                                                                        checked={isChecked}
                                                                        ref={el => { if (el) el.indeterminate = isIndeterminate; }}
                                                                        onChange={() => handleToggleTierOption(tIdx, opt)}
                                                                        className="w-4 h-4 rounded border-gray-300 text-[#304674] focus:ring-[#304674]"
                                                                    />
                                                                    <span className="text-sm text-gray-700 dark:text-slate-300">{opt}</span>
                                                                </label>
                                                            );
                                                        })}
                                                    </div>
                                                ))}
                                            </div>
                                            <div className="px-4 py-2 border-t border-gray-100 dark:border-slate-700">
                                                <button 
                                                    onClick={() => setSelectedVariants([])}
                                                    className="text-xs font-medium text-[#304674] dark:text-blue-400 hover:underline"
                                                >
                                                    Reset opsi
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* HPP Input */}
                                <div className="relative w-full sm:w-48">
                                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">Rp</span>
                                    <input 
                                        type="number"
                                        value={hppValue}
                                        onChange={(e) => setHppValue(e.target.value)}
                                        placeholder="HPP"
                                        className="w-full pl-9 pr-4 py-2 text-sm bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-[#304674] outline-none text-gray-800 dark:text-white"
                                    />
                                </div>

                                {/* Apply Button */}
                                <button 
                                    onClick={handleApply}
                                    disabled={isSaving || selectedVariants.length === 0}
                                    className="w-full sm:w-auto px-6 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-800 dark:text-white text-sm font-bold rounded-lg transition-colors disabled:opacity-50 border border-gray-300 dark:border-slate-600"
                                >
                                    {isSaving ? "Menyimpan..." : "Terapkan"}
                                </button>
                            </div>
                        </div>

                        {/* Variants Table (Preview) */}
                        <div className="mt-6 border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="text-xs text-gray-500 dark:text-slate-400 bg-gray-50 dark:bg-slate-900/50 border-b border-gray-200 dark:border-slate-700">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Varian</th>
                                            <th className="px-4 py-3 font-medium text-left">Stok</th>
                                            <th className="px-4 py-3 font-medium text-left">Harga Jual</th>
                                            <th className="px-4 py-3 font-medium text-left w-52">HPP</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 dark:divide-slate-700/50 bg-white dark:bg-slate-800">
                                        {variants.map(variant => (
                                            <tr key={variant.id} className={selectedVariants.includes(variant.id) ? "bg-blue-50/30 dark:bg-blue-900/10" : ""}>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-3">
                                                        <div className="flex flex-col">
                                                            <span className="font-medium text-gray-700 dark:text-slate-300">{variant.variant_name || variant.model_name}</span>
                                                            <span className="text-[10px] text-gray-400 dark:text-slate-500 font-mono">{variant.model_sku}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="text-gray-600 dark:text-slate-400">{variant.stock}</span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="text-gray-600 dark:text-slate-400">{formatRp(variant.price)}</span>
                                                </td>
                                                <td className="px-4 py-2 text-left">
                                                    <HppEditor 
                                                        type="variant" 
                                                        id={variant.id} 
                                                        hpp={variant.hpp} 
                                                        onSave={onSave}
                                                        align="left"
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </motion.div>
                </div>
            )}
        </AnimatePresence>,
        document.body
    );
}
