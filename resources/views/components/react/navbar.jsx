import { useState, useRef, useEffect } from "react";
import { createPortal } from "react-dom";
import { motion, AnimatePresence } from "framer-motion";
import { Menu, User, Settings, LogOut, ChevronDown, ShieldCheck, LockKeyhole } from "lucide-react";
import axios from "axios";
import { getDefaultAccessRoute } from "../../../js/contexts/AccessContext";

export default function Navigation({ onMenuClick, user = { name: "Loading...", email: "" }, access }) {
    const [isProfileOpen, setIsProfileOpen] = useState(false);
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);
    const [isDark, setIsDark] = useState(false);
    const [isOwnerModalOpen, setIsOwnerModalOpen] = useState(false);
    const [ownerPassword, setOwnerPassword] = useState("");
    const [ownerError, setOwnerError] = useState("");
    const [switching, setSwitching] = useState(false);
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
            if (typeof window.cleanupEcho === 'function') {
                window.cleanupEcho();
            }
            await axios.post("/logout");
        } catch (error) {
            console.error("Logout failed:", error);
        } finally {
            window.location.href = "/";
            window.location.reload();
        }
    };

    const switchProfile = async (profileId) => {
        setSwitching(true);
        try {
            await axios.post("/api/access/switch-profile", { profile_id: profileId });
            const nextAccess = await access.refresh();
            setIsProfileOpen(false);
            window.location.hash = `#${getDefaultAccessRoute(nextAccess)}`;
        } finally {
            setSwitching(false);
        }
    };

    const switchToOwner = async (event) => {
        event.preventDefault();
        setOwnerError("");
        setSwitching(true);
        try {
            await axios.post("/api/access/switch-owner", { password: ownerPassword });
            setOwnerPassword("");
            setIsOwnerModalOpen(false);
            const nextAccess = await access.refresh();
            window.location.hash = `#${getDefaultAccessRoute(nextAccess)}`;
        } catch (error) {
            setOwnerError(error.response?.data?.errors?.password?.[0] || "Gagal memverifikasi Owner.");
        } finally {
            setSwitching(false);
        }
    };

    return (
        <>
        {/* Position sekarang mengikuti flex-none container di AppLayout */}
        <nav className="relative z-20 lg:z-30 w-full h-16 sm:h-[55px] bg-white/90 backdrop-blur-md dark:bg-slate-900/90 border-b border-slate-200 dark:border-slate-800 shadow-sm transition-all duration-300">
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
                                <span className="font-normal">fine</span>sheet <span className="text-xs bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">beta test</span>
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
                        
                        <div ref={dropdownRef} className="relative">
                            <button
                                type="button"
                                onClick={() => setIsProfileOpen((open) => !open)}
                                className="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[#304674] text-xs font-bold text-white">
                                    {(access?.active_profile?.name || user.name || "U").charAt(0).toUpperCase()}
                                </div>
                                <div className="hidden min-w-0 text-left md:block">
                                    <p className="max-w-[140px] truncate text-xs font-bold text-slate-800 dark:text-white">
                                        {access?.is_owner_mode ? user.name : access?.active_profile?.name}
                                    </p>
                                    <p className="text-[10px] font-medium text-slate-400">
                                        {access?.is_owner_mode ? "Owner" : "Mode operator"}
                                    </p>
                                </div>
                                <ChevronDown className={`h-4 w-4 transition ${isProfileOpen ? "rotate-180" : ""}`} />
                            </button>

                            {isProfileOpen && (
                                <div className="absolute right-0 top-[calc(100%+8px)] z-[200] w-72 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                                    <div className="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                                        <p className="text-sm font-bold text-slate-900 dark:text-white">{user.name}</p>
                                        <p className="truncate text-xs text-slate-400">{user.email}</p>
                                    </div>

                                    {access?.is_owner_mode ? (
                                        <div className="p-2">
                                            <p className="px-2 py-1.5 text-[10px] font-bold uppercase text-slate-400">Beralih mode</p>
                                            {(access?.available_profiles || []).map((profile) => (
                                                <button key={profile.id} type="button" disabled={switching} onClick={() => switchProfile(profile.id)} className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                                                    <ShieldCheck className="h-4 w-4 text-[#304674] dark:text-blue-400" />
                                                    <span className="truncate">{profile.name}</span>
                                                </button>
                                            ))}
                                            {(access?.available_profiles || []).length === 0 && <p className="px-3 py-2 text-xs text-slate-400">Belum ada profil operator.</p>}
                                        </div>
                                    ) : (
                                        <div className="p-2">
                                            <button type="button" onClick={() => { setIsProfileOpen(false); setIsOwnerModalOpen(true); }} className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                                                <LockKeyhole className="h-4 w-4 text-amber-500" />
                                                Kembali ke Owner
                                            </button>
                                        </div>
                                    )}

                                    <div className="border-t border-slate-100 p-2 dark:border-slate-800">
                                        {access?.is_owner_mode && <a href="#/team-access" onClick={() => setIsProfileOpen(false)} className="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"><Settings className="h-4 w-4" />Tim & Hak Akses</a>}
                                        {access?.is_owner_mode && <a href="#/profile" onClick={() => setIsProfileOpen(false)} className="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800"><User className="h-4 w-4" />Profil Owner</a>}
                                        <button type="button" onClick={() => { setIsProfileOpen(false); setIsLogoutModalOpen(true); }} className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"><LogOut className="h-4 w-4" />Logout</button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        {/* Logout Confirmation Modal Portaled to Document Body */}
        {typeof document !== 'undefined' && createPortal(
            <AnimatePresence>
                {isLogoutModalOpen && (
                    <div className="fixed inset-0 z-[500] flex items-center justify-center px-4">
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm"
                            onClick={() => setIsLogoutModalOpen(false)}
                        />
                        <motion.div
                            initial={{ opacity: 0, scale: 0.95 }}
                            animate={{ opacity: 1, scale: 1 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm p-6 relative z-10 border border-slate-100 dark:border-slate-800"
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
                                    className="flex-1 px-4 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-semibold rounded-xl transition-colors cursor-pointer"
                                >
                                    Kembali
                                </button>
                                <button
                                    onClick={handleLogout}
                                    className="flex-1 px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm shadow-rose-600/30 cursor-pointer"
                                >
                                    Lanjut Logout
                                </button>
                            </div>
                        </motion.div>
                    </div>
                )}
            </AnimatePresence>,
            document.body
        )}

        {typeof document !== 'undefined' && createPortal(
            <AnimatePresence>
                {isOwnerModalOpen && (
                    <div className="fixed inset-0 z-[510] flex items-center justify-center px-4">
                        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 bg-slate-900/55 backdrop-blur-sm" onClick={() => setIsOwnerModalOpen(false)} />
                        <motion.form initial={{ opacity: 0, scale: 0.96 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.96 }} onSubmit={switchToOwner} className="relative z-10 w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
                            <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10"><LockKeyhole className="h-5 w-5" /></div>
                            <h3 className="mt-4 text-lg font-bold text-slate-900 dark:text-white">Verifikasi Owner</h3>
                            <p className="mt-1 text-sm text-slate-500">Masukkan password akun untuk membuka kembali akses Owner.</p>
                            <label className="mt-5 block text-xs font-bold text-slate-600 dark:text-slate-300">Password akun</label>
                            <input autoFocus type="password" value={ownerPassword} onChange={(event) => setOwnerPassword(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800" />
                            {ownerError && <p className="mt-2 text-xs font-medium text-rose-600">{ownerError}</p>}
                            <div className="mt-6 flex gap-3">
                                <button type="button" onClick={() => setIsOwnerModalOpen(false)} className="flex-1 rounded-lg bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">Batal</button>
                                <button type="submit" disabled={switching || !ownerPassword} className="flex-1 rounded-lg bg-[#304674] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{switching ? "Memeriksa..." : "Masuk Owner"}</button>
                            </div>
                        </motion.form>
                    </div>
                )}
            </AnimatePresence>,
            document.body,
        )}
        </>
    );
}
