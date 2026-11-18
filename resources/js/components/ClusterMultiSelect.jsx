import React, { useEffect, useRef, useState } from "react";

function SelectionCheckbox({ checked, indeterminate = false, onChange, label }) {
    const ref = useRef(null);

    useEffect(() => {
        if (ref.current) ref.current.indeterminate = indeterminate;
    }, [indeterminate]);

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            onChange={onChange}
            aria-label={label}
            className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[#304674] focus:ring-[#304674]"
        />
    );
}

export default function ClusterMultiSelect({ label, options, selected, onChange, placeholder }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);
    const allSelected = options.length > 0 && selected.length === options.length;

    useEffect(() => {
        const close = (event) => {
            if (!ref.current?.contains(event.target)) setOpen(false);
        };
        document.addEventListener("mousedown", close);
        return () => document.removeEventListener("mousedown", close);
    }, []);

    const toggle = (key) => onChange(
        selected.includes(key)
            ? selected.filter((item) => item !== key)
            : [...selected, key],
    );

    return (
        <div ref={ref} className="relative min-w-0">
            <span className="mb-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">{label}</span>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className={`flex h-10 w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 text-left text-sm shadow-sm transition-colors hover:border-[#304674] focus:outline-none focus:ring-2 focus:ring-[#304674]/20 dark:bg-slate-800 ${selected.length > 0 ? "border-[#304674] text-[#304674] dark:border-blue-500 dark:text-blue-300" : "border-slate-300 text-slate-700 dark:border-slate-600 dark:text-slate-200"}`}
                aria-expanded={open}
            >
                <span className="truncate">{selected.length > 0 ? `${selected.length} cluster dipilih` : placeholder}</span>
                <span className={`material-symbols-rounded text-lg transition-transform ${open ? "rotate-180" : ""}`}>expand_more</span>
            </button>
            {open && (
                <div className="absolute left-0 top-full z-40 mt-1 w-full min-w-64 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-800">
                    <div className="border-b border-slate-100 p-2 dark:border-slate-700">
                        <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50 dark:text-slate-100 dark:hover:bg-slate-700">
                            <SelectionCheckbox
                                checked={allSelected}
                                indeterminate={selected.length > 0 && !allSelected}
                                onChange={() => onChange(allSelected ? [] : options.map((option) => option.key))}
                                label={`Pilih semua ${label.toLowerCase()}`}
                            />
                            Pilih semua
                        </label>
                    </div>
                    <div className="max-h-64 overflow-y-auto p-2">
                        {options.length === 0 ? (
                            <p className="px-2 py-3 text-sm text-slate-400">Belum ada cluster.</p>
                        ) : options.map((option) => (
                            <label key={option.key} className="flex cursor-pointer items-center justify-between gap-3 rounded-md px-2 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">
                                <span className="flex min-w-0 items-center gap-2">
                                    <SelectionCheckbox checked={selected.includes(option.key)} onChange={() => toggle(option.key)} label={`Pilih ${option.label}`} />
                                    <span className="truncate font-medium">{option.label}</span>
                                </span>
                                <span className="text-xs text-slate-400">{option.count}</span>
                            </label>
                        ))}
                    </div>
                    {selected.length > 0 && (
                        <button type="button" onClick={() => onChange([])} className="w-full border-t border-slate-100 px-4 py-2.5 text-left text-xs font-semibold text-[#304674] hover:bg-slate-50 dark:border-slate-700 dark:text-blue-300 dark:hover:bg-slate-700">
                            Reset opsi
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
