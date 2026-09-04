import '../css/app.css';
import './bootstrap';
import React, { useState, useEffect, lazy, Suspense } from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import axios from "axios";

// Global axios config for handling 401 Unauthorized
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response && error.response.status === 401) {
            window.location.hash = '#/login';
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
const Cashflow = lazy(() => import('./pages/Cashflow'));
const MeetCreators = lazy(() => import('./pages/MeetCreators'));
const Profile = lazy(() => import('./pages/Profile'));
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
    const [hash, setHash] = useState(window.location.hash.slice(1) || '/');

    useEffect(() => {
        const handleHashChange = () => {
            setHash(window.location.hash.slice(1) || '/');
        };
        window.addEventListener('hashchange', handleHashChange);

        // Set initial hash to root if none
        if (!window.location.hash) {
            window.location.hash = '#/';
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

// Initialize Echo listeners outside React lifecycle to prevent unmount race conditions
if (window.Echo) {
    console.log("Echo is defined globally. Subscribing to channels...");
    
    window.Echo.channel('orders')
        .listen('.OrderCreated', (e) => {
            console.log('OrderCreated event received globally:', e);
            
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

            toast.custom((t) => (
                <div
                    className={`${
                        t.visible ? 'animate-enter' : 'animate-leave'
                    } max-w-md w-full bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl shadow-2xl rounded-2xl pointer-events-auto flex ring-1 ring-black/5 dark:ring-white/10 overflow-hidden transform transition-all hover:scale-105`}
                >
                    <div className="flex-1 w-0 p-4">
                        <div className="flex items-start">
                            <div className="flex-shrink-0 pt-0.5">
                                {platformName.includes('shopee') ? (
                                    <div className="h-10 w-10 rounded-full bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center shadow-lg shadow-orange-500/30">
                                        <svg className="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                        </svg>
                                    </div>
                                ) : (
                                    <div className="h-10 w-10 rounded-full bg-gradient-to-br from-slate-800 to-black flex items-center justify-center shadow-lg shadow-cyan-500/20 border border-slate-700">
                                        <svg className="w-6 h-6 text-cyan-400" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 15.68a6.34 6.34 0 006.27 6.36 6.34 6.34 0 006.33-6.36v-6.32a8.28 8.28 0 004 1.05V6.84a4.93 4.93 0 01-2.01-.15z"/>
                                        </svg>
                                    </div>
                                )}
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-bold text-slate-900 dark:text-white">
                                    Pesanan Baru! 🎉
                                </p>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 font-medium">
                                    {e.order_sn}
                                </p>
                                <div className="mt-2 flex items-center gap-2">
                                    <span className={`text-xs px-2 py-1 rounded-md font-bold flex-shrink-0 ${
                                        platformName.includes('shopee') 
                                        ? 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400' 
                                        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-cyan-400'
                                    }`}>
                                        {e.platform}
                                    </span>
                                    <span className="text-xs font-semibold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded-md truncate max-w-[200px]" title={e.product_name}>
                                        {e.product_name || 'Produk tidak diketahui'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="flex border-l border-slate-200 dark:border-slate-700 flex-shrink-0">
                        <button
                            onClick={() => toast.dismiss(t.id)}
                            className="w-full border border-transparent rounded-none rounded-r-2xl p-4 flex items-center justify-center text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors focus:outline-none"
                        >
                            Tutup
                        </button>
                    </div>
                </div>
            ), { duration: 10000, position: 'top-right' });
        });
}

function App() {
    const currentRoute = useHashRouter();
    const isAuth = authRoutes.includes(currentRoute);

    const PageComponent = routes[currentRoute];

    useEffect(() => {
        console.log('App Mounted');
    }, []);

    if (!PageComponent) {
        // Default to landing page if route not found
        window.location.hash = '#/';
        return <PageLoader />;
    }

    return (
        <>
            <GlobalBanner />
            <Toaster />
            <Suspense fallback={<PageLoader />}>
                <PageComponent />
            </Suspense>
        </>
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

ReactDOM.createRoot(document.getElementById("app")).render(
    <QueryClientProvider client={queryClient}>
        <App />
    </QueryClientProvider>
);