import { useState, useEffect } from "react";
import axios from "axios";
import Sidebar from "../react/sidebar";
import Navbar from "../react/navbar";
import Footer from "../react/footer";

export default function AppLayout({ children }) {
    const [isMobileOpen, setIsMobileOpen] = useState(false);
    const [user, setUser] = useState({ name: "Loading...", email: "" });

    useEffect(() => {
        axios.get("/api/user")
            .then(res => setUser(res.data))
            .catch(err => console.error("Failed to fetch user", err));
    }, []);

    return (
        <div className="flex flex-col h-screen w-full overflow-hidden bg-slate-50 dark:bg-slate-900 transition-colors duration-300">
            {/* 1. Navbar Paling Atas */}
            <div className="w-full flex-none bg-white dark:bg-slate-900 shadow-sm">
                <Navbar onMenuClick={() => setIsMobileOpen(true)} user={user} />
            </div>

            {/* 2. Container Bawah */}
            <div className="flex flex-1 w-full overflow-hidden relative">
                {/* Sidebar di Kiri Bawah */}
                <Sidebar
                    isMobileOpen={isMobileOpen}
                    closeMobile={() => setIsMobileOpen(false)}
                    user={user}
                />

                {/* Main Content Area */}
                <div className="flex-1 min-w-0 flex flex-col overflow-y-auto overflow-x-hidden relative z-10 bg-inherit">
                    <main className="grow shrink-0 p-4 sm:p-6 lg:p-8">
                        {children}
                    </main>
                    <Footer />
                </div>
            </div>
        </div>
    );
}
