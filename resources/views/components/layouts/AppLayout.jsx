import { useState } from "react";
import Sidebar from "../react/sidebar";
import Navbar from "../react/navbar";
import Footer from "../react/footer";

export default function AppLayout({ children }) {
    const [isMobileOpen, setIsMobileOpen] = useState(false);

    return (
        <div className="flex flex-col h-screen overflow-hidden bg-slate-50 dark:bg-slate-900 transition-colors duration-300">
            {/* 1. Navbar Paling Atas */}
            {/* UBAH DI SINI: Turunkan jadi z-30 agar mengalah pada Sidebar yang z-50 */}
            <div className="flex-none relative z-30">
                <Navbar onMenuClick={() => setIsMobileOpen(true)} />
            </div>

            {/* 2. Container Bawah */}
            {/* UBAH DI SINI: Hapus 'relative z-0' agar z-50 milik Sidebar tidak terjebak */}
            <div className="flex flex-1 overflow-hidden">
                <Sidebar
                    isMobileOpen={isMobileOpen}
                    closeMobile={() => setIsMobileOpen(false)}
                />

                <div className="flex flex-col flex-1 overflow-y-auto overflow-x-hidden transition-all duration-300 relative z-10 bg-inherit">
                    <main className="p-4 sm:p-6 lg:p-8 flex-1 min-h-full min-w-full">{children}</main>
                    <Footer />
                </div>
            </div>
        </div>
    );
}
