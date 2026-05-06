<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Ramdani Konveksi') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="antialiased font-sans bg-gray-50 text-gray-900 overflow-x-hidden selection:bg-blue-500 selection:text-white">

    <div class="fixed inset-0 -z-10 overflow-hidden pointer-events-none">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob"></div>
        <div class="absolute top-0 right-1/4 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-32 left-1/3 w-96 h-96 bg-pink-300 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-blob animation-delay-4000"></div>
    </div>

    <div class="relative min-h-screen flex flex-col justify-center px-6 lg:px-8">
        
        <nav class="absolute top-0 left-0 right-0 p-6 flex justify-between items-center max-w-7xl mx-auto w-full">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-slate-900 rounded-lg flex items-center justify-center text-white font-bold text-xs">F</div>
                <span class="font-bold text-gray-700 tracking-tight">Finesheet</span>
            </div>
            
            @if (Route::has('login'))
                <div class="flex gap-4">
                    @auth
                        <a href="{{ url('/dashboard') }}" wire:navigate class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="text-sm font-semibold text-gray-900 hover:text-blue-600 transition">Log in</a>
                    @endauth
                </div>
            @endif
        </nav>

        <div class="mx-auto max-w-7xl w-full grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-8 items-center py-12 lg:py-0">
            
            <div x-data="{ show: false }" x-init="setTimeout(() => show = true, 100)" 
                 class="relative z-10 flex flex-col items-center lg:items-start text-center lg:text-left">
                
                <div x-show="show" x-transition:enter="transition ease-out duration-700" x-transition:enter-start="opacity-0 translate-y-10" x-transition:enter-end="opacity-100 translate-y-0">
                    <span class="inline-block py-1 px-3 rounded-full bg-blue-100 text-blue-700 text-xs font-bold tracking-wide mb-4">
                        SYSTEM V.2.0
                    </span>
                    
                    <h1 class="text-4xl lg:text-6xl font-extrabold tracking-tight text-slate-900 mb-6 leading-tight">
                        Kelola Finansial <br>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-purple-600">
                            Lebih Efisien.
                        </span>
                    </h1>
                    
                    <p class="text-lg text-gray-600 mb-8 max-w-lg mx-auto lg:mx-0 leading-relaxed">
                        Wilujeng Sumping di Sistem Informasi Ramdani Konveksi. Pantau stok, profit, cashflow, dan pesanan dari berbagai <br><span class="font-bold">E-Commerce</span> dalam satu dashboard terintegrasi.
                    </p>

                    <div class="flex flex-col sm:flex-row gap-4 w-full sm:w-auto">
                         @if (Route::has('login'))
                            @auth
                                <a href="{{ url('/dashboard') }}" wire:navigate class="px-8 py-4 bg-slate-900 text-white rounded-xl font-semibold shadow-lg shadow-slate-900/20 hover:bg-slate-800 hover:-translate-y-1 transition-all duration-300 w-full sm:w-auto flex justify-center items-center gap-2">
                                    Buka Dashboard
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                    </svg>
                                </a>
                            @else
                                <a href="{{ route('login') }}" wire:navigate class="px-8 py-4 bg-slate-900 text-white rounded-xl font-semibold shadow-lg shadow-slate-900/20 hover:bg-slate-800 hover:-translate-y-1 transition-all duration-300 w-full sm:w-auto">
                                    Masuk Akun
                                </a>
                                <a href="#" class="px-8 py-4 bg-white text-slate-700 border border-gray-200 rounded-xl font-semibold hover:bg-gray-50 hover:border-gray-300 transition-all w-full sm:w-auto">
                                    Pelajari Fitur
                                </a>
                            @endauth
                        @endif
                    </div>
                </div>
            </div>

            <div x-data="{ show: false }" x-init="setTimeout(() => show = true, 300)" 
                 class="relative lg:h-full flex items-center justify-center">
                
                <div x-show="show" x-transition:enter="transition ease-out duration-1000" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" 
                     class="relative w-full max-w-md lg:max-w-full">
                    
                    <div class="absolute inset-0 bg-gradient-to-tr from-blue-100 to-purple-100 rounded-full filter blur-3xl opacity-70 -z-10 transform scale-75"></div>

                    <img src="{{ asset('landing-page-assets/2.png') }}" 
                         alt="Dashboard Illustration" 
                         class="relative z-10 w-full drop-shadow-2xl animate-float mx-auto hover:scale-105 transition-transform duration-500 ease-out">
                    
                    <div class="absolute -bottom-6 -left-6 bg-white/80 backdrop-blur-md p-4 rounded-2xl shadow-xl border border-white/50 animate-bounce" style="animation-duration: 3s;">
                        <div class="flex items-center gap-3">
                            <div class="bg-green-100 p-2 rounded-full">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Total Profit</p>
                                <p class="font-bold text-gray-800">Rp 45.2 Juta</p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <footer class="absolute bottom-4 left-0 right-0 text-center text-xs text-gray-400">
            &copy; {{ date('Y') }} Finesheet. Built with Love and Semangat.
        </footer>
    </div>

</body>
</html>