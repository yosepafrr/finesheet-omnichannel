import { useState, useRef, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Menu, Bell, User, Settings, LogOut, ChevronDown } from "lucide-react";

export default function Navigation({ onMenuClick }) {
    const [isProfileOpen, setIsProfileOpen] = useState(false);
    const dropdownRef = useRef(null);

    const user = { name: "Yosep", email: "yosep@example.com" };

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

    const handleLogout = () => console.log("Logout triggered");

    return (
        // Mengubah position menjadi sticky top-0 dan z-30 layaknya Laravel
        <nav className="sticky top-0 z-30 w-full h-16 sm:h-[72px] bg-white/90 backdrop-blur-md dark:bg-slate-900/90 border-b border-slate-200 dark:border-slate-800 shadow-sm transition-all duration-300">
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

                    {/* --- KANAN: Notifikasi & Profil --- */}
                    <div className="flex items-center gap-1 sm:gap-4">
                        <button className="relative p-2 text-slate-500 hover:text-[#304674] hover:bg-gray-100 dark:hover:bg-slate-800 rounded-full transition-colors">
                            <Bell className="w-5 h-5" />
                            <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 border-2 border-white dark:border-slate-900 rounded-full"></span>
                        </button>

                        <div className="w-px h-6 bg-slate-200 dark:bg-slate-700 mx-1 hidden  sm:block"></div>
                        <div className="relative" ref={dropdownRef}>
                            <button
                                onClick={() => setIsProfileOpen(!isProfileOpen)}
                                className="group flex items-center gap-3 p-1 pr-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-800 border border-transparent hover:border-slate-200 dark:hover:border-slate-700 transition-all focus:outline-none"
                            >
                                <div className="h-9 w-9 rounded-full bg-[#304674] text-white flex items-center justify-center text-sm font-bold shadow-sm">
                                    {user.name.charAt(0)}
                                </div>
                                <div className="hidden md:flex items-center gap-3">
                                    <span className="text-sm font-semibold text-slate-700 dark:text-slate-200 group-hover:text-[#304674] transition-colors">
                                        {user.name}
                                    </span>
                                    <ChevronDown
                                        className={`w-4 h-4 text-slate-400 transition-transform duration-300 ${isProfileOpen ? "rotate-180" : ""}`}
                                    />
                                </div>
                            </button>

                            <AnimatePresence>
                                {isProfileOpen && (
                                    <motion.div
                                        initial={{
                                            opacity: 0,
                                            y: 10,
                                            scale: 0.95,
                                        }}
                                        animate={{ opacity: 1, y: 0, scale: 1 }}
                                        exit={{
                                            opacity: 0,
                                            y: 10,
                                            scale: 0.95,
                                        }}
                                        transition={{
                                            duration: 0.2,
                                            ease: "easeOut",
                                        }}
                                        className="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-2xl shadow-lg border border-slate-100 dark:border-slate-800 overflow-hidden origin-top-right"
                                    >
                                        <div className="p-4 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                                            <p className="text-xs font-medium text-slate-500 mb-0.5">
                                                Signed in as
                                            </p>
                                            <p className="text-sm font-bold text-slate-900 dark:text-white truncate">
                                                {user.email}
                                            </p>
                                        </div>
                                        <div className="p-2">
                                            <a
                                                href="/profile"
                                                className="flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-[#304674] hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors"
                                            >
                                                <User className="w-4 h-4" />{" "}
                                                Profile Settings
                                            </a>
                                            <a
                                                href="/preferences"
                                                className="flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-[#304674] hover:bg-slate-50 dark:hover:bg-slate-800 rounded-xl transition-colors"
                                            >
                                                <Settings className="w-4 h-4" />{" "}
                                                Preferences
                                            </a>
                                            <div className="h-px bg-slate-100 dark:bg-slate-800 my-2"></div>
                                            <button
                                                onClick={handleLogout}
                                                className="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 rounded-xl transition-colors text-left"
                                            >
                                                <LogOut className="w-4 h-4" />{" "}
                                                Log Out
                                            </button>
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    );
}
