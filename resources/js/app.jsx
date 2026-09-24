import '../css/app.css';
import './bootstrap';
import React, { useState, useEffect, lazy, Suspense } from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import axios from "axios";
import { BellRing, Store, Trash2, X } from "lucide-react";

// Global axios config for handling 401 Unauthorized
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response && error.response.status === 401) {
            const currentRoute = (window.location.hash.slice(1) || '/').split('?')[0];
            const publicRoutes = ['/', '', '/login', '/register', '/meet-the-creators'];
            if (!publicRoutes.includes(currentRoute)) {
                window.location.hash = '#/login';
            }
        }
        return Promise.reject(error);
    }
);

// Lazy load pages
const LandingPage = lazy(() => import('./pages/LandingPage'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const StoreList = lazy(() => import('./pages/StoreList'));
const ProductList = lazy(() => import('./pages/ProductList'));
const OrderList = lazy(() => import('./pages/OrderList'));
const ProfitTracker = lazy(() => import('./pages/ProfitTracker'));
const PayableRekap = lazy(() => import('./pages/PayableRekap'));
const Cashflow = lazy(() => import('./pages/Cashflow'));
const MeetCreators = lazy(() => import('./pages/MeetCreators'));
const Profile = lazy(() => import('./pages/Profile'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const MasterProductDetail = lazy(() => import('./pages/MasterProductDetail'));
const Login = lazy(() => import('./pages/auth/Login'));
const Register = lazy(() => import('./pages/auth/Register'));

// Route config
const routes = {
    '/': LandingPage,
    '/dashboard': Dashboard,
    '/stores': StoreList,
    '/products': ProductList,
    '/orders': OrderList,
    '/profit-tracker': ProfitTracker,
    '/payable': PayableRekap,
    '/cashflow': Cashflow,
    '/meet-the-creators': MeetCreators,
    '/profile': Profile,
    '/login': Login,
    '/register': Register,
};

function GlobalBanner() {
    const [disconnectedStores, setDisconnectedStores] = useState(false);
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        axios.get('/api/stores')
        .then(res => {
            const data = res.data;
            if (Array.isArray(data)) {
                const hasDisconnected = data.some(s => !s.is_active);
                setDisconnectedStores(hasDisconnected);
            }
        })
        .catch(() => {});
    }, []);

    if (!disconnectedStores || !visible) return null;

    return (
        <div className="fixed top-4 left-1/2 -translate-x-1/2 z-[9999] w-[90%] max-w-2xl animate-fade-in-down">
            <div className="bg-rose-500 text-white px-4 py-3 rounded-2xl shadow-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div className="flex-1 flex items-center gap-3 w-full">
                    <div className="bg-rose-600/50 p-2 rounded-xl flex-shrink-0">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <p className="font-medium text-sm leading-snug">
                        Koneksi ke salah satu toko Anda terputus. Harap lakukan otorisasi ulang agar sinkronisasi dapat berjalan lancar.
                    </p>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0 w-full sm:w-auto justify-end">
                    <button 
                        onClick={() => setVisible(false)}
                        className="text-white hover:bg-rose-600 px-3 py-2 rounded-xl text-sm font-medium transition-colors"
                    >
                        Nanti Saja
                    </button>
                    <button 
                        onClick={() => { setVisible(false); window.location.hash = '#/stores'; }}
                        className="bg-white text-rose-600 px-4 py-2 rounded-xl text-sm font-bold shadow-sm hover:bg-rose-50 transition-colors"
                    >
                        Otorisasi Sekarang
                    </button>
                </div>
            </div>
            <style>{`
                @keyframes fadeInDown {
                    from { opacity: 0; transform: translate(-50%, -20px); }
                    to { opacity: 1; transform: translate(-50%, 0); }
                }
                .animate-fade-in-down {
                    animation: fadeInDown 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                }
            `}</style>
        </div>
    );
}

// Page loading skeleton
function PageLoader() {
    return (
        <div className="flex items-center justify-center h-full min-h-[60vh]">
            <div className="flex flex-col items-center gap-4">
                <div className="w-10 h-10 border-4 border-slate-200 dark:border-slate-700 border-t-[#304674] dark:border-t-blue-500 rounded-full animate-spin"></div>
                <p className="text-sm text-slate-500 dark:text-slate-400 font-medium animate-pulse">Loading...</p>
            </div>
        </div>
    );
}

// Simple hash router hook
function useHashRouter() {
    const routeFromHash = () => {
        const rawHash = window.location.hash.slice(1);
        return (rawHash || '/').split('?')[0] || '/';
    };

    const [hash, setHash] = useState(() => {
        let currentHash = window.location.hash.slice(1);
        if (!currentHash && window.location.pathname && window.location.pathname !== '/') {
            currentHash = window.location.pathname + window.location.search;
            // Normalize path to hash routing
            window.history.replaceState(null, '', '/#' + currentHash);
        }
        return (currentHash || '/').split('?')[0] || '/';
    });

    useEffect(() => {
        const handleHashChange = () => {
            setHash(routeFromHash());
        };
        window.addEventListener('hashchange', handleHashChange);

        // Set initial hash to root if none
        if (!window.location.hash && window.location.pathname === '/') {
            window.location.replace('#/');
        }

        return () => window.removeEventListener('hashchange', handleHashChange);
    }, []);

    return hash;
}

// Export navigate helper for use in other components
export function navigate(path) {
    window.location.hash = '#' + path;
}

// Auth pages don't use AppLayout
const authRoutes = ['/login', '/register'];

import { Toaster, toast } from 'react-hot-toast';

let currentEchoUserId = null;

// Initialize Echo listeners for the authenticated user to prevent data & notification leakage
export function initEchoForUser(userId) {
    if (!window.Echo || !userId) return;
    
    // Already subscribed to this user
    if (currentEchoUserId === Number(userId)) {
        return;
    }

    // Always leave public channels and any previous user's private channels
    try {
        window.Echo.leave('orders');
        window.Echo.leave('payables');
        if (currentEchoUserId) {
            window.Echo.leave(`orders.${currentEchoUserId}`);
            window.Echo.leave(`payables.${currentEchoUserId}`);
        }
    } catch (e) {
        console.warn('Echo: cleanup error before subscribe', e);
    }

    currentEchoUserId = Number(userId);
    console.log(`Echo: Subscribing to private channels for user ${userId}...`);
    
    window.Echo.private(`orders.${userId}`)
        .listen('.OrderCreated', (e) => {
            // Defense-in-depth: Verify that event payload user_id matches the subscribed user
            if (e.user_id && Number(e.user_id) !== Number(userId)) {
                console.warn(`[Echo] Security check: Dropping notification belonging to user ${e.user_id} (active user: ${userId})`);
                return;
            }

            console.log('OrderCreated event received for user:', e);
            
            let soundUrl = '';
            const platformName = (e.platform || '').toLowerCase();
            if (platformName.includes('shopee')) {
                soundUrl = '/sound/shopee_sound.mp3';
            } else if (platformName.includes('tiktok')) {
                soundUrl = '/sound/tiktok_sound.mp3';
            }
            
            if (soundUrl) {
                const audio = new Audio(soundUrl);
                audio.play().catch(err => console.log('Auto-play sound failed:', err));
            }

            // Dispatch a global event so other components (like PayableRekap) can auto-refresh
            window.dispatchEvent(new CustomEvent('order-created', { detail: e }));
        })
        .listen('.OrderUpdated', (e) => {
            if (e.user_id && Number(e.user_id) !== Number(userId)) return;
            console.log('OrderUpdated event received for user:', e);
            window.dispatchEvent(new CustomEvent('order-updated', { detail: e }));
        });

    window.Echo.private(`payables.${userId}`)
        .listen('.PayableUpdated', (e) => {
            if (e.user_id && Number(e.user_id) !== Number(userId)) return;
            console.log('PayableUpdated event received for user:', e);
            window.dispatchEvent(new CustomEvent('payable-updated', { detail: e }));
        });
}

export function cleanupEcho() {
    if (!window.Echo) return;
    try {
        window.Echo.leave('orders');
        window.Echo.leave('payables');
        if (currentEchoUserId) {
            window.Echo.leave(`orders.${currentEchoUserId}`);
            window.Echo.leave(`payables.${currentEchoUserId}`);
            currentEchoUserId = null;
        }
    } catch (e) {
        console.warn('cleanupEcho error', e);
    }
}

window.initEchoForUser = initEchoForUser;
window.cleanupEcho = cleanupEcho;

// Auto-initialize if authUser exists on blade render
if (window.Echo && window.authUser && window.authUser.id) {
    initEchoForUser(window.authUser.id);
}

function OrderNotificationCenter() {
    const [notifications, setNotifications] = useState([]);

    useEffect(() => {
        const handleOrderCreated = (event) => {
            const notification = event.detail;
            if (!notification?.order_sn) return;

            setNotifications((current) => [
                notification,
                ...current.filter((item) => String(item.order_sn) !== String(notification.order_sn)),
            ]);
        };

        window.addEventListener('order-created', handleOrderCreated);
        return () => window.removeEventListener('order-created', handleOrderCreated);
    }, []);

    const dismiss = (orderSn) => {
        setNotifications((current) => current.filter(
            (item) => String(item.order_sn) !== String(orderSn),
        ));
    };

    const openOrderList = (notification) => {
        const params = new URLSearchParams({
            source: 'notification',
            store_id: String(notification.store_id),
            filter: 'perlu_dikirim',
            shipping_process: 'needs_processing',
        });

        dismiss(notification.order_sn);
        window.location.hash = `#/orders?${params.toString()}`;
    };

    if (notifications.length === 0) return null;

    return (
        <aside
            className="fixed right-3 top-3 z-[10000] flex max-h-[calc(100dvh-24px)] w-[calc(100vw-24px)] max-w-sm flex-col gap-2 sm:right-5 sm:top-5"
            aria-label="Notifikasi pesanan baru"
        >
            <div className="min-h-0 space-y-2 overflow-y-auto overscroll-contain pr-1">
                {notifications.map((notification) => {
                    const platformName = String(notification.platform || '').toLowerCase();
                    const isShopee = platformName.includes('shopee');

                    return (
                        <div
                            key={notification.order_sn}
                            role="button"
                            tabIndex={0}
                            onClick={() => openOrderList(notification)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    openOrderList(notification);
                                }
                            }}
                            className="group w-full cursor-pointer overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl shadow-slate-900/10 transition hover:border-[#304674]/40 hover:shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                        >
                            <div className="flex items-start gap-3 p-4">
                                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${isShopee ? 'bg-orange-50' : 'bg-slate-100 dark:bg-slate-800'}`}>
                                    <img
                                        src={isShopee ? '/Marketplace-logo/shopee.png' : '/Marketplace-logo/tts.png'}
                                        alt=""
                                        className="h-6 w-6 object-contain"
                                    />
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="flex items-center gap-1.5 text-sm font-bold text-slate-900 dark:text-white">
                                                <BellRing className="h-3.5 w-3.5 text-[#304674] dark:text-blue-400" />
                                                Pesanan baru
                                            </p>
                                            <p className="mt-0.5 truncate text-xs font-medium text-slate-500 dark:text-slate-400">
                                                {notification.order_sn}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                dismiss(notification.order_sn);
                                            }}
                                            className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                            title="Tutup notifikasi"
                                            aria-label="Tutup notifikasi"
                                        >
                                            <X className="h-4 w-4" />
                                        </button>
                                    </div>

                                    <p className="mt-2 truncate text-sm font-semibold text-slate-700 dark:text-slate-200" title={notification.product_name}>
                                        {notification.product_name || 'Detail produk sedang dimuat'}
                                    </p>
                                    <div className="mt-2 flex min-w-0 items-center gap-2 text-xs">
                                        <span className={`shrink-0 rounded px-2 py-1 font-semibold ${isShopee ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-cyan-300'}`}>
                                            {isShopee ? 'Shopee' : 'TikTok Shop'}
                                        </span>
                                        <span className="flex min-w-0 items-center gap-1 truncate font-medium text-slate-500 dark:text-slate-400" title={notification.store_name}>
                                            <Store className="h-3.5 w-3.5 shrink-0" />
                                            <span className="truncate">{notification.store_name || 'Toko tidak diketahui'}</span>
                                        </span>
                                    </div>
                                    <p className="mt-2 text-[11px] font-medium text-[#304674] dark:text-blue-400">
                                        Buka pesanan perlu diproses
                                    </p>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {notifications.length >= 3 && (
                <button
                    type="button"
                    onClick={() => setNotifications([])}
                    className="flex w-full shrink-0 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600 shadow-lg transition hover:bg-slate-50 active:scale-[0.98] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    <Trash2 className="h-4 w-4" />
                    Hapus semua notifikasi
                </button>
            )}
        </aside>
    );
}

class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error("ErrorBoundary caught an error:", error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="flex flex-col items-center justify-center min-h-[60vh] p-6 text-center">
                    <div className="w-12 h-12 rounded-2xl bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-4">
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h2 className="text-lg font-bold text-slate-800 dark:text-slate-200 mb-2">Terjadi Kesalahan Halaman</h2>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-6 max-w-md">
                        Halaman mengalami kendala saat dimuat. Silakan muat ulang halaman atau kembali ke beranda.
                    </p>
                    <button
                        onClick={() => {
                            this.setState({ hasError: false, error: null });
                            window.location.reload();
                        }}
                        className="px-5 py-2.5 bg-[#304674] dark:bg-blue-600 text-white text-sm font-semibold rounded-xl hover:opacity-90 transition-opacity shadow-sm"
                    >
                        Muat Ulang Halaman
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}

function App() {
    const currentRoute = useHashRouter();
    const isAuth = authRoutes.includes(currentRoute);

    let PageComponent = routes[currentRoute];
    let routeParams = {};

    // Dynamic routing fallback if no exact match
    if (!PageComponent) {
        // e.g. /orders/123
        const orderMatch = currentRoute.match(/^\/orders\/(\d+)$/);
        if (orderMatch) {
            PageComponent = OrderDetail;
            routeParams = { id: orderMatch[1] };
        }

        const masterProductMatch = currentRoute.match(/^\/products\/master\/(\d+)$/);
        if (masterProductMatch) {
            PageComponent = MasterProductDetail;
            routeParams = { id: masterProductMatch[1] };
        }
    }

    useEffect(() => {
        console.log('App Mounted');

        if (window.flashMessages?.success) {
            toast.success(window.flashMessages.success);
        }
        if (window.flashMessages?.error) {
            toast.error(window.flashMessages.error, { duration: 8000 });
        }
        window.flashMessages = {};
    }, []);

    if (!PageComponent) {
        // Default to landing page if route not found
        window.location.hash = '#/';
        return <PageLoader />;
    }

    return (
        <ErrorBoundary>
            <GlobalBanner />
            <OrderNotificationCenter />
            <Toaster />
            <Suspense fallback={<PageLoader />}>
                <PageComponent routeParams={routeParams} />
            </Suspense>
        </ErrorBoundary>
    );
}

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            retry: 1,
        },
    },
});

const container = document.getElementById("app");
if (container) {
    if (!window.__finesheet_root) {
        window.__finesheet_root = ReactDOM.createRoot(container);
    }
    window.__finesheet_root.render(
        <QueryClientProvider client={queryClient}>
            <App />
        </QueryClientProvider>
    );
}
