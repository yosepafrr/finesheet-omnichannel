import { useState } from "react";
import axios from "axios";

export default function Register() {
    const [name, setName] = useState("");
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [passwordConfirmation, setPasswordConfirmation] = useState("");
    const [errors, setErrors] = useState({});
    const [loading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    const getPasswordStrength = (pwd) => {
        if (!pwd) return { label: "", color: "bg-slate-200 dark:bg-slate-700", width: "w-0" };
        
        const hasLetters = /[a-zA-Z]/.test(pwd);
        const hasNumbers = /[0-9]/.test(pwd);
        const hasSymbols = /[^a-zA-Z0-9]/.test(pwd);
        
        if (hasLetters && hasNumbers && hasSymbols) {
            return { label: "Strong", color: "bg-emerald-500", width: "w-full" };
        } else if (hasLetters && hasNumbers) {
            return { label: "Good", color: "bg-blue-500", width: "w-2/3" };
        } else if (hasLetters || hasNumbers || hasSymbols) {
            return { label: "Weak", color: "bg-red-500", width: "w-1/3" };
        }
        
        return { label: "", color: "bg-slate-200 dark:bg-slate-700", width: "w-0" };
    };

    const strength = getPasswordStrength(password);
    
    const isEmailInvalid = email.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    const isPasswordMismatch = passwordConfirmation.length > 0 && password !== passwordConfirmation;

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});

        try {
            await axios.get("/sanctum/csrf-cookie");
            await axios.post("/register", {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
            });
            window.location.href = "/#/dashboard";
        } catch (err) {
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors || {});
            } else {
                setErrors({ email: ["Pendaftaran gagal. Silakan coba lagi."] });
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-50 via-blue-50/30 to-slate-100 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 px-4 py-8 transition-colors duration-300">
            {/* Background decorations */}
            <div className="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                <div className="absolute -top-40 -left-40 w-80 h-80 bg-purple-500/5 rounded-full blur-3xl"></div>
                <div className="absolute -bottom-40 -right-40 w-80 h-80 bg-[#304674]/5 dark:bg-blue-500/5 rounded-full blur-3xl"></div>
            </div>

            <div className="w-full max-w-md relative z-10">
                {/* Logo */}
                <div className="text-center mb-8">
                    <a href="/" className="inline-block group">
                        <span className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white group-hover:text-[#304674] dark:group-hover:text-blue-400 transition-colors">
                            <span className="material-symbols-rounded mr-4">
                                arrow_back
                            </span>
                            <span className="font-normal">fine</span>sheet
                        </span>
                    </a>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Buat akun baru untuk memulai
                    </p>
                </div>

                {/* Card */}
                <div className="bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl rounded-2xl shadow-xl shadow-slate-200/50 dark:shadow-slate-900/50 border border-white/50 dark:border-slate-700/50 p-8">
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {/* Name */}
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Nama Lengkap
                            </label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                    <span className="material-symbols-rounded text-xl">
                                        person
                                    </span>
                                </span>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    className="w-full pl-11 pr-4 py-3 bg-slate-50/80 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-transparent transition-all text-sm"
                                    placeholder="John Doe"
                                    required
                                    autoFocus
                                />
                            </div>
                            {errors.name && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.name[0]}
                                </p>
                            )}
                        </div>

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
                                    className={`w-full pl-11 pr-4 py-3 bg-slate-50/80 dark:bg-slate-900/50 border text-slate-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 transition-all text-sm ${
                                        isEmailInvalid || errors.email
                                        ? "border-red-500 focus:ring-red-500 focus:border-red-500"
                                        : "border-slate-200 dark:border-slate-700 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-transparent"
                                    }`}
                                    placeholder="nama@email.com"
                                    required
                                />
                            </div>
                            {(errors.email || isEmailInvalid) && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.email ? errors.email[0] : "Format email tidak valid"}
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
                                    placeholder="Min 8 karakter, huruf & angka"
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
                            
                            {password && (
                                <div className="mt-2 space-y-1.5">
                                    <div className="flex h-1.5 w-full bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                        <div className={`h-full transition-all duration-300 ${strength.color} ${strength.width}`}></div>
                                    </div>
                                    <p className={`text-xs font-medium text-right ${strength.color.replace('bg-', 'text-')}`}>
                                        {strength.label}
                                    </p>
                                </div>
                            )}

                            {errors.password && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    {errors.password[0]}
                                </p>
                            )}
                        </div>

                        {/* Confirm Password */}
                        <div>
                            <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                Konfirmasi Password
                            </label>
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">
                                    <span className="material-symbols-rounded text-xl">
                                        lock
                                    </span>
                                </span>
                                <input
                                    type={showPassword ? "text" : "password"}
                                    value={passwordConfirmation}
                                    onChange={(e) =>
                                        setPasswordConfirmation(e.target.value)
                                    }
                                    className={`w-full pl-11 pr-4 py-3 bg-slate-50/80 dark:bg-slate-900/50 border text-slate-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 transition-all text-sm ${
                                        isPasswordMismatch
                                        ? "border-red-500 focus:ring-red-500 focus:border-red-500"
                                        : "border-slate-200 dark:border-slate-700 focus:ring-[#304674] dark:focus:ring-blue-500 focus:border-transparent"
                                    }`}
                                    placeholder="Ulangi password"
                                    required
                                />
                            </div>
                            {isPasswordMismatch && (
                                <p className="mt-1.5 text-xs text-red-500">
                                    Password konfirmasi tidak cocok
                                </p>
                            )}
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
                                "Daftar"
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

                    {/* Login Link */}
                    <p className="text-center text-sm text-slate-600 dark:text-slate-400">
                        Sudah punya akun?{" "}
                        <a
                            href="#/login"
                            className="font-bold text-[#304674] dark:text-blue-400 hover:underline"
                        >
                            Masuk di sini
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
