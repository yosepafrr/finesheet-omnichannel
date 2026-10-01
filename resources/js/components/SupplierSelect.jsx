import { Building2, Check, ChevronDown, Plus, Search } from "lucide-react";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";

export default function SupplierSelect({
    value = "",
    onChange,
    suppliers = [],
    defaultLabel = "Belum ditentukan",
    includeUnassigned = false,
    onManageSuppliers,
    disabled = false,
    buttonClassName = "",
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState("");
    const [position, setPosition] = useState(null);
    const buttonRef = useRef(null);
    const panelRef = useRef(null);

    const normalizedValue = String(value ?? "");
    const selectedSupplier = suppliers.find((supplier) => String(supplier.id) === normalizedValue);
    const selectedLabel = normalizedValue === "unassigned"
        ? "Belum ditentukan"
        : selectedSupplier?.name || defaultLabel;

    const filteredSuppliers = useMemo(() => {
        const query = search.trim().toLowerCase();
        if (!query) return suppliers;

        return suppliers.filter((supplier) => supplier.name?.toLowerCase().includes(query));
    }, [search, suppliers]);

    const updatePosition = () => {
        if (!buttonRef.current) return;

        const rect = buttonRef.current.getBoundingClientRect();
        const viewportPadding = 8;
        const preferredWidth = Math.max(rect.width, 280);
        const width = Math.min(preferredWidth, window.innerWidth - (viewportPadding * 2));
        const left = Math.min(
            Math.max(viewportPadding, rect.left),
            window.innerWidth - width - viewportPadding,
        );
        const spaceBelow = window.innerHeight - rect.bottom;
        const spaceAbove = rect.top;
        const opensUpward = spaceBelow < 300 && spaceAbove > spaceBelow;

        setPosition({
            left,
            width,
            top: opensUpward ? undefined : rect.bottom + 6,
            bottom: opensUpward ? window.innerHeight - rect.top + 6 : undefined,
            maxHeight: Math.max(180, Math.min(320, (opensUpward ? spaceAbove : spaceBelow) - 16)),
        });
    };

    useEffect(() => {
        if (!open) return undefined;

        updatePosition();
        const handlePointerDown = (event) => {
            if (
                !buttonRef.current?.contains(event.target)
                && !panelRef.current?.contains(event.target)
            ) {
                setOpen(false);
            }
        };
        const handleEscape = (event) => {
            if (event.key === "Escape") setOpen(false);
        };
        const closeOnScroll = (event) => {
            if (panelRef.current?.contains(event.target)) return;
            setOpen(false);
        };

        document.addEventListener("mousedown", handlePointerDown);
        document.addEventListener("keydown", handleEscape);
        window.addEventListener("resize", updatePosition);
        window.addEventListener("scroll", closeOnScroll, true);

        return () => {
            document.removeEventListener("mousedown", handlePointerDown);
            document.removeEventListener("keydown", handleEscape);
            window.removeEventListener("resize", updatePosition);
            window.removeEventListener("scroll", closeOnScroll, true);
        };
    }, [open]);

    useEffect(() => {
        if (!open) setSearch("");
    }, [open]);

    useEffect(() => {
        if (
            normalizedValue
            && normalizedValue !== "unassigned"
            && !suppliers.some((supplier) => String(supplier.id) === normalizedValue)
        ) {
            onChange("");
        }
    }, [normalizedValue, onChange, suppliers]);

    const selectValue = (nextValue) => {
        onChange(nextValue);
        setOpen(false);
    };

    return (
        <div className="min-w-0">
            <button
                ref={buttonRef}
                type="button"
                disabled={disabled}
                onClick={() => setOpen((current) => !current)}
                className={`flex h-10 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 text-left text-sm text-slate-700 shadow-sm transition-colors hover:border-[#304674] focus:outline-none focus:ring-2 focus:ring-[#304674]/20 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 ${buttonClassName}`}
                aria-expanded={open}
                aria-haspopup="listbox"
            >
                <span className="flex min-w-0 items-center gap-2">
                    <Building2 className="h-4 w-4 shrink-0 text-[#304674] dark:text-blue-300" />
                    <span className="truncate font-medium">{selectedLabel}</span>
                </span>
                <ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? "rotate-180" : ""}`} />
            </button>

            {open && position && createPortal(
                <div
                    ref={panelRef}
                    className="fixed z-[10020] flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                    style={{
                        left: position.left,
                        width: position.width,
                        top: position.top,
                        bottom: position.bottom,
                        maxHeight: position.maxHeight,
                    }}
                    role="listbox"
                >
                    <div className="border-b border-slate-100 p-2 dark:border-slate-800">
                        <div className="flex h-9 items-center gap-2 rounded-lg bg-slate-50 px-3 text-slate-500 ring-1 ring-inset ring-slate-200 focus-within:ring-[#304674] dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700">
                            <Search className="h-4 w-4 shrink-0" />
                            <input
                                autoFocus
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Cari supplier..."
                                className="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-800 placeholder:text-slate-400 focus:ring-0 dark:text-white"
                            />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto p-1.5">
                        <button
                            type="button"
                            onClick={() => selectValue("")}
                            className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors ${normalizedValue === "" ? "bg-blue-50 font-semibold text-[#304674] dark:bg-blue-500/10 dark:text-blue-300" : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"}`}
                            role="option"
                            aria-selected={normalizedValue === ""}
                        >
                            <span className="truncate">{defaultLabel}</span>
                            {normalizedValue === "" && <Check className="h-4 w-4 shrink-0" />}
                        </button>

                        {includeUnassigned && (
                            <button
                                type="button"
                                onClick={() => selectValue("unassigned")}
                                className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors ${normalizedValue === "unassigned" ? "bg-blue-50 font-semibold text-[#304674] dark:bg-blue-500/10 dark:text-blue-300" : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"}`}
                                role="option"
                                aria-selected={normalizedValue === "unassigned"}
                            >
                                <span>Belum ditentukan</span>
                                {normalizedValue === "unassigned" && <Check className="h-4 w-4 shrink-0" />}
                            </button>
                        )}

                        {filteredSuppliers.map((supplier) => {
                            const active = String(supplier.id) === normalizedValue;
                            return (
                                <button
                                    key={supplier.id}
                                    type="button"
                                    onClick={() => selectValue(String(supplier.id))}
                                    className={`flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-left text-sm transition-colors ${active ? "bg-blue-50 font-semibold text-[#304674] dark:bg-blue-500/10 dark:text-blue-300" : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"}`}
                                    role="option"
                                    aria-selected={active}
                                >
                                    <span className="truncate">{supplier.name}</span>
                                    {active && <Check className="h-4 w-4 shrink-0" />}
                                </button>
                            );
                        })}

                        {filteredSuppliers.length === 0 && search && (
                            <p className="px-3 py-4 text-center text-xs text-slate-400">Supplier tidak ditemukan</p>
                        )}
                    </div>

                    {onManageSuppliers && (
                        <div className="border-t border-slate-100 p-1.5 dark:border-slate-800">
                            <button
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    onManageSuppliers();
                                }}
                                className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold text-[#304674] transition-colors hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-500/10"
                            >
                                <Plus className="h-4 w-4" />
                                Tambah atau kelola supplier
                            </button>
                        </div>
                    )}
                </div>,
                document.body,
            )}
        </div>
    );
}
