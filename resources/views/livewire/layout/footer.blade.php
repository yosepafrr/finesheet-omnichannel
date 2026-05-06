<footer class="mt-auto border-t border-gray-200 bg-white/50 backdrop-blur-sm dark:bg-gray-800/50 dark:border-gray-700">
    <div class="mx-auto px-8 py-6">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            
            <div class="text-center md:text-left">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    &copy; {{ date('Y') }} 
                    <span class="font-bold text-gray-700 dark:text-gray-200">Ramdani Konveksi</span>. 
                    <span class="hidden sm:inline">All rights reserved.</span>
                </p>
                <p class="text-[10px] text-gray-400 mt-0.5">
                    Sistem Informasi Manajemen Produksi v2.0
                </p>
            </div>

            <div>
                <a href="{{ url('/meet-the-creators') }}" wire:navigate 
                   class="group flex items-center gap-3 px-4 py-2 rounded-full bg-gray-50 hover:bg-[#304674]/5 border border-gray-200 hover:border-[#304674]/20 transition-all duration-300">
                    
                    <div class="w-6 h-6 rounded-full bg-[#304674] text-white flex items-center justify-center shadow-sm group-hover:scale-110 transition-transform">
                        <span class="material-symbols-rounded text-xs">code</span>
                    </div>

                    <div class="flex flex-col items-start">
                        <span class="text-[10px] uppercase tracking-wider text-gray-400 font-bold group-hover:text-[#304674] transition-colors">
                            Built with ❤️ by
                        </span>
                        <span class="text-sm font-bold text-gray-700 group-hover:text-[#304674] transition-colors flex items-center gap-1">
                            Meet the Creators
                            <span class="material-symbols-rounded text-base opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300">
                                arrow_forward
                            </span>
                        </span>
                    </div>
                </a>
            </div>

        </div>
    </div>
</footer>