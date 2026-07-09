import AppLayout from "../../views/components/layouts/AppLayout";

export default function Cashflow() {
    return (
        <AppLayout>
            <div className="min-h-[calc(80vh-64px)] w-full bg-slate-50 dark:bg-slate-900 transition-colors duration-300 flex items-center justify-center p-4">
                <div className="text-center animate-fade-in-up bg-white dark:bg-slate-800 p-12 rounded-3xl shadow-sm border border-gray-100 dark:border-slate-700 max-w-lg w-full">
                    <div className="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-50 dark:bg-green-500/10 mb-6">
                        <span className="material-symbols-rounded text-4xl text-green-500 dark:text-green-400">account_balance_wallet</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-white mb-3">Arus Kas Segera Hadir</h1>
                    <p className="text-gray-500 dark:text-slate-400 text-sm sm:text-base leading-relaxed">
                        Fitur pencatatan keuangan dan sinkronisasi kas sedang dalam tahap pengembangan. Kami akan segera menghadirkannya untuk Anda!
                    </p>
                    <div className="mt-8 flex justify-center">
                        <span className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest bg-gray-100 dark:bg-slate-900 px-4 py-2 rounded-full">
                            Under Construction
                        </span>
                    </div>
                </div>
            </div>

            <style>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up { animation: fadeInUp 0.5s ease-out forwards; }
            `}</style>
        </AppLayout>
    );
}
