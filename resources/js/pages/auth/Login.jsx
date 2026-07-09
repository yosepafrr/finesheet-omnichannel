import { useState } from "react";
import axios from "axios";

export default function Login() {
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [remember, setRemember] = useState(false);
    const [errors, setErrors] = useState({});
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});

        try {
            // Get CSRF cookie first
            await axios.get("/sanctum/csrf-cookie");
            await axios.post("/login", { email, password, remember });
            // Redirect to React dashboard on success
            window.location.href = "/#/dashboard";
        } catch (err) {
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors || {});
            } else {
                setErrors({
                    email: ["Login gagal. Periksa email dan password Anda."],
                });
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-50 via-blue-50/30 to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-4 py-8 transition-colors duration-300">
            {/* Background decorations */}
            <div className="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                <div className="absolute -top-40 -right-40 w-80 h-80 bg-[#304674]/5 dark:bg-blue-500/5 rounded-full blur-3xl"></div>
                <div className="absolute -bottom-40 -left-40 w-80 h-80 bg-purple-500/5 rounded-full blur-3xl"></div>
            </div>

            <div className="w-full max-w-md relative z-10">
                {/* Logo */}
                <div className="text-center mb-8">
                    <a href="/" className="inline-block group">
                        <span className="text-3xl font-bold tracking-tight items-center flex text-slate-900 dark:text-white group-hover:text-[#304674] dark:group-hover:text-blue-400 transition-colors">
                            <span className="material-symbols-rounded mr-4">
                                arrow_back
                            </span>
                            <span className="font-normal">fine</span>sheet
                        </span>
                    </a>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Masuk ke dashboard Anda
                    </p>
                </div>

                {/* Card */}
                <div className="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-2xl shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 border border-white/50 dark:border-slate-700/50 p-8">
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {/* Email */}
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Email
                            </label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                    <span className="material-symbols-rounded text-xl">
                                        mail
                                    </span>
                                </span>
                                <input
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    className="w-full pl-11 pr-4 py-3 bg-slate-50/80 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-transparent transition-all text-sm"
                                    placeholder="nama@email.com"
                                    required
                                    autoFocus
                                />
                            </div>
                            {errors.email && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.email[0]}
                                </p>
                            )}
                        </div>

                        {/* Password */}
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Password
                            </label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                    <span className="material-symbols-rounded text-xl">
                                        lock
                                    </span>
                                </span>
                                <input
                                    type={showPassword ? "text" : "password"}
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                    className="w-full pl-11 pr-12 py-3 bg-slate-50/80 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-transparent transition-all text-sm"
                                    placeholder="••••••••"
                                    required
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowPassword(!showPassword)
                                    }
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                >
                                    <span className="material-symbols-rounded text-xl">
                                        {showPassword
                                            ? "visibility_off"
                                            : "visibility"}
                                    </span>
                                </button>
                            </div>
                            {errors.password && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.password[0]}
                                </p>
                            )}
                        </div>

                        {/* Remember & Forgot */}
                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={remember}
                                    onChange={(e) =>
                                        setRemember(e.target.checked)
                                    }
                                    className="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-[#304674] dark:text-blue-500 focus:ring-[#304674] dark:focus:ring-blue-500 bg-white dark:bg-slate-900"
                                />
                                <span className="text-sm text-slate-600 dark:text-slate-400">
                                    Ingat saya
                                </span>
                            </label>
                            <a
                                href="/forgot-password"
                                className="text-sm font-medium text-[#304674] dark:text-blue-400 hover:underline"
                            >
                                Lupa password?
                            </a>
                        </div>

                        {/* Submit */}
                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full py-3 bg-[#304674] dark:bg-blue-600 text-white font-bold rounded-xl hover:bg-[#25365a] dark:hover:bg-blue-700 transition-all shadow-lg shadow-[#304674]/20 dark:shadow-blue-900/30 active:scale-[0.98] disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                        >
                            {loading ? (
                                <>
                                    <div className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                                    Memproses...
                                </>
                            ) : (
                                "Masuk"
                            )}
                        </button>
                    </form>

                    {/* Divider */}
                    <div className="my-6 flex items-center gap-3">
                        <div className="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                        <span className="text-xs text-slate-400 dark:text-slate-500 font-medium">
                            atau
                        </span>
                        <div className="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                    </div>

                    {/* Register Link */}
                    <p className="text-center text-sm text-slate-600 dark:text-slate-400">
                        Belum punya akun?{" "}
                        <a
                            href="#/register"
                            className="font-bold text-[#304674] dark:text-blue-400 hover:underline"
                        >
                            Daftar sekarang
                        </a>
                    </p>
                </div>

                {/* Footer */}
                <p className="text-center text-xs text-slate-400 dark:text-slate-500 mt-6">
                    &copy; {new Date().getFullYear()} Finesheet. All rights
                    reserved.
                </p>
            </div>
        </div>
    );
}
