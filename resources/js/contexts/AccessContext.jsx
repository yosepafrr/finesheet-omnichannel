import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import axios from "axios";

const AccessContext = createContext(null);

export const PAGE_PERMISSIONS = {
    "/dashboard": "dashboard.view",
    "/stores": "stores.view",
    "/products": "products.view",
    "/stock-management": "products.view",
    "/shopee-boost": "products.boost.manage",
    "/orders": "orders.view",
    "/fulfillment-settings": "fulfillment.settings.manage",
    "/profit-tracker": "finance.profit.view",
    "/payable": "finance.payable.manage",
    "/balance-sheet": "finance.cashflow.manage",
    "/cashflow": "finance.cashflow.manage",
};

const ROUTE_PRIORITY = {
    order_operations: ["/orders", "/fulfillment-settings", "/stores"],
    product_operations: ["/products", "/stock-management", "/stores"],
    finance_operations: ["/profit-tracker", "/payable", "/balance-sheet", "/cashflow", "/orders"],
    read_only: ["/dashboard", "/orders", "/products", "/stock-management", "/stores"],
    custom: ["/dashboard", "/orders", "/products", "/stock-management", "/stores", "/profit-tracker", "/payable", "/balance-sheet", "/cashflow"],
};

export function getDefaultAccessRoute(access = {}) {
    if (access.is_owner_mode) return "/dashboard";

    const permissions = new Set(access.permissions || []);
    const layout = access.active_profile?.layout || "custom";
    const preferredRoutes = ROUTE_PRIORITY[layout] || ROUTE_PRIORITY.custom;
    const remainingRoutes = Object.keys(PAGE_PERMISSIONS).filter((route) => !preferredRoutes.includes(route));

    return [...preferredRoutes, ...remainingRoutes].find((route) => permissions.has(PAGE_PERMISSIONS[route])) || "/profile";
}

export function AccessProvider({ children }) {
    const [context, setContext] = useState({
        is_owner_mode: !window.authUser?.id,
        active_profile: null,
        permissions: [],
        available_profiles: [],
    });
    const [loading, setLoading] = useState(Boolean(window.authUser?.id));

    const refresh = useCallback(async () => {
        if (!window.authUser?.id) {
            setLoading(false);
            return;
        }

        setLoading(true);
        try {
            const response = await axios.get("/api/access/context");
            setContext(response.data);
            return response.data;
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        refresh().catch(() => {});
    }, [refresh]);

    const value = useMemo(() => ({
        ...context,
        loading,
        refresh,
        homeRoute: getDefaultAccessRoute(context),
        can: (permission) => !loading && (context.is_owner_mode || context.permissions.includes(permission)),
    }), [context, loading, refresh]);

    return <AccessContext.Provider value={value}>{children}</AccessContext.Provider>;
}

export function useAccess() {
    const context = useContext(AccessContext);
    if (!context) throw new Error("useAccess must be used inside AccessProvider");
    return context;
}
