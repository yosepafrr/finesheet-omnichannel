import { useEffect, useState } from "react";

// Hook to get current hash route
function useCurrentRoute() {
    const [route, setRoute] = useState(window.location.hash.slice(1) || '/dashboard');

    useEffect(() => {
        const handleHashChange = () => {
            setRoute(window.location.hash.slice(1) || '/dashboard');
        };
        window.addEventListener('hashchange', handleHashChange);
        return () => window.removeEventListener('hashchange', handleHashChange);
    }, []);

    return route;
}

export default function Sidebar({ isMobileOpen, closeMobile, user = { name: "Loading..." } }) {
    const currentRoute = useCurrentRoute();

    const [sidebarCollapsed, setSidebarCollapsed] = useState(
        JSON.parse(localStorage.getItem("sidebarCollapsed")) || false,
    );

    const [openSubmenus, setOpenSubmenus] = useState(
        JSON.parse(localStorage.getItem("sidebarState")) || {},
    );

    useEffect(() => {
        localStorage.setItem(
            "sidebarCollapsed",
            JSON.stringify(sidebarCollapsed),
        );
    }, [sidebarCollapsed]);

    useEffect(() => {
        localStorage.setItem("sidebarState", JSON.stringify(openSubmenus));
    }, [openSubmenus]);

    const toggleSidebar = () => {
        setSidebarCollapsed(!sidebarCollapsed);
        if (!sidebarCollapsed) setOpenSubmenus({});
    };

    useEffect(() => {
        const handleResize = () => {
            if (window.innerWidth < 1024) {
                setSidebarCollapsed(false);
            }
        };
        handleResize();
        window.addEventListener("resize", handleResize);
        return () => window.removeEventListener("resize", handleResize);
    }, []);

    const toggleMenu = (menu) => {
        if (sidebarCollapsed) {
            setSidebarCollapsed(false);
            setTimeout(
                () =>
                    setOpenSubmenus((prev) => ({
                        ...prev,
                        [menu]: !prev[menu],
                    })),
                150,
            );
        } else {
            setOpenSubmenus((prev) => ({ ...prev, [menu]: !prev[menu] }));
        }
    };

    const isActive = (path) => currentRoute === path;
    const isSubmenuActive = (paths) => paths.some(p => currentRoute === p);

    const navItemClass = (path) =>
        `group flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-300 relative ${
            isActive(path)
                ? "bg-[#304674] text-white shadow-md shadow-[#304674]/20 dark:bg-blue-600 dark:shadow-blue-900/20"
                : "text-slate-600 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800/50 hover:text-[#304674] dark:hover:text-blue-400"
        }`;

    const submenuItemClass = (path) =>
        `px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-300 ${
            isActive(path)
                ? "bg-[#304674] text-white shadow-md shadow-[#304674]/20 dark:bg-blue-600 dark:shadow-blue-900/20"
                : "text-slate-500 hover:text-[#304674] hover:bg-gray-100 dark:hover:bg-slate-800/50 transition-colors"
        }`;

    const expandListClass = (menuKey, subPaths) =>
        `w-full group flex items-center justify-between px-3 py-2.5 rounded-xl transition-all duration-300 relative text-sm text-slate-600 dark:text-slate-400 hover:bg-gray-100 dark:hover:bg-slate-800/50 hover:text-[#304674] dark:hover:text-blue-400
        ${(openSubmenus[menuKey] || isSubmenuActive(subPaths)) ? "bg-slate-50 dark:bg-slate-800/30 text-[#304674] dark:text-blue-400 font-medium" : "font-medium"}`;

    const renderTooltip = (text) => {
        if (!sidebarCollapsed) return null;
        return (
            <div className="fixed ml-[60px] px-2.5 py-1.5 bg-slate-800 dark:bg-slate-700 text-white text-xs font-medium rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all whitespace-nowrap z-[500] shadow-md border border-slate-700 pointer-events-none">
                {text}
            </div>
        );
    };

    const handleNav = (e, path) => {
        e.preventDefault();
        window.location.hash = '#' + path;
        if (isMobileOpen) closeMobile();
    };

    return (
        <>
            {/* MOBILE OVERLAY */}
            {isMobileOpen && (
                <div
                    onClick={closeMobile}
                    className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] lg:hidden transition-opacity"
                />
            )}

            {/* SIDEBAR */}
            <aside
                className={`flex-shrink-0 fixed inset-y-0 left-0 z-[70] flex flex-col h-full bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 shadow-2xl lg:shadow-none transition-all duration-300 ease-in-out lg:relative lg:z-[90] lg:translate-x-0
                ${isMobileOpen ? "translate-x-0" : "-translate-x-full"}
                ${sidebarCollapsed ? "w-[70px]" : "w-64"}`}
            >
                {/* MOBILE DRAWER HEADER */}
                <div className="flex items-center justify-between px-4 h-16 border-b border-slate-200 dark:border-slate-800 lg:hidden flex-shrink-0 bg-white dark:bg-slate-900">
                    <div className="flex items-center gap-2">
                        <span className="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                            <span className="font-normal">fine</span>sheet
                        </span>
                    </div>
                    <button
                        onClick={closeMobile}
                        className="p-2 -mr-2 rounded-xl text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors focus:outline-none active:scale-95"
                        aria-label="Tutup menu"
                    >
                        <span className="material-symbols-rounded text-2xl leading-none">close</span>
                    </button>
                </div>

                {/* COLLAPSE BUTTON */}
                <button
                    onClick={toggleSidebar}
                    className="hidden lg:flex absolute -right-4 top-1/2 w-10 h-10 aspect-square bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-full items-center justify-center text-slate-400 hover:text-[#304674] dark:hover:text-blue-400 hover:border-[#304674] shadow-md transition-all duration-300 z-50 focus:outline-none"
                >
                    <span
                        className={`material-symbols-rounded text-md transform transition-transform duration-300 origin-center ${sidebarCollapsed ? "rotate-180" : ""}`}
                    >
                        chevron_left
                    </span>
                </button>

                {/* NAVIGATION */}
                <div className="flex-1 px-3 py-4 lg:py-6 space-y-1.5 overflow-y-auto overflow-x-hidden hide-scrollbar">
                    <a href="#/dashboard" onClick={(e) => handleNav(e, '/dashboard')} className={navItemClass('/dashboard')}>
                        <span className="material-symbols-rounded text-xl">
                            space_dashboard
                        </span>
                        {!sidebarCollapsed && (
                            <span className="font-medium whitespace-nowrap text-sm">
                                Dashboard
                            </span>
                        )}
                        {renderTooltip("Dashboard")}
                    </a>

                    <a href="#/stores" onClick={(e) => handleNav(e, '/stores')} className={navItemClass('/stores')}>
                        <span className="material-symbols-rounded text-xl shrink-0">
                            store
                        </span>
                        {!sidebarCollapsed && (
                            <span className="font-medium whitespace-nowrap text-sm">
                                Your Stores
                            </span>
                        )}
                        {renderTooltip("Your Stores")}
                    </a>

                    <a href="#/products" onClick={(e) => handleNav(e, '/products')} className={navItemClass('/products')}>
                        <span className="material-symbols-rounded text-xl shrink-0">
                            apparel
                        </span>
                        {!sidebarCollapsed && (
                            <span className="font-medium whitespace-nowrap text-sm">
                                Your Products
                            </span>
                        )}
                        {renderTooltip("Your Products")}
                    </a>

                    <a href="#/orders" onClick={(e) => handleNav(e, '/orders')} className={navItemClass('/orders')}>
                        <span className="material-symbols-rounded text-xl shrink-0">
                            shopping_cart
                        </span>
                        {!sidebarCollapsed && (
                            <span className="font-medium whitespace-nowrap text-sm">
                                Order List
                            </span>
                        )}
                        {renderTooltip("Order List")}
                    </a>

                    <div className="my-4 border-t border-slate-100 dark:border-slate-800 mx-2"></div>

                    {/* FINANCE */}
                    <div>
                        <button
                            onClick={() => toggleMenu("finance")}
                            className={expandListClass("finance", ['/profit-tracker', '/cashflow', '/payable'])}
                        >
                            <div className="flex items-center gap-3">
                                <span className="material-symbols-rounded text-xl shrink-0">
                                    finance_mode
                                </span>
                                {!sidebarCollapsed && (
                                    <span className="whitespace-nowrap">
                                        Finance
                                    </span>
                                )}
                            </div>
                            {!sidebarCollapsed && (
                                <span
                                    className={`material-symbols-rounded text-lg transition-transform duration-300 ${openSubmenus.finance ? "rotate-180" : ""}`}
                                >
                                    expand_more
                                </span>
                            )}
                            {renderTooltip("Finance")}
                        </button>
                        {openSubmenus.finance && !sidebarCollapsed && (
                            <div className="flex flex-col gap-1 mt-1 ml-6 pl-4 border-l-2 border-gray-300 dark:border-slate-700 overflow-hidden">
                                <a
                                    href="#/profit-tracker"
                                    onClick={(e) => handleNav(e, '/profit-tracker')}
                                    className={submenuItemClass('/profit-tracker')}
                                >
                                    Profit Tracker
                                </a>
                                <a
                                    href="#/payable"
                                    onClick={(e) => handleNav(e, '/payable')}
                                    className={submenuItemClass('/payable')}
                                >
                                    Suppliers Transactions
                                </a>
                                <a
                                    href="#/cashflow"
                                    onClick={(e) => handleNav(e, '/cashflow')}
                                    className={submenuItemClass('/cashflow')}
                                >
                                    Cashflow
                                </a>
{/* 
                                <a
                                    href="#/ads"
                                    onClick={(e) => handleNav(e, '/ads')}
                                    className={submenuItemClass('/ads')}
                                >
                                    Ads
                                </a> */}
                            </div>
                        )}
                    </div>

                    {/* ORDERS */}
                    {/* <div>
                        <button
                            onClick={() => toggleMenu("orders")}
                            className={expandListClass("orders", ['/orders'])}
                        >
                            <div className="flex items-center gap-3">
                                <span className="material-symbols-rounded text-xl shrink-0">
                                    shopping_bag
                                </span>
                                {!sidebarCollapsed && (
                                    <span className="whitespace-nowrap">
                                        Orders
                                    </span>
                                )}
                            </div>
                            {!sidebarCollapsed && (
                                <span
                                    className={`material-symbols-rounded text-lg transition-transform duration-300 ${openSubmenus.orders ? "rotate-180" : ""}`}
                                >
                                    expand_more
                                </span>
                            )}
                            {renderTooltip("Orders")}
                        </button>
                        {openSubmenus.orders && !sidebarCollapsed && (
                            <div className="flex flex-col gap-1 mt-1 ml-6 pl-4 border-l-2 border-gray-300 dark:border-slate-700 overflow-hidden">
                                <a
                                    href="#/orders"
                                    onClick={(e) => handleNav(e, '/orders')}
                                    className={submenuItemClass('/orders')}
                                >
                                    Order List
                                </a>
                            </div>
                        )}
                    </div> */}
                </div>

            </aside>
        </>
    );
}
