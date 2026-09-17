import { useState, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import axios from "axios";
import {
    LogOut,
    LayoutDashboard,
    LineChart,
    Link2,
    Wallet,
    ArrowRight,
    CheckCircle2,
    Activity,
    TrendingUp,
    ShieldCheck,
    Zap,
    BarChart3,
    Package,
    Store,
} from "lucide-react";
import { motion, AnimatePresence, useMotionValue, useTransform } from "framer-motion";

const navigate = (path) => {
    window.location.hash = "#" + path;
};

// ----- Design System -----
// Palette: periwinkle/lavender base (#eef0fa), accent blue (#3a54b0), glass white
// Shape: 20px cards, 999px pills
// Motion: framer-motion, spring physics, useMotionValue for magnetic

const FEATURES = [
    {
        title: "Omnichannel Sync",
        description: "Hubungkan Shopee dan TikTok Shop secara otomatis. Satu dasbor untuk semua channel.",
        icon: Link2,
        accent: "#3a54b0",
        accentBg: "rgba(58,84,176,0.10)",
    },
    {
        title: "Profit Tracker",
        description: "Pantau estimasi profit bersih per produk, per channel, setiap saat.",
        icon: TrendingUp,
        accent: "#0ea87b",
        accentBg: "rgba(14,168,123,0.10)",
    },
    {
        title: "Manajemen Hutang",
        description: "Pencatatan hutang ke supplier otomatis berbasis HPP setiap pesanan masuk.",
        icon: Wallet,
        accent: "#9333ea",
        accentBg: "rgba(147,51,234,0.10)",
    },
    {
        title: "Smart Dashboard",
        description: "Semua metrik bisnis dalam satu layar interaktif yang bisa diakses kapan saja.",
        icon: LayoutDashboard,
        accent: "#e67e22",
        accentBg: "rgba(230,126,34,0.10)",
    },
];

const STATS = [
    { value: "99.9%", label: "Uptime" },
    { value: "2 Menit", label: "Setup Awal" },
    { value: "Real-time", label: "Data Update" },
];

// ----- Glassmorphism card web approximation (see SKILL.md Appendix C)
const glassStyle = {
    background: "linear-gradient(135deg, rgba(255,255,255,0.55), rgba(255,255,255,0.20))",
    backdropFilter: "blur(24px) saturate(180%) contrast(1.03)",
    WebkitBackdropFilter: "blur(24px) saturate(180%) contrast(1.03)",
    border: "1px solid rgba(255,255,255,0.55)",
    boxShadow: "inset 0 1px 0 rgba(255,255,255,0.70), 0 20px 60px rgba(58,84,176,0.08)",
};

const glassNavStyle = (scrolled) => ({
    background: scrolled
        ? "linear-gradient(135deg, rgba(255,255,255,0.80), rgba(255,255,255,0.65))"
        : "transparent",
    backdropFilter: scrolled ? "blur(20px) saturate(160%)" : "none",
    WebkitBackdropFilter: scrolled ? "blur(20px) saturate(160%)" : "none",
    borderBottom: scrolled ? "1px solid rgba(255,255,255,0.50)" : "none",
    boxShadow: scrolled ? "0 4px 30px rgba(58,84,176,0.06)" : "none",
});

// ---- Magnetic CTA Button ----
function MagneticButton({ children, onClick, className, ...rest }) {
    const ref = useRef(null);
    const x = useMotionValue(0);
    const y = useMotionValue(0);
    const translateX = useTransform(x, [-60, 60], [-8, 8]);
    const translateY = useTransform(y, [-30, 30], [-5, 5]);

    const handleMouseMove = (e) => {
        const rect = ref.current.getBoundingClientRect();
        x.set(e.clientX - rect.left - rect.width / 2);
        y.set(e.clientY - rect.top - rect.height / 2);
    };
    const handleMouseLeave = () => {
        x.set(0);
        y.set(0);
    };

    return (
        <motion.button
            ref={ref}
            style={{ translateX, translateY }}
            onMouseMove={handleMouseMove}
            onMouseLeave={handleMouseLeave}
            onClick={onClick}
            className={className}
            whileTap={{ scale: 0.97 }}
            {...rest}
        >
            {children}
        </motion.button>
    );
}

export default function LandingPage() {
    const [scrolled, setScrolled] = useState(false);
    const [authUser, setAuthUser] = useState(null);
    const [authLoading, setAuthLoading] = useState(true);
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);

    useEffect(() => {
        const handleScroll = () => setScrolled(window.scrollY > 24);
        window.addEventListener("scroll", handleScroll, { passive: true });
        return () => window.removeEventListener("scroll", handleScroll);
    }, []);

    useEffect(() => {
        axios
            .get("/api/user")
            .then((res) => setAuthUser(res.data))
            .catch(() => setAuthUser(false))
            .finally(() => setAuthLoading(false));
    }, []);

    const handleLogout = async () => {
        try {
            if (typeof window.cleanupEcho === "function") window.cleanupEcho();
            await axios.post("/logout");
        } catch (error) {
            console.error("Logout failed:", error);
        } finally {
            window.location.href = "/";
            window.location.reload();
        }
    };

    return (
        <div
            className="min-h-screen font-sans overflow-x-hidden"
            style={{
                background: "linear-gradient(145deg, #eef0fa 0%, #e8eaf6 40%, #f0f4ff 70%, #eef2ff 100%)",
                color: "#1a1d3a",
            }}
        >
            {/* â”€â”€ Background Orbs â”€â”€ */}
            <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
                <div
                    className="absolute animate-blob"
                    style={{
                        top: "-8%", left: "-6%",
                        width: "36rem", height: "36rem",
                        background: "radial-gradient(circle, rgba(99,102,241,0.35) 0%, transparent 70%)",
                        borderRadius: "50%", filter: "blur(48px)",
                    }}
                />
                <div
                    className="absolute animate-blob animation-delay-2000"
                    style={{
                        top: "30%", right: "-8%",
                        width: "28rem", height: "28rem",
                        background: "radial-gradient(circle, rgba(139,92,246,0.25) 0%, transparent 70%)",
                        borderRadius: "50%", filter: "blur(56px)",
                    }}
                />
                <div
                    className="absolute animate-blob animation-delay-4000"
                    style={{
                        bottom: "0%", left: "20%",
                        width: "40rem", height: "40rem",
                        background: "radial-gradient(circle, rgba(59,130,246,0.20) 0%, transparent 70%)",
                        borderRadius: "50%", filter: "blur(64px)",
                    }}
                />
            </div>

            {/* â”€â”€ Navbar â”€â”€ */}
            <nav
                className="fixed w-full z-50 transition-all duration-500"
                style={glassNavStyle(scrolled)}
            >
                <div className="max-w-7xl mx-auto px-6 lg:px-10 flex items-center justify-between h-[68px]">
                    {/* Logo */}
                    <div
                        className="flex items-center gap-2 cursor-pointer select-none"
                        onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
                    >
                        <div
                            className="w-8 h-8 rounded-xl flex items-center justify-center"
                            style={{ background: "#3a54b0" }}
                        >
                            <BarChart3 className="w-4 h-4 text-white" />
                        </div>
                        <span className="text-xl font-bold tracking-tight" style={{ color: "#1a1d3a" }}>
                            Fine<span style={{ color: "#3a54b0" }}>sheet</span> <span className="text-xs bg-gradient-to-r from-red-500 to-red-600 bg-clip-text text-transparent">beta test</span>
                        </span>
                    </div>

                    {/* Nav Actions */}
                    <div className="flex items-center gap-3">
                        {authLoading ? (
                            <div className="w-24 h-9 rounded-full animate-pulse" style={{ background: "rgba(255,255,255,0.5)" }} />
                        ) : authUser ? (
                            <div className="flex items-center gap-3">
                                <div
                                    className="flex items-center gap-2 px-4 py-2 rounded-full cursor-pointer transition-all hover:shadow-md"
                                    style={{ ...glassStyle, cursor: "pointer" }}
                                    onClick={() => navigate("/profile")}
                                >
                                    <div
                                        className="w-6 h-6 rounded-full flex items-center justify-center text-white text-xs font-bold"
                                        style={{ background: "#3a54b0" }}
                                    >
                                        {authUser.name ? authUser.name.charAt(0).toUpperCase() : "?"}
                                    </div>
                                    <span className="text-sm font-semibold hidden sm:block" style={{ color: "#1a1d3a" }}>
                                        {authUser.name?.split(" ")[0]}
                                    </span>
                                </div>
                                <button
                                    onClick={() => setIsLogoutModalOpen(true)}
                                    className="p-2 rounded-full transition-colors"
                                    style={{ color: "#94a3b8" }}
                                    title="Logout"
                                >
                                    <LogOut className="w-5 h-5" />
                                </button>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3">
                                <button
                                    onClick={() => navigate("/login")}
                                    className="hidden sm:block px-5 py-2 text-sm font-semibold transition-colors"
                                    style={{ color: "#4b5563" }}
                                >
                                    Masuk
                                </button>
                                <MagneticButton
                                    onClick={() => navigate("/register")}
                                    className="px-5 py-2.5 text-sm font-bold text-white rounded-full transition-all"
                                    style={{ background: "#3a54b0", boxShadow: "0 6px 24px rgba(58,84,176,0.35)" }}
                                >
                                    Mulai Gratis
                                </MagneticButton>
                            </div>
                        )}
                    </div>
                </div>
            </nav>

            {/* â”€â”€ Hero â”€â”€ */}
            <main className="relative z-10 pt-32 pb-24 max-w-7xl mx-auto px-6 lg:px-10">
                <div className="grid lg:grid-cols-2 gap-16 items-center">

                    {/* Left: Copy */}
                    <motion.div
                        initial={{ opacity: 0, y: 28 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
                    >
                        {/* <div
                            className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold mb-6"
                            style={{ background: "rgba(58,84,176,0.10)", color: "#3a54b0", border: "1px solid rgba(58,84,176,0.18)" }}
                        >
                            <Zap className="w-3 h-3" />
                            Platform Manajemen Bisnis Online
                        </div> */}

                        <h1
                            className="text-5xl md:text-6xl font-extrabold tracking-tight mb-6"
                            style={{ color: "#1a1d3a", lineHeight: "1.08" }}
                        >
                            Otomatiskan.{" "}
                            <span
                                className="relative"
                                style={{ color: "#3a54b0" }}
                            >
                                Optimalkan.
                            </span>{" "}
                            Tumbuh Pesat.
                        </h1>

                        <p
                            className="text-lg leading-relaxed mb-10 max-w-[480px]"
                            style={{ color: "#4b5563", fontWeight: 500 }}
                        >
                            Finesheet menyederhanakan alur kerja seller e-commerce, dari sync pesanan hingga pelacakan profit dan manajemen hutang supplier.
                        </p>

                        <div className="flex flex-col sm:flex-row items-start gap-4 mb-10">
                            {authUser ? (
                                <MagneticButton
                                    onClick={() => navigate("/dashboard")}
                                    className="flex items-center gap-2 px-8 py-4 text-base font-bold text-white rounded-full"
                                    style={{ background: "#3a54b0", boxShadow: "0 10px 32px rgba(58,84,176,0.35)" }}
                                >
                                    Buka Dashboard
                                    <ArrowRight className="w-5 h-5" />
                                </MagneticButton>
                            ) : (
                                <>
                                    <MagneticButton
                                        onClick={() => navigate("/register")}
                                        className="flex items-center gap-2 px-8 py-4 text-base font-bold text-white rounded-full"
                                        style={{ background: "#3a54b0", boxShadow: "0 10px 32px rgba(58,84,176,0.35)" }}
                                    >
                                        Mulai Gratis Sekarang
                                        <ArrowRight className="w-5 h-5" />
                                    </MagneticButton>
                                    <button
                                        onClick={() => navigate("/login")}
                                        className="flex items-center gap-2 px-8 py-4 text-base font-bold rounded-full transition-all hover:shadow-lg"
                                        style={{ ...glassStyle, color: "#3a54b0" }}
                                    >
                                        Sudah punya akun
                                    </button>
                                </>
                            )}
                        </div>

                        <div className="flex items-center gap-6 text-sm font-medium" style={{ color: "#64748b" }}>
                            <div className="flex items-center gap-1.5">
                                <CheckCircle2 className="w-4 h-4" style={{ color: "#0ea87b" }} />
                                Tanpa Kartu Kredit
                            </div>
                            <div className="flex items-center gap-1.5">
                                <CheckCircle2 className="w-4 h-4" style={{ color: "#0ea87b" }} />
                                Setup 2 Menit
                            </div>
                            <div className="flex items-center gap-1.5">
                                <ShieldCheck className="w-4 h-4" style={{ color: "#0ea87b" }} />
                                Data Aman
                            </div>
                        </div>
                    </motion.div>

                    {/* Right: Dashboard Preview */}
                    <motion.div
                        initial={{ opacity: 0, y: 32, scale: 0.97 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        transition={{ duration: 0.9, ease: [0.16, 1, 0.3, 1], delay: 0.15 }}
                        className="relative hidden lg:block"
                    >
                        {/* Main glass panel */}
                        <div
                            className="relative rounded-[28px] overflow-hidden"
                            style={{
                                ...glassStyle,
                                padding: "2px",
                                boxShadow: "0 32px 80px rgba(58,84,176,0.15), inset 0 1px 0 rgba(255,255,255,0.80)",
                            }}
                        >
                            {/* Inline Dashboard Mockup */}
                            <div className="p-5 rounded-[26px]" style={{ background: "rgba(255,255,255,0.35)" }}>
                                {/* Top bar */}
                                <div className="flex items-center justify-between mb-4">
                                    <div className="flex items-center gap-2">
                                        <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background: "#3a54b0" }}>
                                            <BarChart3 className="w-3.5 h-3.5 text-white" />
                                        </div>
                                        <div className="h-3 w-20 rounded-full" style={{ background: "rgba(58,84,176,0.15)" }} />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="h-7 w-28 rounded-full" style={{ background: "rgba(58,84,176,0.08)" }} />
                                        <div className="w-7 h-7 rounded-full" style={{ background: "rgba(58,84,176,0.10)" }} />
                                    </div>
                                </div>

                                {/* Metric cards */}
                                <div className="grid grid-cols-3 gap-3 mb-4">
                                    {[
                                        { label: "Pesanan", value: "1,284", color: "#3a54b0" },
                                        { label: "Profit", value: "+24.5Jt", color: "#0ea87b" },
                                        { label: "Hutang", value: "11.2Jt", color: "#9333ea" },
                                    ].map((m, i) => (
                                        <div key={i} className="p-3 rounded-2xl" style={{ background: "rgba(255,255,255,0.60)", border: "1px solid rgba(255,255,255,0.70)" }}>
                                            <p className="text-[10px] font-semibold mb-1" style={{ color: "#94a3b8" }}>{m.label}</p>
                                            <p className="text-base font-black" style={{ color: m.color }}>{m.value}</p>
                                        </div>
                                    ))}
                                </div>

                                {/* Chart area */}
                                <div className="p-4 rounded-2xl mb-3" style={{ background: "rgba(255,255,255,0.55)", border: "1px solid rgba(255,255,255,0.70)" }}>
                                    <p className="text-[10px] font-semibold mb-3" style={{ color: "#64748b" }}>Pertumbuhan Profit (30 Hari)</p>
                                    <div className="flex items-end justify-between gap-1 h-20 px-1">
                                        {[30, 45, 40, 60, 55, 75, 65, 85, 70, 90, 80, 95].map((h, i) => (
                                            <div
                                                key={i}
                                                className="flex-1 rounded-t-md"
                                                style={{
                                                    height: `${h}%`,
                                                    background: i === 11
                                                        ? "#3a54b0"
                                                        : `rgba(58,84,176,${0.15 + (h / 100) * 0.35})`,
                                                }}
                                            />
                                        ))}
                                    </div>
                                </div>

                                {/* Order list */}
                                <div className="space-y-2">
                                    {[
                                        { id: "#FS-2841", platform: "Shopee", status: "Dikirim", amount: "Rp 245k" },
                                        { id: "#FS-2840", platform: "TikTok", status: "Proses", amount: "Rp 180k" },
                                    ].map((o, i) => (
                                        <div key={i} className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background: "rgba(255,255,255,0.55)", border: "1px solid rgba(255,255,255,0.70)" }}>
                                            <div className="flex items-center gap-2">
                                                <div className="w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: i === 0 ? "rgba(14,168,123,0.12)" : "rgba(58,84,176,0.10)" }}>
                                                    <Package className="w-3 h-3" style={{ color: i === 0 ? "#0ea87b" : "#3a54b0" }} />
                                                </div>
                                                <div>
                                                    <p className="text-[10px] font-bold" style={{ color: "#1a1d3a" }}>{o.id}</p>
                                                    <p className="text-[9px]" style={{ color: "#94a3b8" }}>{o.platform}</p>
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-[10px] font-bold" style={{ color: "#1a1d3a" }}>{o.amount}</p>
                                                <p className="text-[9px] font-semibold" style={{ color: i === 0 ? "#0ea87b" : "#3a54b0" }}>{o.status}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Floating stat chip - Growth */}
                        <motion.div
                            animate={{ y: [0, -10, 0] }}
                            transition={{ repeat: Infinity, duration: 4.5, ease: "easeInOut" }}
                            className="absolute -top-6 -left-8 flex items-center gap-3 px-4 py-3 rounded-2xl z-20"
                            style={{ ...glassStyle, boxShadow: "0 12px 40px rgba(58,84,176,0.14), inset 0 1px 0 rgba(255,255,255,0.80)" }}
                        >
                            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: "rgba(14,168,123,0.12)" }}>
                                <TrendingUp className="w-5 h-5" style={{ color: "#0ea87b" }} />
                            </div>
                            <div>
                                <div className="text-[11px] font-semibold" style={{ color: "#64748b" }}>Estimasi Profit</div>
                                <div className="text-base font-black" style={{ color: "#1a1d3a" }}>+350%</div>
                            </div>
                        </motion.div>

                        {/* Floating stat chip - Orders */}
                        <motion.div
                            animate={{ y: [0, 10, 0] }}
                            transition={{ repeat: Infinity, duration: 5.5, ease: "easeInOut", delay: 0.8 }}
                            className="absolute -bottom-6 -right-6 flex items-center gap-3 px-4 py-3 rounded-2xl z-20"
                            style={{ ...glassStyle, boxShadow: "0 12px 40px rgba(58,84,176,0.14), inset 0 1px 0 rgba(255,255,255,0.80)" }}
                        >
                            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ background: "rgba(58,84,176,0.10)" }}>
                                <Package className="w-5 h-5" style={{ color: "#3a54b0" }} />
                            </div>
                            <div>
                                <div className="text-[11px] font-semibold" style={{ color: "#64748b" }}>Pesanan Aktif</div>
                                <div className="text-base font-black" style={{ color: "#1a1d3a" }}>128 Pesanan</div>
                            </div>
                        </motion.div>
                    </motion.div>
                </div>
            </main>

            {/* â”€â”€ Stats Bar â”€â”€ */}
            <section className="relative z-10 py-2 max-w-5xl mx-auto px-6 lg:px-10">
                <div
                    className="rounded-2xl px-8 py-6 grid grid-cols-3 gap-6"
                    style={glassStyle}
                >
                    {STATS.map((stat, i) => (
                        <div key={i} className={`text-center ${i < STATS.length - 1 ? "border-r" : ""}`} style={{ borderColor: "rgba(255,255,255,0.50)" }}>
                            <div className="text-md md:text-3xl font-black mb-1" style={{ color: "#3a54b0" }}>{stat.value}</div>
                            <div className="text-sm font-medium" style={{ color: "#64748b" }}>{stat.label}</div>
                        </div>
                    ))}
                </div>
            </section>

            {/* â”€â”€ Features â”€â”€ */}
            <section id="features" className="relative z-10 py-24 max-w-7xl mx-auto px-6 lg:px-10">
                <div className="mb-14 max-w-2xl">
                    <h2 className="text-3xl md:text-4xl font-extrabold mb-4 tracking-tight" style={{ color: "#1a1d3a" }}>
                        Semua yang Anda butuhkan untuk{" "}
                        <span style={{ color: "#3a54b0" }}>tumbuh</span>
                    </h2>
                    <p className="text-base leading-relaxed" style={{ color: "#64748b", fontWeight: 500, maxWidth: "46ch" }}>
                        Finesheet memadukan otomasi kuat dan wawasan cerdas agar bisnis online Anda berjalan lebih efisien.
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                    {FEATURES.map((feat, idx) => {
                        const Icon = feat.icon;
                        return (
                            <motion.div
                                key={idx}
                                initial={{ opacity: 0, y: 24 }}
                                whileInView={{ opacity: 1, y: 0 }}
                                viewport={{ once: true, amount: 0.25 }}
                                transition={{ duration: 0.55, delay: idx * 0.09, ease: [0.16, 1, 0.3, 1] }}
                                whileHover={{ y: -4, transition: { duration: 0.25 } }}
                                className="group rounded-[20px] p-6 cursor-default"
                                style={{
                                    ...glassStyle,
                                    boxShadow: "inset 0 1px 0 rgba(255,255,255,0.70), 0 8px 32px rgba(58,84,176,0.07)",
                                    transition: "box-shadow 0.25s, transform 0.25s",
                                }}
                            >
                                <div
                                    className="w-12 h-12 rounded-2xl flex items-center justify-center mb-5"
                                    style={{ background: feat.accentBg }}
                                >
                                    <Icon className="w-6 h-6" style={{ color: feat.accent }} />
                                </div>
                                <h3 className="text-base font-bold mb-2" style={{ color: "#1a1d3a" }}>{feat.title}</h3>
                                <p className="text-sm leading-relaxed" style={{ color: "#64748b", fontWeight: 500 }}>
                                    {feat.description}
                                </p>
                            </motion.div>
                        );
                    })}
                </div>
            </section>

            {/* â”€â”€ How It Works â”€â”€ */}
            <section className="relative z-10 py-24 max-w-7xl mx-auto px-6 lg:px-10">
                <div className="grid lg:grid-cols-2 gap-16 items-center">
                    {/* Left: Steps */}
                    <div className="space-y-6">
                        <h2 className="text-3xl md:text-4xl font-extrabold tracking-tight mb-8" style={{ color: "#1a1d3a" }}>
                            Mulai dalam{" "}
                            <span style={{ color: "#3a54b0" }}>3 langkah</span>
                        </h2>
                        {[
                            { step: "01", title: "Hubungkan Toko", desc: "Koneksikan akun Shopee dan TikTok Shop Anda hanya dengan beberapa klik.", icon: Store },
                            { step: "02", title: "Sinkronkan Pesanan", desc: "Finesheet otomatis menarik semua pesanan dan menghitung biaya serta profit.", icon: Activity },
                            { step: "03", title: "Pantau & Tumbuh", desc: "Gunakan dasbor real-time untuk membuat keputusan bisnis yang lebih cerdas.", icon: LineChart },
                        ].map((item, i) => {
                            const Icon = item.icon;
                            return (
                                <motion.div
                                    key={i}
                                    initial={{ opacity: 0, x: -20 }}
                                    whileInView={{ opacity: 1, x: 0 }}
                                    viewport={{ once: true, amount: 0.4 }}
                                    transition={{ duration: 0.5, delay: i * 0.12, ease: [0.16, 1, 0.3, 1] }}
                                    className="flex items-start gap-4 p-5 rounded-[20px]"
                                    style={glassStyle}
                                >
                                    <div
                                        className="w-10 h-10 rounded-xl flex-shrink-0 flex items-center justify-center text-xs font-black"
                                        style={{ background: "#3a54b0", color: "white" }}
                                    >
                                        {item.step}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-base mb-1" style={{ color: "#1a1d3a" }}>{item.title}</h3>
                                        <p className="text-sm leading-relaxed" style={{ color: "#64748b", fontWeight: 500 }}>{item.desc}</p>
                                    </div>
                                </motion.div>
                            );
                        })}
                    </div>

                    {/* Right: Visual metric card */}
                    <motion.div
                        initial={{ opacity: 0, scale: 0.96 }}
                        whileInView={{ opacity: 1, scale: 1 }}
                        viewport={{ once: true, amount: 0.3 }}
                        transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
                        className="rounded-[28px] p-8"
                        style={{
                            ...glassStyle,
                            boxShadow: "0 32px 80px rgba(58,84,176,0.12), inset 0 1px 0 rgba(255,255,255,0.80)",
                        }}
                    >
                        <div className="mb-6">
                            <p className="text-sm font-semibold mb-1" style={{ color: "#64748b" }}>Ringkasan Bisnis</p>
                            <p className="text-xs" style={{ color: "#94a3b8" }}>30 hari terakhir</p>
                        </div>
                        <div className="space-y-4">
                            {[
                                { label: "Total Pesanan", value: "1,284", pct: 85, color: "#3a54b0" },
                                { label: "Escrow Cair", value: "Rp 24.5 Jt", pct: 65, color: "#0ea87b" },
                                { label: "Tagihan Supplier", value: "Rp 11.2 Jt", pct: 40, color: "#9333ea" },
                            ].map((row, i) => (
                                <div key={i}>
                                    <div className="flex justify-between mb-1.5">
                                        <span className="text-sm font-medium" style={{ color: "#4b5563" }}>{row.label}</span>
                                        <span className="text-sm font-bold" style={{ color: "#1a1d3a" }}>{row.value}</span>
                                    </div>
                                    <div className="h-2 rounded-full" style={{ background: "rgba(58,84,176,0.08)" }}>
                                        <motion.div
                                            initial={{ width: 0 }}
                                            whileInView={{ width: `${row.pct}%` }}
                                            viewport={{ once: true }}
                                            transition={{ duration: 1.1, delay: i * 0.15, ease: [0.16, 1, 0.3, 1] }}
                                            className="h-full rounded-full"
                                            style={{ background: row.color }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div
                            className="mt-6 p-4 rounded-2xl flex items-center gap-3"
                            style={{ background: "rgba(58,84,176,0.07)", border: "1px solid rgba(58,84,176,0.12)" }}
                        >
                            <TrendingUp className="w-5 h-5 flex-shrink-0" style={{ color: "#0ea87b" }} />
                            <p className="text-sm font-semibold" style={{ color: "#1a1d3a" }}>
                                Estimasi Profit Bersih:{" "}
                                <span style={{ color: "#0ea87b" }}>Rp 13.3 Jt</span>
                            </p>
                        </div>
                    </motion.div>
                </div>
            </section>

            {/* â”€â”€ CTA Section â”€â”€ */}
            {!authUser && (
                <section className="relative z-10 py-16 max-w-5xl mx-auto px-6 mb-20">
                    <motion.div
                        initial={{ opacity: 0, y: 24 }}
                        whileInView={{ opacity: 1, y: 0 }}
                        viewport={{ once: true, amount: 0.4 }}
                        transition={{ duration: 0.7, ease: [0.16, 1, 0.3, 1] }}
                        className="rounded-[32px] p-12 text-center relative overflow-hidden"
                        style={{
                            background: "linear-gradient(135deg, #3a54b0 0%, #5b21b6 100%)",
                            boxShadow: "0 24px 80px rgba(58,84,176,0.35)",
                        }}
                    >
                        <div
                            className="absolute top-0 right-0 w-64 h-64 rounded-full pointer-events-none"
                            style={{ background: "radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%)", transform: "translate(30%, -30%)" }}
                        />
                        <div
                            className="absolute bottom-0 left-0 w-48 h-48 rounded-full pointer-events-none"
                            style={{ background: "radial-gradient(circle, rgba(255,255,255,0.10) 0%, transparent 70%)", transform: "translate(-30%, 30%)" }}
                        />

                        <div className="relative z-10">
                            <h2 className="text-3xl md:text-4xl font-extrabold text-white mb-4 tracking-tight">
                                Lihat Finesheet beraksi
                            </h2>
                            <p className="text-base text-white/80 mb-10 max-w-xl mx-auto font-medium">
                                Mulai gratis hari ini dan rasakan perbedaannya dalam mengelola bisnis online Anda.
                            </p>
                            <MagneticButton
                                onClick={() => navigate("/register")}
                                className="inline-flex items-center gap-2 px-10 py-4 text-base font-bold rounded-full transition-all"
                                style={{
                                    background: "white",
                                    color: "#3a54b0",
                                    boxShadow: "0 8px 32px rgba(0,0,0,0.18)",
                                }}
                            >
                                Buat Akun Gratis
                                <ArrowRight className="w-5 h-5" />
                            </MagneticButton>
                        </div>
                    </motion.div>
                </section>
            )}

            {/* â”€â”€ Footer â”€â”€ */}
            <footer
                className="relative z-10 py-10 border-t"
                style={{ borderColor: "rgba(58,84,176,0.10)", background: "rgba(255,255,255,0.25)" }}
            >
                <div className="max-w-7xl mx-auto px-6 lg:px-10 flex flex-col md:flex-row justify-between items-center gap-4">
                    <div className="flex items-center gap-2">
                        <div
                            className="w-7 h-7 rounded-lg flex items-center justify-center"
                            style={{ background: "#3a54b0" }}
                        >
                            <BarChart3 className="w-3.5 h-3.5 text-white" />
                        </div>
                        <span className="font-bold" style={{ color: "#1a1d3a" }}>
                            Fine<span style={{ color: "#3a54b0" }}>sheet</span>
                        </span>
                    </div>
                    <p className="text-sm font-medium" style={{ color: "#94a3b8" }}>
                        &copy; {new Date().getFullYear()} Finesheet. Hak Cipta Dilindungi.
                    </p>
                    <div className="flex gap-5 text-sm font-semibold" style={{ color: "#64748b" }}>
                        <a href="#" className="hover:underline" style={{ textDecorationColor: "#3a54b0" }}>Privasi</a>
                        <a href="#" className="hover:underline" style={{ textDecorationColor: "#3a54b0" }}>Syarat Layanan</a>
                    </div>
                </div>
            </footer>

            {/* â”€â”€ Keyframe Styles â”€â”€ */}
            <style>{`
                @keyframes blob {
                    0%   { transform: translate(0px, 0px) scale(1); }
                    33%  { transform: translate(40px, -60px) scale(1.08); }
                    66%  { transform: translate(-30px, 30px) scale(0.94); }
                    100% { transform: translate(0px, 0px) scale(1); }
                }
                .animate-blob {
                    animation: blob 18s infinite alternate ease-in-out;
                }
                .animation-delay-2000 { animation-delay: 3s; }
                .animation-delay-4000 { animation-delay: 6s; }

                @media (prefers-reduced-motion: reduce) {
                    .animate-blob { animation: none; }
                }
            `}</style>

            {/* â”€â”€ Logout Modal â”€â”€ */}
            {typeof document !== "undefined" &&
                createPortal(
                    <AnimatePresence>
                        {isLogoutModalOpen && (
                            <div className="fixed inset-0 z-[500] flex items-center justify-center px-4">
                                <motion.div
                                    initial={{ opacity: 0 }}
                                    animate={{ opacity: 1 }}
                                    exit={{ opacity: 0 }}
                                    className="fixed inset-0"
                                    style={{ background: "rgba(26,29,58,0.45)", backdropFilter: "blur(8px)" }}
                                    onClick={() => setIsLogoutModalOpen(false)}
                                />
                                <motion.div
                                    initial={{ opacity: 0, scale: 0.95, y: 12 }}
                                    animate={{ opacity: 1, scale: 1, y: 0 }}
                                    exit={{ opacity: 0, scale: 0.95, y: 12 }}
                                    transition={{ duration: 0.25, ease: [0.16, 1, 0.3, 1] }}
                                    className="rounded-[24px] w-full max-w-sm p-8 relative z-10"
                                    style={{
                                        ...glassStyle,
                                        background: "rgba(255,255,255,0.92)",
                                        boxShadow: "0 24px 80px rgba(58,84,176,0.15), inset 0 1px 0 rgba(255,255,255,0.90)",
                                    }}
                                >
                                    <div
                                        className="w-14 h-14 rounded-2xl flex items-center justify-center mb-6 mx-auto"
                                        style={{ background: "rgba(239,68,68,0.08)" }}
                                    >
                                        <LogOut className="w-7 h-7" style={{ color: "#ef4444" }} />
                                    </div>
                                    <h3 className="text-xl font-bold text-center mb-2" style={{ color: "#1a1d3a" }}>
                                        Keluar Aplikasi
                                    </h3>
                                    <p className="text-sm text-center mb-8 font-medium" style={{ color: "#64748b" }}>
                                        Apakah Anda yakin ingin keluar dari akun Anda?
                                    </p>
                                    <div className="flex gap-3">
                                        <button
                                            onClick={() => setIsLogoutModalOpen(false)}
                                            className="flex-1 px-4 py-3 text-sm font-bold rounded-xl transition-all"
                                            style={{ background: "rgba(58,84,176,0.08)", color: "#3a54b0" }}
                                        >
                                            Batal
                                        </button>
                                        <button
                                            onClick={handleLogout}
                                            className="flex-1 px-4 py-3 text-sm text-white font-bold rounded-xl transition-all"
                                            style={{ background: "#ef4444", boxShadow: "0 4px 16px rgba(239,68,68,0.30)" }}
                                        >
                                            Ya, Logout
                                        </button>
                                    </div>
                                </motion.div>
                            </div>
                        )}
                    </AnimatePresence>,
                    document.body
                )}
        </div>
    );
}


