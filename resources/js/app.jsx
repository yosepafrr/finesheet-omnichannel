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
        <Suspense fallback={<PageLoader />}>
            <PageComponent />
        </Suspense>
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