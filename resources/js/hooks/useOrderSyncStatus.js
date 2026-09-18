import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import axios from "axios";

const ACTIVE_POLL_INTERVAL = 3000;
const COMPLETED_VISIBILITY = 5000;

export function useOrderSyncStatus() {
    const [syncs, setSyncs] = useState([]);
    const [recentlyCompleted, setRecentlyCompleted] = useState(false);
    const hadActiveSyncRef = useRef(false);
    const completionTimerRef = useRef(null);

    const refresh = useCallback(async () => {
        try {
            const response = await axios.get("/api/sync/orders/status");
            const nextSyncs = response.data?.syncs || [];
            const hasActiveSync = nextSyncs.length > 0;

            if (hadActiveSyncRef.current && !hasActiveSync) {
                setRecentlyCompleted(true);
                clearTimeout(completionTimerRef.current);
                completionTimerRef.current = setTimeout(
                    () => setRecentlyCompleted(false),
                    COMPLETED_VISIBILITY,
                );
            }

            if (hasActiveSync) {
                setRecentlyCompleted(false);
            }

            hadActiveSyncRef.current = hasActiveSync;
            setSyncs(nextSyncs);

            return nextSyncs;
        } catch (error) {
            console.error("Failed to read order sync status", error);
            return null;
        }
    }, []);

    useEffect(() => {
        refresh();

        return () => clearTimeout(completionTimerRef.current);
    }, [refresh]);

    const isAnySyncing = syncs.length > 0;

    useEffect(() => {
        if (!isAnySyncing) return undefined;

        const interval = setInterval(refresh, ACTIVE_POLL_INTERVAL);
        return () => clearInterval(interval);
    }, [isAnySyncing, refresh]);

    const syncsByStore = useMemo(
        () => Object.fromEntries(syncs.map((sync) => [String(sync.store_id), sync])),
        [syncs],
    );

    return {
        syncs,
        syncsByStore,
        isAnySyncing,
        recentlyCompleted,
        refresh,
    };
}
