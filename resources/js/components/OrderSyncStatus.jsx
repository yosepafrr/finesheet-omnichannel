import { AnimatePresence, motion } from "framer-motion";
import { CheckCircle2, LoaderCircle } from "lucide-react";

export function OrderSyncStatus({ syncs, recentlyCompleted, className = "" }) {
    const isActive = syncs.length > 0;
    const isInitialSync = syncs.some((sync) => sync.context === "initial");
    const isLogisticsPhase = isActive && syncs.every((sync) => sync.phase === "logistics");
    const runningCount = syncs.filter((sync) => sync.status === "running").length;
    const storeLabel = syncs.length === 1
        ? syncs[0].store_name
        : `${syncs.length} toko`;

    return (
        <AnimatePresence initial={false}>
            {(isActive || recentlyCompleted) && (
                <motion.div
                    key={isActive ? "active" : "completed"}
                    initial={{ opacity: 0, y: -4 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -4 }}
                    transition={{ duration: 0.2 }}
                    className={`relative overflow-hidden rounded-lg border px-3.5 py-2.5 ${
                        isActive
                            ? "border-blue-200/80 bg-blue-50/70 dark:border-blue-500/20 dark:bg-blue-500/10"
                            : "border-emerald-200/80 bg-emerald-50/70 dark:border-emerald-500/20 dark:bg-emerald-500/10"
                    } ${className}`}
                    role="status"
                    aria-live="polite"
                >
                    <div className="flex min-w-0 items-center gap-3">
                        {isActive ? (
                            <LoaderCircle className="h-4 w-4 shrink-0 animate-spin text-[#304674] dark:text-blue-400" />
                        ) : (
                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        )}
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-xs font-semibold text-slate-700 dark:text-slate-200">
                                {isActive
                                    ? (isLogisticsPhase
                                        ? "Memperbarui status pengiriman"
                                        : (isInitialSync ? "Menarik pesanan pertama" : "Sinkronisasi pesanan berjalan"))
                                    : "Sinkronisasi pesanan dan logistik selesai"}
                            </p>
                            <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">
                                {isActive
                                    ? (isLogisticsPhase
                                        ? `${storeLabel} ${runningCount > 0 ? "sedang memeriksa tracking" : "menunggu pemeriksaan tracking"}. Badge pengiriman akan diperbarui otomatis.`
                                        : `${storeLabel} ${runningCount > 0 ? "sedang diproses" : "menunggu antrean"}. Data akan muncul bertahap.`)
                                    : "Data pesanan terbaru sudah dimuat."}
                            </p>
                        </div>
                        {isActive && (
                            <span className="hidden shrink-0 text-[10px] font-medium text-slate-500 dark:text-slate-400 sm:inline">
                                Berjalan di latar belakang
                            </span>
                        )}
                    </div>

                    {isActive && (
                        <div className="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden bg-blue-100 dark:bg-blue-950/50">
                            <motion.div
                                className="h-full w-1/3 bg-[#304674]/70 dark:bg-blue-400/80"
                                animate={{ x: ["-100%", "300%"] }}
                                transition={{ duration: 1.8, repeat: Infinity, ease: "easeInOut" }}
                            />
                        </div>
                    )}
                </motion.div>
            )}
        </AnimatePresence>
    );
}

export function StoreOrderSyncStatus({ sync }) {
    if (!sync) return null;

    return (
        <span className="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium text-[#304674] dark:text-blue-400">
            <LoaderCircle className="h-3 w-3 shrink-0 animate-spin" />
            {sync.phase === "logistics"
                ? "Memperbarui status pengiriman..."
                : (sync.context === "initial" ? "Menarik pesanan pertama..." : "Menyinkronkan pesanan...")}
        </span>
    );
}
