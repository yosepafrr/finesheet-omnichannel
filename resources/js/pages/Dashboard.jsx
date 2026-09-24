import { useState, useEffect, useRef } from "react";
import { motion, AnimatePresence } from "framer-motion";
import axios from "axios";
import AppLayout from "../../views/components/layouts/AppLayout";
import { 
    LayoutDashboard, 
    TrendingUp, 
    ShoppingCart, 
    Wallet,
    Store,
    Calendar,
    ChevronDown
} from "lucide-react";
import toast from "react-hot-toast";
import ReactApexChart from "react-apexcharts";
import OnboardingTour from "@/components/OnboardingTour";
import { useOnboarding } from "@/hooks/useOnboarding";

const DASHBOARD_TOUR_STEPS = [
    {
        selector: "#tour-metrics",
        title: "Metrik Bisnis",
        description: "Pantau estimasi profit bersih, pesanan aktif, hutang supplier, dan jumlah toko yang terhubung dalam satu tampilan.",
        position: "bottom",
    },
    {
        selector: "#tour-chart",
        title: "Grafik Tren",
        description: "Lihat tren pesanan dan profit harian. Gunakan filter hari di atas untuk menyesuaikan rentang waktu.",
        position: "top",
    },
    {
        selector: null,
        title: "Siap Digunakan!",
        description: "Jelajahi sidebar untuk mengakses Daftar Pesanan, Produk, Profit Tracker, dan pengaturan Toko. Gunakan tombol ? kapan saja untuk membuka panduan ini kembali.",
        position: "bottom",
    },
];

const formatRupiah = (number) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(number);
};

export default function Dashboard() {
    const [loading, setLoading] = useState(true);
    const [days, setDays] = useState(7);
    const [isDaysDropdownOpen, setIsDaysDropdownOpen] = useState(false);
    const daysDropdownRef = useRef(null);
    const tour = useOnboarding("dashboard", DASHBOARD_TOUR_STEPS.length);

    useEffect(() => {
        function handleClickOutside(event) {
            if (daysDropdownRef.current && !daysDropdownRef.current.contains(event.target)) {
                setIsDaysDropdownOpen(false);
            }
        }
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);
    const [stats, setStats] = useState({
        perlu_dikirim_count: 0,
        total_supplier_debt: 0,
        net_estimation: 0,
        recent_orders: [],
        order_trend: [],
        profit_trend: [],
        platform_distribution: [],
        store_count: 0,
        active_store_count: 0,
    });

    useEffect(() => {
        let isMounted = true;

        const fetchDashboardData = async (silent = false) => {
            try {
                if (!silent) setLoading(true);
                const response = await axios.get(`/dashboard/stats?days=${days}`);
                if (isMounted) {
                    setStats(response.data);
                }
            } catch (error) {
                console.error("Failed to fetch dashboard stats", error);
                if (error?.response?.status !== 401 && !silent) {
                    toast.error("Gagal memuat data dashboard");
                }
            } finally {
                if (!silent && isMounted) setLoading(false);
            }
        };

        // Initial fetch with loading state
        fetchDashboardData(false);

        // Auto-refresh interval (silent fetch every 15 seconds)
        const interval = setInterval(() => {
            fetchDashboardData(true);
        }, 15000);

        return () => {
            isMounted = false;
            clearInterval(interval);
        };
    }, [days]);

    if (loading) {
        return (
            <AppLayout>
                <div className="w-full space-y-6 pb-20 animate-pulse">
                    {/* Header Skeleton */}
                    <div className="h-8 bg-slate-200 dark:bg-slate-700 rounded w-48 mb-6"></div>
                    
                    {/* Metrics Skeleton */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        {[1, 2, 3, 4].map(i => (
                            <div key={i} className="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-gray-100 dark:border-slate-700 h-32">
                                <div className="flex justify-between items-start mb-4">
                                    <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-24"></div>
                                    <div className="w-10 h-10 bg-slate-200 dark:bg-slate-700 rounded-lg"></div>
                                </div>
                                <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded w-32"></div>
                            </div>
                        ))}
                    </div>

                    {/* Table Skeleton */}
                    <div className="bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 h-64 p-6">
                        <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded w-40 mb-6"></div>
                        <div className="space-y-4">
                            {[1, 2, 3].map(i => (
                                <div key={i} className="h-10 bg-slate-200 dark:bg-slate-700 rounded w-full"></div>
                            ))}
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <div className="w-full space-y-8 pb-20">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight flex items-center gap-2">
                            <LayoutDashboard className="w-6 h-6 text-[#304674] dark:text-blue-500" />
                            Dashboard
                        </h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
                            Ringkasan akumulasi aktivitas bisnismu.
                        </p>
                    </div>
                    
                    {/* Dropdown dipindahkan ke bawah */}
                </div>

                {/* Metrics */}
                <div id="tour-metrics" className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <a href="#/profit-tracker" target="_blank" rel="noopener noreferrer" className="block bg-white dark:bg-slate-800 rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-slate-700 group hover:shadow-md transition-all cursor-pointer">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <p className="text-sm font-semibold text-gray-500 dark:text-slate-400 mb-1">Estimasi Profit Bersih</p>
                                <h3 className="text-2xl font-bold text-gray-800 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                    {formatRupiah(stats.net_estimation)}
                                </h3>
                            </div>
                            <div className="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center text-blue-500 group-hover:scale-110 transition-transform">
                                <TrendingUp className="w-6 h-6" />
                            </div>
                        </div>
                    </a>

                    <a href="#/orders" target="_blank" rel="noopener noreferrer" className="block bg-white dark:bg-slate-800 rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-slate-700 group hover:shadow-md transition-all cursor-pointer">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <p className="text-sm font-semibold text-gray-500 dark:text-slate-400 mb-1">Jumlah Pesanan</p>
                                <h3 className="text-2xl font-bold text-gray-800 dark:text-white group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors">
                                    {stats.perlu_dikirim_count.toLocaleString("id-ID")} <span className="text-sm font-normal text-gray-500">perlu dikirim</span>
                                </h3>
                            </div>
                            <div className="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center text-emerald-500 group-hover:scale-110 transition-transform">
                                <ShoppingCart className="w-6 h-6" />
                            </div>
                        </div>
                    </a>

                    <a href="#/payable" target="_blank" rel="noopener noreferrer" className="block bg-white dark:bg-slate-800 rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-slate-700 group hover:shadow-md transition-all cursor-pointer">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <p className="text-sm font-semibold text-gray-500 dark:text-slate-400 mb-1">Total Tagihan ke Supplier</p>
                                <h3 className="text-2xl font-bold text-gray-800 dark:text-white group-hover:text-rose-600 dark:group-hover:text-rose-400 transition-colors">
                                    {formatRupiah(stats.total_supplier_debt)}
                                </h3>
                            </div>
                            <div className="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-500/10 flex items-center justify-center text-rose-500 group-hover:scale-110 transition-transform">
                                <Wallet className="w-6 h-6" />
                            </div>
                        </div>
                    </a>

                    <a href="#/stores" target="_blank" rel="noopener noreferrer" className="block bg-white dark:bg-slate-800 rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-slate-700 group hover:shadow-md transition-all cursor-pointer">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <p className="text-sm font-semibold text-gray-500 dark:text-slate-400 mb-1">Status Toko</p>
                                <h3 className={`mt-2 font-bold ${stats.store_count === 0 ? 'max-w-[190px] text-sm leading-snug text-[#304674] dark:text-blue-300' : stats.active_store_count < stats.store_count ? 'text-base text-amber-700 dark:text-amber-300' : 'text-lg text-gray-800 dark:text-white'}`}>
                                    {stats.store_count === 0
                                        ? 'Kamu belum menautkan toko, tautkan sekarang!'
                                        : stats.active_store_count < stats.store_count
                                            ? `${stats.store_count - stats.active_store_count} toko perlu otorisasi ulang`
                                            : 'Terkoneksi dengan baik'}
                                </h3>
                            </div>
                            <div className="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-500/10 flex items-center justify-center text-amber-500 group-hover:scale-110 transition-transform">
                                <Store className="w-6 h-6" />
                            </div>
                        </div>
                    </a>
                </div>

                {/* Filter Waktu */}
                <div className="flex justify-start lg:justify-end mt-2 mb-2">
                    <div className="relative w-full sm:w-[190px]" ref={daysDropdownRef}>
                        <button
                            type="button"
                            onClick={() => setIsDaysDropdownOpen(!isDaysDropdownOpen)}
                            className="flex items-center justify-between w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 text-gray-700 dark:text-slate-200 py-2 pl-3.5 pr-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 shadow-sm text-sm transition-all hover:border-gray-300 dark:hover:border-slate-600 cursor-pointer"
                        >
                            <div className="flex items-center gap-2">
                                <Calendar className="w-4 h-4 text-gray-400 dark:text-slate-500" />
                                <span className="truncate pr-2 font-medium">
                                    {days === 7 ? '7 Hari Terakhir' : days === 14 ? '14 Hari Terakhir' : '30 Hari Terakhir'}
                                </span>
                            </div>
                            <ChevronDown
                                className={`w-4 h-4 text-gray-400 dark:text-slate-500 transition-transform duration-300 flex-shrink-0 ${isDaysDropdownOpen ? "rotate-180" : ""}`}
                            />
                        </button>

                        <AnimatePresence>
                            {isDaysDropdownOpen && (
                                <motion.div
                                    initial={{ opacity: 0, y: -10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: -10 }}
                                    transition={{ duration: 0.2 }}
                                    className="absolute z-10 w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-lg border border-gray-100 dark:border-slate-700 py-1 overflow-hidden"
                                >
                                    {[7, 14, 30].map(val => (
                                        <button
                                            key={val}
                                            onClick={() => {
                                                setDays(val);
                                                setIsDaysDropdownOpen(false);
                                            }}
                                            className={`w-full text-left px-3 py-2 text-sm transition-colors ${
                                                days === val 
                                                ? 'bg-blue-50 text-blue-600 dark:bg-slate-700 dark:text-blue-400 font-semibold' 
                                                : 'text-gray-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                                            }`}
                                        >
                                            {val} Hari Terakhir
                                        </button>
                                    ))}
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>

                {/* Charts Section */}
                <div id="tour-chart" className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Profit Trend (Area Chart) */}
                    <div className="col-span-1 lg:col-span-2 bg-white dark:bg-slate-800 rounded-3xl shadow-sm p-6 border border-gray-100 dark:border-slate-700 relative z-0">
                        <div className="mb-4">
                            <h3 className="text-gray-800 dark:text-white font-bold text-lg">Pertumbuhan Profit Bersih</h3>
                            <p className="text-gray-500 dark:text-slate-400 text-sm">Estimasi profit kotor dikurangi biaya per hari dalam {days} hari terakhir.</p>
                        </div>
                        <div className="h-72 mt-4">
                            <ReactApexChart 
                                type="area"
                                height="100%"
                                series={[{ name: 'Estimasi Profit', data: stats.profit_trend?.map(item => item['Estimasi Profit']) || [] }]}
                                options={{
                                    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif', background: 'transparent' },
                                    dataLabels: { enabled: false },
                                    stroke: { curve: 'smooth', width: 3, colors: ['#3b82f6'] },
                                    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100], colorStops: [{ offset: 0, color: '#3b82f6', opacity: 0.4 }, { offset: 100, color: '#3b82f6', opacity: 0.05 }] } },
                                    xaxis: { 
                                        categories: stats.profit_trend?.map(item => item.date) || [], 
                                        labels: { style: { colors: '#94a3b8' } },
                                        axisBorder: { show: false },
                                        axisTicks: { show: false },
                                        tooltip: { enabled: false }
                                    },
                                    yaxis: { labels: { style: { colors: '#94a3b8' }, formatter: (value) => formatRupiah(value) } },
                                    grid: { borderColor: '#e2e8f0', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
                                    tooltip: { theme: 'light', y: { formatter: (value) => formatRupiah(value) } },
                                }}
                            />
                        </div>
                    </div>

                    {/* Platform Distribution (Donut Chart) */}
                    <div className="col-span-1 bg-white dark:bg-slate-800 rounded-3xl shadow-sm p-6 border border-gray-100 dark:border-slate-700 flex flex-col items-center justify-center relative z-0">
                        <div className="w-full text-left mb-4">
                            <h3 className="text-gray-800 dark:text-white font-bold text-lg">Distribusi Platform</h3>
                            <p className="text-gray-500 dark:text-slate-400 text-sm">Persentase jumlah pesanan per platform.</p>
                        </div>
                        <div className="flex-1 flex flex-col items-center justify-center w-full mt-4 h-64">
                            <ReactApexChart 
                                type="donut"
                                width="100%"
                                height="250"
                                series={stats.platform_distribution?.map(item => item.value) || []}
                                options={{
                                    chart: { fontFamily: 'Inter, sans-serif', background: 'transparent' },
                                    labels: stats.platform_distribution?.map(item => item.name) || [],
                                    colors: stats.platform_distribution?.map(item => item.name.toLowerCase() === 'shopee' ? '#ea580c' : '#059669') || ['#ea580c', '#059669'], // orange-600 and emerald-600
                                    stroke: { show: false },
                                    dataLabels: { enabled: false },
                                    plotOptions: { donut: { size: '75%' } },
                                    tooltip: { theme: 'light', y: { formatter: (val) => `${val} pesanan` } },
                                    legend: { position: 'bottom', labels: { colors: '#94a3b8' } }
                                }}
                            />
                        </div>
                    </div>

                    {/* Order Trend (Area Chart) */}
                    <div className="col-span-1 lg:col-span-3 bg-white dark:bg-slate-800 rounded-3xl shadow-sm p-6 border border-gray-100 dark:border-slate-700 relative z-0">
                        <div className="mb-4">
                            <h3 className="text-gray-800 dark:text-white font-bold text-lg">Tren Pesanan Masuk</h3>
                            <p className="text-gray-500 dark:text-slate-400 text-sm">Total pesanan harian berdasarkan waktu pesanan dibuat.</p>
                        </div>
                        <div className="h-72 mt-4">
                            <ReactApexChart 
                                type="area"
                                height="100%"
                                series={[{ name: 'Total Pesanan', data: stats.order_trend?.map(item => item['Total Pesanan']) || [] }]}
                                options={{
                                    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif', background: 'transparent' },
                                    dataLabels: { enabled: false },
                                    stroke: { curve: 'straight', width: 3, colors: ['#10b981'] },
                                    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 90, 100], colorStops: [{ offset: 0, color: '#10b981', opacity: 0.4 }, { offset: 100, color: '#10b981', opacity: 0.05 }] } },
                                    xaxis: { 
                                        categories: stats.order_trend?.map(item => item.date) || [], 
                                        labels: { style: { colors: '#94a3b8' } },
                                        axisBorder: { show: false },
                                        axisTicks: { show: false },
                                        tooltip: { enabled: false }
                                    },
                                    yaxis: { labels: { style: { colors: '#94a3b8' }, formatter: (value) => Math.round(value) } },
                                    grid: { borderColor: '#e2e8f0', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: true } } },
                                    tooltip: { theme: 'light', y: { formatter: (value) => `${value} pesanan` } },
                                }}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <OnboardingTour
                steps={DASHBOARD_TOUR_STEPS}
                isOpen={tour.isOpen}
                currentStep={tour.currentStep}
                onNext={tour.next}
                onPrev={tour.prev}
                onSkip={tour.skip}
                onFinish={tour.finish}
                onStart={tour.start}
            />
        </AppLayout>
    );
}
