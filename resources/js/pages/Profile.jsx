import React, { useState, useEffect } from 'react';
import axios from 'axios';
import toast from 'react-hot-toast';
import AppLayout from '../../views/components/layouts/AppLayout';

export default function Profile() {
    const [user, setUser] = useState({ name: '', email: '' });
    const [loading, setLoading] = useState(true);

    const [profileForm, setProfileForm] = useState({ name: '', email: '' });
    const [profileProcessing, setProfileProcessing] = useState(false);
    const [profileErrors, setProfileErrors] = useState({});

    const [passwordForm, setPasswordForm] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [passwordProcessing, setPasswordProcessing] = useState(false);
    const [passwordErrors, setPasswordErrors] = useState({});

    useEffect(() => {
        axios.get('/api/user')
            .then(res => {
                setUser(res.data);
                setProfileForm({ name: res.data.name || '', email: res.data.email || '' });
            })
            .catch(err => {
                console.error("Failed to fetch user data", err);
                if (err?.response?.status !== 401) {
                    toast.error("Gagal memuat profil pengguna");
                }
            })
            .finally(() => setLoading(false));
    }, []);

    const handleProfileSubmit = async (e) => {
        e.preventDefault();
        setProfileProcessing(true);
        setProfileErrors({});

        try {
            const res = await axios.put('/api/profile', profileForm);
            setUser(res.data.user);
            toast.success("Profil berhasil diperbarui!");
        } catch (err) {
            if (err.response?.status === 422) {
                setProfileErrors(err.response.data.errors);
                toast.error("Terdapat kesalahan pada isian form");
            } else {
                toast.error("Terjadi kesalahan sistem");
            }
        } finally {
            setProfileProcessing(false);
        }
    };

    const handlePasswordSubmit = async (e) => {
        e.preventDefault();
        setPasswordProcessing(true);
        setPasswordErrors({});

        try {
            await axios.put('/api/profile/password', passwordForm);
            setPasswordForm({ current_password: '', password: '', password_confirmation: '' });
            toast.success("Password berhasil diperbarui!");
        } catch (err) {
            if (err.response?.status === 422) {
                setPasswordErrors(err.response.data.errors);
                toast.error("Terdapat kesalahan pada isian password");
            } else {
                toast.error("Gagal mengubah password");
            }
        } finally {
            setPasswordProcessing(false);
        }
    };

    if (loading) {
        return (
            <AppLayout>
                <div className="w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 pb-20 animate-pulse">
                    <div className="mx-auto space-y-8">
                        {/* Header Skeleton */}
                        <div>
                            <div className="h-8 bg-slate-200 dark:bg-slate-700 rounded w-48 mb-2"></div>
                            <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-64"></div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                            <div className="md:col-span-2 space-y-8">
                                {/* Profile Info Skeleton */}
                                <div className="bg-white dark:bg-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-slate-700 space-y-6">
                                    <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded w-40"></div>
                                    <div className="space-y-2">
                                        <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-24"></div>
                                        <div className="h-10 bg-slate-200 dark:bg-slate-700 rounded-xl w-full"></div>
                                    </div>
                                    <div className="space-y-2">
                                        <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-28"></div>
                                        <div className="h-10 bg-slate-200 dark:bg-slate-700 rounded-xl w-full"></div>
                                    </div>
                                    <div className="flex justify-end pt-2">
                                        <div className="h-10 bg-slate-200 dark:bg-slate-700 rounded-xl w-36"></div>
                                    </div>
                                </div>
                                {/* Password Form Skeleton */}
                                <div className="bg-white dark:bg-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-slate-700 space-y-6">
                                    <div className="h-6 bg-slate-200 dark:bg-slate-700 rounded w-36"></div>
                                    {[1, 2, 3].map(i => (
                                        <div key={i} className="space-y-2">
                                            <div className="h-4 bg-slate-200 dark:bg-slate-700 rounded w-32"></div>
                                            <div className="h-10 bg-slate-200 dark:bg-slate-700 rounded-xl w-full"></div>
                                        </div>
                                    ))}
                                    <div className="flex justify-end pt-2">
                                        <div className="h-10 bg-slate-200 dark:bg-slate-700 rounded-xl w-36"></div>
                                    </div>
                                </div>
                            </div>
                            
                            {/* Side Info Skeleton */}
                            <div className="md:col-span-1">
                                <div className="bg-gradient-to-br from-[#304674] to-[#1e2d4a] dark:from-blue-900 dark:to-slate-900 rounded-3xl p-6 sm:p-8 shadow-md border border-[#3b558c] dark:border-slate-700 text-center">
                                    <div className="w-24 h-24 mx-auto bg-white/20 dark:bg-white/10 rounded-full mb-4"></div>
                                    <div className="h-6 bg-white/20 dark:bg-white/10 rounded w-3/4 mx-auto mb-2"></div>
                                    <div className="h-4 bg-white/20 dark:bg-white/10 rounded w-1/2 mx-auto mb-6"></div>
                                    <div className="h-10 bg-white/20 dark:bg-white/10 rounded-xl w-full"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <div className="min-h-screen w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300">
                <div className="mx-auto space-y-8 animate-fade-in-up">
                    
                    {/* Header */}
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white tracking-tight">
                            Profil Pengguna
                        </h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
                            Kelola informasi akun dan kata sandi Anda di sini.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                        
                        {/* Profile Info Form */}
                        <div className="md:col-span-2 space-y-8">
                            <div className="bg-white dark:bg-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-slate-700">
                                <h2 className="text-lg font-bold text-gray-800 dark:text-white mb-6">Informasi Profil</h2>
                                
                                <form onSubmit={handleProfileSubmit} className="space-y-5">
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1.5">
                                            Nama Lengkap
                                        </label>
                                        <input
                                            type="text"
                                            value={profileForm.name}
                                            onChange={e => setProfileForm({ ...profileForm, name: e.target.value })}
                                            className={`w-full bg-gray-50 dark:bg-slate-900 border ${profileErrors.name ? 'border-red-500' : 'border-gray-200 dark:border-slate-600'} text-gray-800 dark:text-slate-200 py-2.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-all`}
                                        />
                                        {profileErrors.name && (
                                            <p className="text-red-500 text-sm mt-1">{profileErrors.name[0]}</p>
                                        )}
                                    </div>
                                    
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1.5">
                                            Alamat Email
                                        </label>
                                        <input
                                            type="email"
                                            value={profileForm.email}
                                            onChange={e => setProfileForm({ ...profileForm, email: e.target.value })}
                                            className={`w-full bg-gray-50 dark:bg-slate-900 border ${profileErrors.email ? 'border-red-500' : 'border-gray-200 dark:border-slate-600'} text-gray-800 dark:text-slate-200 py-2.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-all`}
                                        />
                                        {profileErrors.email && (
                                            <p className="text-red-500 text-sm mt-1">{profileErrors.email[0]}</p>
                                        )}
                                    </div>

                                    <div className="flex justify-end pt-2">
                                        <button
                                            type="submit"
                                            disabled={profileProcessing}
                                            className="px-6 py-2.5 bg-[#304674] hover:bg-[#243558] dark:bg-blue-600 dark:hover:bg-blue-700 text-white font-medium rounded-xl transition-colors disabled:opacity-70 disabled:cursor-not-allowed"
                                        >
                                            {profileProcessing ? 'Menyimpan...' : 'Simpan Perubahan'}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            {/* Password Form */}
                            <div className="bg-white dark:bg-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-slate-700">
                                <h2 className="text-lg font-bold text-gray-800 dark:text-white mb-6">Ubah Kata Sandi</h2>
                                
                                <form onSubmit={handlePasswordSubmit} className="space-y-5">
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1.5">
                                            Kata Sandi Saat Ini
                                        </label>
                                        <input
                                            type="password"
                                            value={passwordForm.current_password}
                                            onChange={e => setPasswordForm({ ...passwordForm, current_password: e.target.value })}
                                            className={`w-full bg-gray-50 dark:bg-slate-900 border ${passwordErrors.current_password ? 'border-red-500' : 'border-gray-200 dark:border-slate-600'} text-gray-800 dark:text-slate-200 py-2.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-all`}
                                        />
                                        {passwordErrors.current_password && (
                                            <p className="text-red-500 text-sm mt-1">{passwordErrors.current_password[0]}</p>
                                        )}
                                    </div>
                                    
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1.5">
                                            Kata Sandi Baru
                                        </label>
                                        <input
                                            type="password"
                                            value={passwordForm.password}
                                            onChange={e => setPasswordForm({ ...passwordForm, password: e.target.value })}
                                            className={`w-full bg-gray-50 dark:bg-slate-900 border ${passwordErrors.password ? 'border-red-500' : 'border-gray-200 dark:border-slate-600'} text-gray-800 dark:text-slate-200 py-2.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-all`}
                                        />
                                        {passwordErrors.password && (
                                            <p className="text-red-500 text-sm mt-1">{passwordErrors.password[0]}</p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1.5">
                                            Konfirmasi Kata Sandi Baru
                                        </label>
                                        <input
                                            type="password"
                                            value={passwordForm.password_confirmation}
                                            onChange={e => setPasswordForm({ ...passwordForm, password_confirmation: e.target.value })}
                                            className={`w-full bg-gray-50 dark:bg-slate-900 border ${passwordErrors.password_confirmation ? 'border-red-500' : 'border-gray-200 dark:border-slate-600'} text-gray-800 dark:text-slate-200 py-2.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-[#304674] dark:focus:ring-blue-500 transition-all`}
                                        />
                                    </div>

                                    <div className="flex justify-end pt-2">
                                        <button
                                            type="submit"
                                            disabled={passwordProcessing}
                                            className="px-6 py-2.5 bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white font-medium rounded-xl transition-colors disabled:opacity-70 disabled:cursor-not-allowed"
                                        >
                                            {passwordProcessing ? 'Menyimpan...' : 'Perbarui Kata Sandi'}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {/* Sidebar */}
                        <div className="space-y-6">
                            <div className="bg-white dark:bg-slate-800 rounded-3xl p-6 sm:p-8 shadow-sm border border-gray-100 dark:border-slate-700 flex flex-col items-center text-center">
                                <div className="w-24 h-24 bg-gradient-to-br from-[#304674] to-blue-600 text-white rounded-full flex items-center justify-center text-3xl font-bold shadow-lg mb-4">
                                    {user.name ? user.name.charAt(0).toUpperCase() : 'U'}
                                </div>
                                <h3 className="text-xl font-bold text-gray-800 dark:text-white">
                                    {user.name}
                                </h3>
                                <p className="text-gray-500 dark:text-slate-400 text-sm mt-1">
                                    {user.email}
                                </p>
                                <div className="mt-6 w-full pt-6 border-t border-gray-100 dark:border-slate-700 flex flex-col items-center">
                                    <span className="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wider">
                                        Bergabung Sejak
                                    </span>
                                    <span className="text-sm text-gray-700 dark:text-slate-300 font-medium mt-1">
                                        {user.created_at ? new Date(user.created_at).toLocaleDateString('id-ID', { year: 'numeric', month: 'long', day: 'numeric' }) : '-'}
                                    </span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            
            <style>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up {
                    animation: fadeInUp 0.4s ease-out forwards;
                }
            `}</style>
        </AppLayout>
    );
}
