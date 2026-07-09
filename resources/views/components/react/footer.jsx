export default function Footer() {
    return (
        <footer className="mt-auto border-t border-gray-200 bg-white/50 backdrop-blur-sm dark:bg-gray-800/50 dark:border-gray-700">
            <div className="mx-auto px-8 py-6">
                <div className="flex flex-col md:flex-row items-center justify-between gap-4">
                    <div className="text-center md:text-left">
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            &copy; {new Date().getFullYear()}
                            <span className="font-bold text-gray-700 dark:text-gray-200 ml-1">
                                Finesheet
                            </span>
                            .
                            <span className="hidden sm:inline ml-1">
                                All rights reserved.
                            </span>
                        </p>

                        <p className="text-[10px] text-gray-400 mt-0.5">
                            E-commerce Omnichannel v1.0
                        </p>
                    </div>

                    <div>
                        <a
                            href="#/meet-the-creators"
                            className="group flex items-center gap-3 px-4 py-2 rounded-full bg-gray-50 hover:bg-[#304674]/5 border border-gray-200 hover:border-[#304674]/20 transition-all duration-300"
                        >
                            <div className="w-6 h-6 rounded-full bg-[#304674] text-white flex items-center justify-center shadow-sm group-hover:scale-110 transition-transform">
                                <span className="material-symbols-rounded text-xs">
                                    code
                                </span>
                            </div>

                            <div className="flex flex-col items-start">
                                <span className="text-[10px] uppercase tracking-wider text-gray-400 font-bold group-hover:text-[#304674] transition-colors">
                                    Built with ❤️ by
                                </span>

                                <span className="text-sm font-bold text-gray-700 group-hover:text-[#304674] transition-colors flex items-center gap-1">
                                    Meet the Creators
                                    <span className="material-symbols-rounded text-base opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300">
                                        arrow_forward
                                    </span>
                                </span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    );
}
