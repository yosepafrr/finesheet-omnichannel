<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public $name = '';
    public $email = '';
    public $password = '';
    public $password_confirmation = '';

    public function register()
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="min-h-screen flex items-center justify-center bg-white overflow-hidden">
    
    <div class="w-full lg:w-1/2 flex flex-col justify-center items-center px-6 py-12 lg:px-20 animate-fade-in-up">
        
        <div class="w-full max-w-md space-y-6">
            <div class="text-center lg:text-left">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white font-bold text-lg shadow-lg shadow-blue-900/20">
                     <x-application-logo class="w-6 h-6 fill-current text-white" />
                </div>
                
                <h2 class="text-3xl font-extrabold text-gray-900 tracking-tight">
                    Create Account
                </h2>
                <p class="mt-2 text-sm text-gray-500">
                    Bergabung bersama Ramdani Konveksi untuk manajemen yang lebih baik.
                </p>
            </div>

            <form wire:submit.prevent="register" class="space-y-5">
                
                <div class="group">
                    <label for="name" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 group-focus-within:text-[#304674] transition-colors">
                        Full Name
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">account_circle</span>
                        </div>
                        <input wire:model="name" id="name" type="text" name="name" required autofocus autocomplete="name"
                            class="block w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="Full Name">
                    </div>
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div class="group">
                    <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 group-focus-within:text-[#304674] transition-colors">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">mail</span>
                        </div>
                        <input wire:model="email" id="email" type="email" name="email" required autocomplete="username"
                            class="block w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="name@example.com">
                    </div>
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>

                <div class="group" x-data="{ show: false }">
                    <label for="password" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 group-focus-within:text-[#304674] transition-colors">
                        Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">lock</span>
                        </div>
                        <input wire:model="password" id="password" :type="show ? 'text' : 'password'" name="password" required autocomplete="new-password"
                            class="block w-full pl-12 pr-12 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="Create a password">
                        
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none">
                            <span class="material-symbols-rounded text-lg" x-text="show ? 'visibility_off' : 'visibility'"></span>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>

                <div class="group" x-data="{ show: false }">
                    <label for="password_confirmation" class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2 group-focus-within:text-[#304674] transition-colors">
                        Confirm Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="material-symbols-rounded text-gray-400 group-focus-within:text-[#304674] transition-colors">lock_reset</span>
                        </div>
                        <input wire:model="password_confirmation" id="password_confirmation" :type="show ? 'text' : 'password'" name="password_confirmation" required autocomplete="new-password"
                            class="block w-full pl-12 pr-12 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[#304674]/20 focus:border-[#304674] transition-all sm:text-sm" 
                            placeholder="Repeat password">
                        
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none">
                            <span class="material-symbols-rounded text-lg" x-text="show ? 'visibility_off' : 'visibility'"></span>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
                </div>

                <div class="pt-4 space-y-4">
                    <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent rounded-xl text-sm font-bold text-white bg-[#304674] hover:bg-[#25365a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#304674] transition-all duration-200 shadow-lg shadow-blue-900/30 hover:-translate-y-0.5">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <span class="material-symbols-rounded text-blue-300 group-hover:text-white transition-colors">person_add</span>
                        </span>
                        Daftar Sekarang
                    </button>

                    <div class="text-center">
                        <p class="text-sm text-gray-600">
                            Sudah punya akun? 
                            <a href="{{ route('login') }}" wire:navigate class="font-bold text-[#304674] hover:text-blue-800 transition-colors hover:underline">
                                Login disini
                            </a>
                        </p>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="mt-8 lg:hidden text-center pb-6">
            <p class="text-xs text-gray-400">&copy; {{ date('Y') }} Ramdani Konveksi.</p>
        </div>
    </div>

    <div class="hidden lg:block relative w-1/2 h-screen">
        <img class="absolute inset-0 w-full h-full object-cover" 
             src="images/foggy-mountainous-scenery-gloomy-sky.jpg" 
             alt="Teamwork Background">
        
        <div class="absolute inset-0 bg-gradient-to-br from-[#304674]/90 to-purple-900/80 mix-blend-multiply"></div>
        
        <div class="absolute inset-0 flex flex-col justify-center items-center text-center p-12 text-white z-10">
            <div class="max-w-md">
                <div class="mb-6 inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/10 backdrop-blur-md border border-white/20">
                    <span class="material-symbols-rounded text-3xl text-blue-200">rocket_launch</span>
                </div>
                <h3 class="text-3xl font-extrabold leading-tight mb-4">
                    Mulai Perjalanan Bisnis Anda
                </h3>
                <p class="text-blue-100 text-lg opacity-90 leading-relaxed">
                    Bergabunglah dengan platform manajemen konveksi yang terintegrasi. Kelola produksi dari hulu ke hilir dalam satu dashboard.
                </p>
                
                <div class="mt-12 flex gap-4 justify-center">
                    <div class="bg-white/10 backdrop-blur-md p-4 rounded-xl border border-white/10 shadow-xl">
                        <p class="text-2xl font-bold">100%</p>
                        <p class="text-xs text-blue-200 uppercase">Realtime Data</p>
                    </div>
                    <div class="bg-white/10 backdrop-blur-md p-4 rounded-xl border border-white/10 shadow-xl">
                        <p class="text-2xl font-bold">24/7</p>
                        <p class="text-xs text-blue-200 uppercase">Akses Sistem</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>