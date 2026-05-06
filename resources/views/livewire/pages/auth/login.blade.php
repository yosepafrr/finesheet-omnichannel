<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="min-h-screen flex items-center justify-center bg-white overflow-hidden">
    
    <div class="w-full lg:w-1/2 flex flex-col justify-center items-center px-6 py-12 lg:px-20 animate-fade-in-up">
        
        <div class="w-full max-w-md space-y-8">
            <div class="text-center lg:text-left">
                <div class="flex justify-center lg:justify-start mb-6">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-blue-900/20">
                     <x-application-logo class="w-6 h-6 fill-current text-white" />
                </div>
                </div>
                
                <h2 class="text-3xl font-extrabold text-gray-900 tracking-tight">
                    Welcome Back!
                </h2>
                <p class="mt-2 text-sm text-gray-500">
                    Masukan kredensial Anda untuk mengakses dashboard produksi.
                </p>
            </div>

            <x-auth-session-status class="mb-4" :status="session('status')" />

            <form wire:submit="login" class="space-y-6">
                
                <div class="group">
                    <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 group-focus-within:text-[#304674] transition-colors">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">mail</span>
                        </div>
                        <input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username" 
                            class="block w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="name@example.com">
                    </div>
                    <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                </div>

                <div class="group" x-data="{ show: false }">
                    <div class="flex items-center justify-between mb-2">
                        <label for="password" class="block text-xs font-bold text-gray-500 uppercase tracking-wide group-focus-within:text-[#304674] transition-colors">
                            Password
                        </label>
                    </div>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">lock</span>
                        </div>
                        <input wire:model="form.password" id="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="current-password"
                            class="block w-full pl-12 pr-12 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="••••••••">
                        
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none">
                            <span class="material-symbols-rounded text-lg" x-text="show ? 'visibility_off' : 'visibility'"></span>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                </div>

                <div class="flex items-center justify-between">
                    <label for="remember" class="flex items-center cursor-pointer group">
                        <div class="relative">
                            <input wire:model="form.remember" id="remember" type="checkbox" class="sr-only peer">
                            
                            <div class="w-5 h-5 border-2 border-gray-300 rounded bg-white transition-colors group-hover:border-[#304674] peer-checked:bg-[#304674] peer-checked:border-[#304674]"></div>
                            
                            <svg class="w-3 h-3 text-white absolute top-1 left-1 pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity duration-200" 
                                viewBox="0 0 17 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 5.917L5.724 10.5L16 1.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="ml-2 text-sm text-gray-600 group-hover:text-gray-900 transition-colors">Ingat saya</span>
                    </label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-[#304674] hover:text-blue-800 transition-colors hover:underline">
                            Lupa password?
                        </a>
                    @endif
                </div>

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent rounded-xl text-sm font-bold text-white bg-[#304674] hover:bg-[#25365a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#304674] transition-all duration-200 shadow-lg shadow-blue-900/30 hover:-translate-y-0.5">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <span class="material-symbols-rounded text-blue-300 group-hover:text-white transition-colors">login</span>
                        </span>
                        Masuk ke Akun
                    </button>
                </div>

                <div class="text-center mt-6">
                    <p class="text-sm text-gray-600">
                        Belum punya akun? 
                        <a href="{{ url('/register') }}" wire:navigate class="font-bold text-[#304674] hover:text-blue-800 transition-colors hover:underline">
                            Daftar sekarang
                        </a>
                    </p>
                </div>
            </form>
        </div>
        
        <div class="mt-12 lg:hidden text-center">
            <p class="text-xs text-gray-400">&copy; {{ date('Y') }} Ramdani Konveksi.</p>
        </div>
    </div>

    <div class="hidden lg:block relative w-1/2 h-screen">
        <img class="absolute inset-0 w-full h-full object-cover" 
             src="https://images.unsplash.com/photo-1556905055-8f358a7a47b2?q=80&w=2070&auto=format&fit=crop" 
             alt="Konveksi Background">
        
        <div class="absolute inset-0 bg-gradient-to-t from-[#304674]/90 to-[#304674]/40 mix-blend-multiply"></div>
        
        <div class="absolute inset-0 flex flex-col justify-end p-16 text-white z-10">
            <div class="bg-white/10 backdrop-blur-md p-6 rounded-2xl border border-white/20 shadow-2xl max-w-lg">
                {{-- <div class="flex items-center gap-2 mb-3">
                    <div class="flex -space-x-2">
                        <img class="w-8 h-8 rounded-full border-2 border-white" src="https://ui-avatars.com/api/?name=A&background=random" alt="User">
                        <img class="w-8 h-8 rounded-full border-2 border-white" src="https://ui-avatars.com/api/?name=B&background=random" alt="User">
                        <img class="w-8 h-8 rounded-full border-2 border-white" src="https://ui-avatars.com/api/?name=C&background=random" alt="User">
                    </div>
                    <span class="text-xs font-medium text-blue-100">Bergabung dengan tim produksi</span>
                </div> --}}
                <h3 class="text-2xl font-bold leading-tight mb-2">
                    "Kelola finansial kamu dengan lebih efisien dan terstruktur."
                </h3>
                <p class="text-blue-100 text-sm opacity-90">
                    Sistem Informasi Manajemen Terpadu v2.0
                </p>
            </div>
        </div>
    </div>

</div>