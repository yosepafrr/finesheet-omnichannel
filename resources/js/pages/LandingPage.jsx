import { useState, useEffect } from "react";
import { navigate } from "../app";
import axios from "axios";
import { LogOut } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";

export default function LandingPage() {
    const [scrolled, setScrolled] = useState(false);
    const [authUser, setAuthUser] = useState(null); // null = loading, false = guest, object = login
    const [authLoading, setAuthLoading] = useState(true);
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);

    useEffect(() => {
        const handleScroll = () => {
            setScrolled(window.scrollY > 50);
        };
        window.addEventListener("scroll", handleScroll);
        return () => window.removeEventListener("scroll", handleScroll);
    }, []);

    // Cek status autentikasi saat landing page dimuat
    useEffect(() => {
        axios.get("/api/user")
            .then((res) => setAuthUser(res.data))
            .catch(() => setAuthUser(false))
            .finally(() => setAuthLoading(false));
    }, []);

    const handleLogout = async () => {
        try {
            await axios.post("/logout");
            window.location.href = "/";
        } catch (error) {
            console.error("Logout failed:", error);
            // Fallback redirect anyway
            window.location.href = "/";
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-900 font-sans transition-colors duration-300">
            {/* Navbar */}
            <nav
                className={`fixed w-full z-50 transition-all duration-300 ${scrolled ? "bg-white/80 dark:bg-slate-900/80 backdrop-blur-md shadow-sm py-3" : "bg-transparent py-5"}`}
            >
                <div className="max-w-7xl mx-auto px-6 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-[#304674] dark:bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold">
                            F
                        </div>
                        <span className="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
                            Finesheet
                        </span>
                    </div>
                    <div className="flex items-center gap-4">
                        {authLoading ? (
                            // Loading skeleton
                            <div className="w-24 h-8 bg-slate-200 dark:bg-slate-700 rounded-xl animate-pulse" />
                        ) : authUser ? (
                            // === SUDAH LOGIN ===
                            <div className="flex items-center gap-3">
                                <div className="flex items-center gap-2.5 px-3 py-1.5 bg-white/70 dark:bg-slate-800/70 backdrop-blur-sm rounded-full border border-slate-200 dark:border-slate-700 shadow-sm">
                                    <div className="w-7 h-7 rounded-full bg-[#304674] dark:bg-blue-600 flex items-center justify-center text-white text-xs font-bold">
                                        {authUser.name
                                            ? authUser.name
                                                  .charAt(0)
                                                  .toUpperCase()
                                            : "?"}
                                    </div>
                                    <span className="text-sm font-semibold text-slate-700 dark:text-slate-200 max-w-[120px] truncate hidden sm:block">
                                        {authUser.name}
                                    </span>
                                </div>
                                <button
                                    onClick={() => {
                                        setIsLogoutModalOpen(true);
                                    }}
                                    className="w-100 flex items-center gap-3 px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 rounded-xl transition-colors text-left"
                                >
                                    <LogOut className="w-4 h-4" /> Log Out
                                </button>
                            </div>
                        ) : (
                            // === GUEST ===
                            <>
                                <button
                                    onClick={() => navigate("/login")}
                                    className="text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-[#304674] dark:hover:text-blue-400 transition-colors"
                                >
                                    Masuk
                                </button>
                                <button
                                    onClick={() => navigate("/register")}
                                    className="px-5 py-2.5 bg-[#304674] dark:bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-[#203155] dark:hover:bg-blue-700 shadow-lg shadow-[#304674]/20 dark:shadow-blue-900/30 transition-all hover:-translate-y-0.5 active:scale-95"
                                >
                                    Daftar Gratis
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </nav>

            {/* Hero Section */}
            <main className="relative pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden">
                {/* Background Decor */}
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] bg-gradient-to-tr from-blue-500/20 to-purple-500/20 dark:from-blue-600/10 dark:to-purple-600/10 rounded-full blur-3xl opacity-50 pointer-events-none"></div>

                <div className="relative z-10 max-w-7xl mx-auto px-6 text-center">
                    {/* <span className="inline-block py-1 px-3 rounded-full bg-blue-50 dark:bg-blue-500/10 border border-blue-100 dark:border-blue-500/20 text-blue-600 dark:text-blue-400 text-xs font-bold tracking-wider uppercase mb-6 animate-fade-in-up">
                        omnichannel with integrated api e-commerce
                    </span> */}

                    {authUser ? (
                        // === Hero untuk user yang sudah login ===
                        <>
                            <h1
                                className="text-5xl md:text-7xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-6 leading-[1.1] animate-fade-in-up"
                                style={{ animationDelay: "0.1s" }}
                            >
                                Selamat Datang,{" "}
                                <br className="hidden md:block" />
                                <span className="text-transparent bg-clip-text bg-gradient-to-r from-[#304674] to-blue-500 dark:from-blue-400 dark:to-cyan-300">
                                    {authUser.name?.split(" ")[0]}!
                                </span>
                            </h1>
                            <p
                                className="max-w-2xl mx-auto text-lg md:text-xl text-slate-500 dark:text-slate-400 mb-10 leading-relaxed animate-fade-in-up"
                                style={{ animationDelay: "0.2s" }}
                            >
                                Anda sudah masuk. Pantau penjualan, stok, dan
                                arus kas konveksi Anda dari dashboard
                                terintegrasi.
                            </p>
                            <div
                                className="flex flex-col sm:flex-row items-center justify-center gap-4 animate-fade-in-up"
                                style={{ animationDelay: "0.3s" }}
                            >
                                <button
                                    onClick={() => navigate("/dashboard")}
                                    className="w-full sm:w-auto px-8 py-3 bg-[#304674] dark:bg-blue-600 text-white font-bold rounded-2xl hover:bg-[#203155] dark:hover:bg-blue-700 shadow-xl shadow-[#304674]/20 dark:shadow-blue-900/30 transition-all hover:-translate-y-1 active:scale-95 text-lg flex items-center justify-center gap-2"
                                >
                                    Buka Dashboard
                                    <span className="material-symbols-rounded">
                                        arrow_forward
                                    </span>
                                </button>
                            </div>
                        </>
                    ) : (
                        // === Hero untuk Guest ===
                        <>
                            <h1
                                className="text-5xl md:text-7xl font-extrabold text-slate-900 dark:text-white tracking-tight mb-8 leading-[1.1] animate-fade-in-up"
                                style={{ animationDelay: "0.1s" }}
                            >
                                Kelola Bisnis Anda{" "}
                                <br className="hidden md:block" />
                                <span className="text-transparent bg-clip-text bg-gradient-to-r from-[#304674] to-blue-500 dark:from-blue-400 dark:to-cyan-300">
                                    Lebih Cerdas &amp; Mudah.
                                </span>
                            </h1>

                            <p
                                className="max-w-2xl mx-auto text-lg md:text-xl text-slate-500 dark:text-slate-400 mb-10 leading-relaxed animate-fade-in-up"
                                style={{ animationDelay: "0.2s" }}
                            >
                                Platform terintegrasi untuk mengelola stok,
                                melacak pesanan dari berbagai marketplace, dan
                                memantau arus kas konveksi Anda secara
                                real-time.
                            </p>

                            <div
                                className="flex flex-col sm:flex-row items-center justify-center gap-4 animate-fade-in-up"
                                style={{ animationDelay: "0.3s" }}
                            >
                                <button
                                    onClick={() => navigate("/login")}
                                    className="w-full sm:w-auto px-8 py-4 bg-[#304674] dark:bg-blue-600 text-white font-bold rounded-2xl hover:bg-[#203155] dark:hover:bg-blue-700 shadow-xl shadow-[#304674]/20 dark:shadow-blue-900/30 transition-all hover:-translate-y-1 active:scale-95 text-lg flex items-center justify-center gap-2"
                                >
                                    Mulai sekarang
                                    <span className="material-symbols-rounded">
                                        arrow_forward
                                    </span>
                                </button>
                            </div>
                        </>
                    )}
                </div>

                {/* Dashboard Mockup Image Placeholder */}
                <div
                    className="relative mt-20 max-w-5xl mx-auto px-6 animate-fade-in-up"
                    style={{ animationDelay: "0.4s" }}
                >
                    <div className="relative rounded-2xl md:rounded-[2rem] bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-2xl p-2 md:p-4 overflow-hidden group">
                        <div className="absolute inset-0 bg-gradient-to-t from-slate-100 dark:from-slate-900 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
                        <div className="aspect-[16/9] w-full bg-slate-100 dark:bg-slate-900 rounded-xl md:rounded-2xl flex flex-col items-center justify-center border border-slate-200 dark:border-slate-700">
                            <span className="material-symbols-rounded text-6xl text-slate-300 dark:text-slate-600 mb-4">
                                monitoring
                            </span>
                            <p className="text-slate-400 dark:text-slate-500 font-medium">
                                Dashboard Preview
                            </p>
                        </div>
                    </div>
                </div>
            </main>

            <style>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up {
                    opacity: 0;
                    animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                }
            `}</style>

            {/* Logout Confirmation Modal */}
            <AnimatePresence>
                {isLogoutModalOpen && (
                    <div className="fixed inset-0 z-[100] flex items-center justify-center px-4">
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm"
                            onClick={() => setIsLogoutModalOpen(false)}
                        />
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            className="bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6 relative z-10 border border-slate-100 dark:border-slate-800"
                        >
                            <div className="w-12 h-12 bg-rose-100 dark:bg-rose-500/20 text-rose-600 dark:text-rose-400 rounded-full flex items-center justify-center mb-4 mx-auto">
                                <LogOut className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white text-center mb-2">
                                Konfirmasi Logout
                            </h3>
                            <p className="text-sm text-slate-500 dark:text-slate-400 text-center mb-6">
                                Apakah Anda yakin ingin keluar dari akun Anda?
                            </p>
                            <div className="flex gap-3">
                                <button
                                    onClick={() => setIsLogoutModalOpen(false)}
                                    className="flex-1 px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-semibold rounded-xl transition-colors"
                                >
                                    Kembali
                                </button>
                                <button
                                    onClick={handleLogout}
                                    className="flex-1 px-4 py-2 bg-red-700 text-white hover:bg-red-600 text-sm font-semibold rounded-xl transition-colors shadow-sm shadow-rose-600/20"
                                >
                                    Lanjut Logout
                                </button>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>
        </div>
    );
}
