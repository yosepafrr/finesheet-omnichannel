import { useState, useEffect } from "react";
import axios from "axios";
import Sidebar from "../react/sidebar";
import Navbar from "../react/navbar";
import Footer from "../react/footer";

export default function AppLayout({ children, isBlurred = false }) {
    const [isMobileOpen, setIsMobileOpen] = useState(false);
    const [user, setUser] = useState({ name: "Loading...", email: "" });

    useEffect(() => {
        axios.get("/api/user")
            .then(res => {
                setUser(res.data);
                if (res.data && res.data.id && typeof window.initEchoForUser === 'function') {
                    window.initEchoForUser(res.data.id);
                }
            })
            .catch(err => console.error("Failed to fetch user", err));
    }, []);

    return (
        <div className="flex flex-col h-screen w-full overflow-hidden bg-slate-50 dark:bg-slate-900 transition-colors duration-300">
            {/* 1. Navbar Paling Atas */}
            <div className={`w-full flex-none bg-white dark:bg-slate-900 shadow-sm transition-all duration-300 relative z-30 lg:z-40 ${isBlurred ? 'blur-sm brightness-75 pointer-events-none select-none' : ''}`}>
                <Navbar onMenuClick={() => setIsMobileOpen(true)} user={user} />
            </div>

            {/* 2. Container Bawah */}
            <div className="flex flex-1 w-full overflow-hidden relative z-10">
                {/* Sidebar di Kiri Bawah — also blurred when modal open */}
                <div className={`relative z-40 lg:z-[90] flex-shrink-0 h-full transition-all duration-300 ${isBlurred ? 'blur-sm brightness-75 pointer-events-none select-none' : ''}`}>
                    <Sidebar
                        isMobileOpen={isMobileOpen}
                        closeMobile={() => setIsMobileOpen(false)}
                        user={user}
                    />
                </div>

                {/* Main Content Area */}
                <div id="main-scroll-container" className="flex-1 min-w-0 flex flex-col overflow-y-auto overflow-x-hidden relative z-0 bg-inherit">
                    <main className="grow shrink-0 p-4 sm:p-6 lg:p-8">
                        {children}
                    </main>
                    <Footer />
                </div>
            </div>
        </div>
    );
}
