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
    '/login': Login,
    '/register': Register,
};

function GlobalBanner() {
    const [disconnectedStores, setDisconnectedStores] = useState(false);
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        fetch('/api/stores', {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => {
            if (res.ok) return res.json();
            throw new Error('Not logged in or error');
        })
        .then(data => {
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

function App() {
    const currentRoute = useHashRouter();
    const isAuth = authRoutes.includes(currentRoute);

    const PageComponent = routes[currentRoute];

    if (!PageComponent) {
        // Default to landing page if route not found
        window.location.hash = '#/';
        return <PageLoader />;
    }

    return (
        <>
            <GlobalBanner />
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