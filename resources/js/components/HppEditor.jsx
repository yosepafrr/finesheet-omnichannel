import React, { useState } from 'react';
import axios from 'axios';

function formatRp(n) {
    return "Rp " + Number(n || 0).toLocaleString("id-ID");
}

export default function HppEditor({ type, id, hpp, onSave, align = "right" }) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(hpp || 0);
    const [saving, setSaving] = useState(false);

    const handleSave = async () => {
        setSaving(true);
        try {
            const endpoint = type === "variant"
                ? `/api/variants/${id}/hpp`
                : `/api/products/${id}/hpp`;
            await axios.put(endpoint, { hpp: Number(value) });
            onSave?.(Number(value));
            setEditing(false);
        } catch (err) {
            console.error(err);
        } finally {
            setSaving(false);
        }
    };

    const justifyClass = align === "left" ? "justify-start" : "justify-end";

    if (editing) {
        return (
            <div className={`flex items-center gap-1 w-full ${justifyClass}`}>
                <div className="relative w-32">
                    <span className="absolute left-2 top-1.5 text-xs text-gray-400">Rp</span>
                    <input type="number" value={value} onChange={(e) => setValue(e.target.value)}
                        className="w-full pl-7 pr-1 py-1 text-xs border border-[#304674] dark:border-blue-500 rounded-md bg-white dark:bg-slate-900 text-gray-800 dark:text-white" autoFocus />
                </div>
                <button onClick={handleSave} disabled={saving} className="p-1 bg-[#304674] dark:bg-blue-600 text-white rounded text-xs shrink-0">
                    <span className="material-symbols-rounded text-sm">check</span>
                </button>
                <button onClick={() => setEditing(false)} className="p-1 bg-gray-200 dark:bg-slate-700 text-gray-600 dark:text-slate-300 rounded shrink-0">
                    <span className="material-symbols-rounded text-sm">close</span>
                </button>
            </div>
        );
    }

    return (
        <div className={`flex items-center gap-2 w-full ${justifyClass}`}>
            <span className={`text-xs font-medium ${hpp ? "text-gray-600 dark:text-slate-300" : "text-gray-400 dark:text-slate-500 italic"}`}>
                {hpp ? formatRp(hpp) : "Belum diisi"}
            </span>
            <button onClick={() => { setValue(hpp || 0); setEditing(true); }}
                className="bg-gray-100 dark:bg-slate-700 p-1 rounded text-gray-400 dark:text-slate-400 hover:text-[#304674] dark:hover:text-blue-400 shrink-0">
                <span className="material-symbols-rounded text-sm">edit</span>
            </button>
        </div>
    );
}
