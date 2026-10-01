import { useCallback, useEffect, useMemo, useState } from "react";
import axios from "axios";

const ACTIVE_POLL_INTERVAL = 3000;
const IDLE_POLL_INTERVAL = 15000;

const EMPTY_STATUS = {
    status: "idle",
    revision: 0,
    context: null,
    message: null,
    is_syncing: false,
};

export function usePayableSyncStatus() {
    const [syncStatus, setSyncStatus] = useState(EMPTY_STATUS);

    const refresh = useCallback(async () => {
        try {
            const response = await axios.get("/api/payable/sync-status");
            const nextStatus = response.data?.data || EMPTY_STATUS;
            setSyncStatus(nextStatus);
            return nextStatus;
        } catch (error) {
            console.error("Failed to read payable sync status", error);
            return null;
        }
    }, []);

    const applyStatus = useCallback((nextStatus) => {
        if (nextStatus) setSyncStatus(nextStatus);
    }, []);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const isSyncing = useMemo(
        () => syncStatus.is_syncing || ["queued", "running"].includes(syncStatus.status),
        [syncStatus],
    );

    useEffect(() => {
        const interval = setInterval(
            refresh,
            isSyncing ? ACTIVE_POLL_INTERVAL : IDLE_POLL_INTERVAL,
        );

        return () => clearInterval(interval);
    }, [isSyncing, refresh]);

    useEffect(() => {
        const handleVisibility = () => {
            if (document.visibilityState === "visible") refresh();
        };

        document.addEventListener("visibilitychange", handleVisibility);
        return () => document.removeEventListener("visibilitychange", handleVisibility);
    }, [refresh]);

    return { syncStatus, isSyncing, refresh, applyStatus };
}
