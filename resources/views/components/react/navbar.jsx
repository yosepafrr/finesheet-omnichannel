import { useState, useRef, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Menu, Bell, User, Settings, LogOut, ChevronDown } from "lucide-react";
import axios from "axios";

export default function Navigation({ onMenuClick, user = { name: "Loading...", email: "" } }) {
    const [isProfileOpen, setIsProfileOpen] = useState(false);
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);
    const [isDark, setIsDark] = useState(false);
    const dropdownRef = useRef(null);

    // Inisialisasi Dark Mode
    useEffect(() => {
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            setIsDark(true);
            document.documentElement.classList.add('dark');
        } else {
            setIsDark(false);
            document.documentElement.classList.remove('dark');
        }
    }, []);

    const toggleDark = () => {
        if (isDark) {
            document.documentElement.classList.remove('dark');
            localStorage.theme = 'light';
            setIsDark(false);
        } else {
            document.documentElement.classList.add('dark');
            localStorage.theme = 'dark';
            setIsDark(true);
        }
    };

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (
                dropdownRef.current &&
                !dropdownRef.current.contains(e.target)
            ) {
                setIsProfileOpen(false);
            }
        };
        document.addEventListener("mousedown", handleClickOutside);
        return () =>
            document.removeEventListener("mousedown", handleClickOutside);
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
        <>
        {/* Position sekarang mengikuti flex-none container di AppLayout */}
        <nav className="relative z-40 w-full h-16 sm:h-[72px] bg-white/90 backdrop-blur-md dark:bg-slate-900/90 border-b border-slate-200 dark:border-slate-800 shadow-sm transition-all duration-300">
            <div className="h-full mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex justify-between items-center h-full">
                    {/* --- KIRI: Menu Mobile & Logo --- */}
                    <div className="flex items-center gap-3">
                        <button
                            onClick={onMenuClick}
                            className="lg:hidden p-2 -ml-2 rounded-xl text-slate-500 hover:text-[#304674] hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors focus:outline-none"
                        >
                            <Menu className="w-6 h-6" />
                        </button>

                        <a
                            href="/"
                            className="group focus:outline-none transition-transform active:scale-95"
                        >
                            <span className="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white leading-none group-hover:text-[#304674] dark:group-hover:text-blue-400 transition-colors duration-300">
                                <span className="font-normal">fine</span>sheet
                            </span>
                        </a>
                    </div>

                    {/* --- KANAN: Theme Toggle & Profil --- */}
                    <div className="flex items-center gap-1 sm:gap-4">
                        <button
                            onClick={toggleDark}
                            className="p-2 text-slate-500 hover:text-[#304674] hover:bg-slate-100 dark:hover:bg-slate-800 rounded-full transition-colors active:scale-95 focus:outline-none"
                            aria-label="Toggle Dark Mode"
                        >
                            {isDark ? (
                                // Moon Icon
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                                </svg>
                            ) : (
                                // Sun Icon
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="5"></circle>
                                    <line x1="12" y1="1" x2="12" y2="3"></line>
                                    <line x1="12" y1="21" x2="12" y2="23"></line>
                                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                                    <line x1="1" y1="12" x2="3" y2="12"></line>
                                    <line x1="21" y1="12" x2="23" y2="12"></line>
                                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                                </svg>
                            )}
                        </button>

                        <div className="w-px h-6 bg-slate-200 dark:bg-slate-700 mx-1 hidden sm:block"></div>
                        
                        <div className="flex items-center gap-2">
                            <a
                                href="#/profile"
                                title="Profile Settings"
                                className="p-2 rounded-full text-slate-500 hover:text-[#304674] hover:bg-slate-100 dark:text-slate-400 dark:hover:text-blue-400 dark:hover:bg-slate-800 transition-colors focus:outline-none flex items-center"
                            >
                                <User className="w-5 h-5" />
                                <span className="hidden md:inline-block ml-2 text-sm font-semibold truncate max-w-[120px]">
                                    {user.name}
                                </span>
                            </a>
                            
                            <button
                                onClick={() => setIsLogoutModalOpen(true)}
                                title="Log Out"
                                className="p-2 rounded-full text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-500/10 transition-colors focus:outline-none"
                            >
                                <LogOut className="w-5 h-5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

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
        </>
    );
}
