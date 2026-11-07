import { AnimatePresence, motion } from "framer-motion";
import { useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";

export default function FinesheetSelect({
    value,
    onChange,
    options = [],
    placeholder = "Pilih opsi",
    disabled = false,
    icon = "tune",
    className = "",
    menuClassName = "",
    ariaLabel,
    compact = false,
}) {
    const [open, setOpen] = useState(false);
    const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0, width: 0 });
    const buttonRef = useRef(null);
    const menuRef = useRef(null);
    const listboxId = useId();
    const selected = options.find((option) => String(option.value) === String(value));

    const updatePosition = () => {
        const rect = buttonRef.current?.getBoundingClientRect();
        if (!rect) return;

        const width = Math.max(rect.width, 220);
        const estimatedHeight = Math.min(240, Math.max(52, options.length * 48 + 12));
        const roomBelow = window.innerHeight - rect.bottom;
        const openAbove = roomBelow < estimatedHeight + 16 && rect.top > roomBelow;
        setMenuPosition({
            top: openAbove
                ? Math.max(8, rect.top - estimatedHeight - 6)
                : Math.min(rect.bottom + 6, window.innerHeight - estimatedHeight - 8),
            left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
            width,
        });
    };

    useEffect(() => {
        if (!open) return undefined;

        updatePosition();
        const closeOnOutside = (event) => {
            if (!menuRef.current?.contains(event.target) && !buttonRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === "Escape") {
                setOpen(false);
                buttonRef.current?.focus();
            }
        };
        const closeOnViewportChange = () => setOpen(false);

        document.addEventListener("mousedown", closeOnOutside);
        document.addEventListener("keydown", closeOnEscape);
        window.addEventListener("resize", closeOnViewportChange);
        window.addEventListener("scroll", closeOnViewportChange, true);

        return () => {
            document.removeEventListener("mousedown", closeOnOutside);
            document.removeEventListener("keydown", closeOnEscape);
            window.removeEventListener("resize", closeOnViewportChange);
            window.removeEventListener("scroll", closeOnViewportChange, true);
        };
    }, [open]);

    const selectOption = (option) => {
        if (option.disabled) return;
        onChange(option.value);
        setOpen(false);
        buttonRef.current?.focus();
    };

    return (
        <div className={`relative ${className}`}>
            <button
                ref={buttonRef}
                type="button"
                role="combobox"
                aria-label={ariaLabel || placeholder}
                aria-controls={listboxId}
                aria-expanded={open}
                aria-haspopup="listbox"
                disabled={disabled}
                onClick={() => setOpen((current) => !current)}
                onKeyDown={(event) => {
                    if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key) && !open) {
                        event.preventDefault();
                        setOpen(true);
                    }
                }}
                className={`fs-select-trigger ${compact ? "fs-select-trigger-compact" : ""} ${open ? "fs-select-trigger-open" : ""}`}
            >
                <span className="fs-select-icon" aria-hidden="true">
                    <span className="material-symbols-rounded text-[17px]">{icon}</span>
                </span>
                <span className={`min-w-0 flex-1 truncate text-left ${selected ? "text-slate-800 dark:text-slate-100" : "text-slate-400"}`}>
                    {selected?.label || placeholder}
                </span>
                <span className={`material-symbols-rounded text-[19px] text-slate-400 transition-transform duration-200 ${open ? "rotate-180 text-[#304674] dark:text-blue-300" : ""}`} aria-hidden="true">
                    expand_more
                </span>
            </button>

            {typeof document !== "undefined" && createPortal(
                <AnimatePresence>
                    {open && (
                        <motion.div
                            ref={menuRef}
                            id={listboxId}
                            role="listbox"
                            initial={{ opacity: 0, y: -6, scale: 0.985 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: -4, scale: 0.99 }}
                            transition={{ duration: 0.16, ease: [0.22, 1, 0.36, 1] }}
                            style={{ top: menuPosition.top, left: menuPosition.left, width: menuPosition.width }}
                            className={`fixed z-[700] max-h-60 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-[0_18px_48px_rgba(15,23,42,0.18)] dark:border-slate-600 dark:bg-slate-800 ${menuClassName}`}
                        >
                            {options.length === 0 && (
                                <p className="px-3 py-2 text-sm text-slate-400">Belum ada pilihan tersedia.</p>
                            )}
                            {options.map((option) => {
                                const isSelected = String(option.value) === String(value);
                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        role="option"
                                        aria-selected={isSelected}
                                        disabled={option.disabled}
                                        onClick={() => selectOption(option)}
                                        className={`group flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition ${isSelected ? "bg-[#304674]/8 font-semibold text-[#304674] dark:bg-blue-400/15 dark:text-blue-200" : "text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700/70"}`}
                                    >
                                        <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition ${isSelected ? "bg-[#304674] text-white" : "bg-slate-100 text-slate-400 group-hover:bg-[#304674]/10 group-hover:text-[#304674] dark:bg-slate-700 dark:text-slate-300"}`}>
                                            <span className="material-symbols-rounded text-[16px]">{isSelected ? "check" : (option.icon || icon)}</span>
                                        </span>
                                        <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                    </button>
                                );
                            })}
                        </motion.div>
                    )}
                </AnimatePresence>,
                document.body,
            )}
        </div>
    );
}
