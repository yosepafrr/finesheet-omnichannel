<?php

namespace App\Livewire\Layout;

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;

new class extends Component {
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        Log::info('>>> Logout berhasil dipanggil');
        $logout();
        $this->redirect('/', navigate: true);
    }
}; 
?>

<nav x-data="{ scrolled: false }" 
     @scroll.window="scrolled = (window.pageYOffset > 10)"
     :class="scrolled ? 'bg-white/90 backdrop-blur-md shadow-sm border-gray-200' : 'bg-white border-transparent'"
     class="sticky top-0 z-30 w-full transition-all duration-300 border-b">
    
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
            
            <div class="flex items-center gap-4">
                
                <button @click="$dispatch('open-sidebar')" 
                        class="lg:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-500 hover:text-[#304674] hover:bg-gray-100 focus:outline-none transition">
                    <span class="material-symbols-rounded text-2xl">menu</span>
                </button>

                <div class="lg:hidden font-semibold text-gray-700">
                    {{ request()->routeIs('dashboard') ? 'Dashboard' : '' }}
                    {{ request()->routeIs('profile') ? 'Profile' : '' }}
                    {{ request()->routeIs('store.*') ? 'Stores' : '' }}
                    {{ request()->routeIs('product.*') ? 'Products' : '' }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                
                <div class="relative ms-3">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="flex items-center gap-2 transition duration-150 ease-in-out group">
                                <div class="h-9 w-9 rounded-full bg-[#304674] text-white flex items-center justify-center text-sm font-bold shadow-md shadow-blue-900/10 group-hover:shadow-blue-900/20">
                                    {{ substr(auth()->user()->name, 0, 1) }}
                                </div>
                                
                                <div class="hidden md:block text-sm font-medium text-gray-700 group-hover:text-[#304674]">
                                    {{ auth()->user()->name }}
                                </div>
                                
                                <span class="material-symbols-rounded text-gray-400 text-lg hidden md:block group-hover:text-[#304674]">expand_more</span>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/50">
                                <p class="text-xs text-gray-500">Signed in as</p>
                                <p class="text-sm font-bold text-[#304674] truncate">{{ auth()->user()->email }}</p>
                            </div>

                            <x-dropdown-link :href="route('profile')" wire:navigate>
                                Profile Settings
                            </x-dropdown-link>

                            <button wire:click="logout" class="w-full text-start">
                                <x-dropdown-link class="text-red-600 hover:bg-red-50 hover:text-red-700">
                                    Log Out
                                </x-dropdown-link>
                            </button>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>

        </div>
    </div>
</nav>