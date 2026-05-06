<div x-data="{ 
        sidebarCollapsed: JSON.parse(localStorage.getItem('sidebarCollapsed')) || false,
        openSubmenus: JSON.parse(localStorage.getItem('sidebarState')) || {},
        mobileOpen: false, // State khusus Mobile
        
        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            localStorage.setItem('sidebarCollapsed', JSON.stringify(this.sidebarCollapsed));
            if (this.sidebarCollapsed) this.openSubmenus = {}; 
        },
        toggleMenu(menu) {
            if (this.sidebarCollapsed) {
                this.sidebarCollapsed = false;
                setTimeout(() => { this.openSubmenus[menu] = !this.openSubmenus[menu]; }, 150);
            } else {
                this.openSubmenus[menu] = !this.openSubmenus[menu];
            }
            localStorage.setItem('sidebarState', JSON.stringify(this.openSubmenus));
        }
    }" 
    @open-sidebar.window="mobileOpen = true"
    class="flex flex-col h-full">

    <div x-show="mobileOpen" 
         @click="mobileOpen = false"
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-gray-900/50 z-40 lg:hidden backdrop-blur-sm">
    </div>

    <aside class="fixed inset-y-0 left-0 z-50 flex flex-col h-screen bg-white border-r border-gray-200 shadow-xl lg:shadow-sm transition-all duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-auto"
       :class="{ 
           'translate-x-0': mobileOpen, 
           '-translate-x-full': !mobileOpen,
           'w-64': !sidebarCollapsed, 
           'w-20': sidebarCollapsed
       }">
        <div class="flex items-center justify-between h-16 px-4 border-b border-gray-100 shrink-0">
            <a href="/" class="flex items-center gap-3 whitespace-nowrap w-full overflow-hidden">
                <div class="shrink-0 w-10 h-10 bg-[#304674] text-white rounded-xl flex items-center justify-center shadow-lg shadow-[#304674]/20 transition-all duration-300">
                     <x-application-logo class="w-6 h-6 fill-current text-white" />
                </div>

                <div class="leading-tight transition-all duration-300 origin-left"
                     :class="sidebarCollapsed ? 'opacity-0 w-0' : 'opacity-100 w-auto'">
                    <h1 class="text-sm font-bold text-gray-800">Sistem<br>Informasi</h1>
                </div>
            </a>

            <button @click="mobileOpen = false" class="lg:hidden p-2 text-gray-500 hover:text-red-500">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        
        <button @click="toggleSidebar" 
                class="hidden lg:flex absolute -right-3 top-20 w-6 h-6 bg-white border border-gray-200 rounded-full items-center justify-center text-gray-400 hover:text-[#304674] hover:border-[#304674] shadow-sm transition-colors z-50">
            <span class="material-symbols-rounded text-sm transform transition-transform duration-300"
                  :class="sidebarCollapsed ? 'rotate-180' : ''">
                chevron_left
            </span>
        </button>

        <div class="flex-1 px-3 py-6 space-y-1 overflow-y-auto overflow-x-visible custom-scrollbar">
            
            <a href="{{ route('dashboard') }}" wire:navigate @click="mobileOpen = false"
               class="group flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-200 relative
               {{ request()->routeIs('dashboard') ? 'bg-[#304674] text-white shadow-md shadow-[#304674]/20' : 'text-gray-600 hover:bg-gray-50 hover:text-[#304674]' }}">
                <span class="material-symbols-rounded text-xl shrink-0">space_dashboard</span>
                <span class="font-medium whitespace-nowrap transition-all duration-300 origin-left"
                      :class="sidebarCollapsed ? 'opacity-0 w-0 hidden' : 'opacity-100 w-auto'">
                    Dashboard
                </span>
                <div x-show="sidebarCollapsed" class="fixed left-16 ml-2 bg-gray-900 text-white text-xs px-3 py-2 rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-[9999] pointer-events-none whitespace-nowrap shadow-xl hidden lg:block">
                    Dashboard
                    <div class="absolute top-1/2 -left-1 -mt-1 w-2 h-2 bg-gray-900 transform rotate-45"></div>
                </div>
            </a>

            <a href="{{ route('store.list') }}" wire:navigate @click="mobileOpen = false"
               class="group flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-200 relative
               {{ request()->routeIs('store.list') ? 'bg-[#304674] text-white shadow-md shadow-[#304674]/20' : 'text-gray-600 hover:bg-gray-50 hover:text-[#304674]' }}">
                <span class="material-symbols-rounded text-xl shrink-0">store</span>
                <span class="font-medium whitespace-nowrap transition-all duration-300 origin-left"
                      :class="sidebarCollapsed ? 'opacity-0 w-0 hidden' : 'opacity-100 w-auto'">
                    Your Stores
                </span>
                <div x-show="sidebarCollapsed" class="fixed left-16 ml-2 bg-gray-900 text-white text-xs px-3 py-2 rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-[9999] pointer-events-none whitespace-nowrap shadow-xl hidden lg:block">
                    Your Stores
                    <div class="absolute top-1/2 -left-1 -mt-1 w-2 h-2 bg-gray-900 transform rotate-45"></div>
                </div>
            </a>

            <a href="{{ route('product.list') }}" wire:navigate @click="mobileOpen = false"
               class="group flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-200 relative
               {{ request()->routeIs('product.list') ? 'bg-[#304674] text-white shadow-md shadow-[#304674]/20' : 'text-gray-600 hover:bg-gray-50 hover:text-[#304674]' }}">
                <span class="material-symbols-rounded text-xl shrink-0">apparel</span>
                <span class="font-medium whitespace-nowrap transition-all duration-300 origin-left"
                      :class="sidebarCollapsed ? 'opacity-0 w-0 hidden' : 'opacity-100 w-auto'">
                    Your Products
                </span>
                <div x-show="sidebarCollapsed" class="fixed left-16 ml-2 bg-gray-900 text-white text-xs px-3 py-2 rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-[9999] pointer-events-none whitespace-nowrap shadow-xl hidden lg:block">
                    Your Products
                    <div class="absolute top-1/2 -left-1 -mt-1 w-2 h-2 bg-gray-900 transform rotate-45"></div>
                </div>
            </a>

            <div class="my-4 border-t border-gray-100"></div>

            <div x-data="{ id: 'finance', get expanded() { return this.openSubmenus[this.id] } }">
                <button @click="toggleMenu('finance')"
                    class="w-full group flex items-center justify-between px-3 py-2.5 rounded-lg transition-all duration-200 text-gray-600 hover:bg-gray-50 hover:text-[#304674] relative"
                    :class="expanded ? 'bg-gray-50 text-[#304674]' : ''">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-xl shrink-0">finance_mode</span>
                        <span class="font-medium whitespace-nowrap transition-all duration-300"
                              :class="sidebarCollapsed ? 'opacity-0 w-0 hidden' : 'opacity-100 w-auto'">
                            Finance
                        </span>
                    </div>
                    <span class="material-symbols-rounded text-lg transition-transform duration-300"
                          :class="{'rotate-180': expanded, 'hidden': sidebarCollapsed}">
                        expand_more
                    </span>
                    <div x-show="sidebarCollapsed" class="fixed left-16 ml-2 bg-gray-900 text-white text-xs px-3 py-2 rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-[9999] pointer-events-none whitespace-nowrap shadow-xl hidden lg:block">Finance</div>
                </button>
                
                <div x-show="expanded && !sidebarCollapsed" x-collapse 
                     class="space-y-1 mt-1 relative before:absolute before:left-[1.3rem] before:top-0 before:bottom-0 before:w-px before:bg-gray-200">
                    <a href="{{ route('profit.tracker') }}" wire:navigate @click="mobileOpen = false"
                       class="flex items-center gap-3 pl-10 pr-3 py-2 text-sm rounded-lg transition-colors
                       {{ request()->routeIs('profit.tracker') ? 'text-[#304674] font-semibold bg-[#304674]/5' : 'text-gray-500 hover:text-[#304674]' }}">
                        Profit Tracker
                    </a>
                    <a href="{{ route('cashflow') }}" wire:navigate @click="mobileOpen = false"
                       class="flex items-center gap-3 pl-10 pr-3 py-2 text-sm rounded-lg transition-colors
                       {{ request()->routeIs('cashflow') ? 'text-[#304674] font-semibold bg-[#304674]/5' : 'text-gray-500 hover:text-[#304674]' }}">
                        Cashflow
                    </a>
                </div>
            </div>

            <div x-data="{ id: 'orders', get expanded() { return this.openSubmenus[this.id] } }" class="mt-1">
                <button @click="toggleMenu('orders')"
                    class="w-full group flex items-center justify-between px-3 py-2.5 rounded-lg transition-all duration-200 text-gray-600 hover:bg-gray-50 hover:text-[#304674] relative"
                    :class="expanded ? 'bg-gray-50 text-[#304674]' : ''">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-rounded text-xl shrink-0">shopping_bag</span>
                        <span class="font-medium whitespace-nowrap transition-all duration-300"
                              :class="sidebarCollapsed ? 'opacity-0 w-0 hidden' : 'opacity-100 w-auto'">
                            Orders
                        </span>
                    </div>
                    <span class="material-symbols-rounded text-lg transition-transform duration-300"
                          :class="{'rotate-180': expanded, 'hidden': sidebarCollapsed}">
                        expand_more
                    </span>
                     <div x-show="sidebarCollapsed" class="fixed left-16 ml-2 bg-gray-900 text-white text-xs px-3 py-2 rounded-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-[9999] pointer-events-none whitespace-nowrap shadow-xl hidden lg:block">Orders</div>
                </button>
                <div x-show="expanded && !sidebarCollapsed" x-collapse 
                     class="space-y-1 mt-1 relative before:absolute before:left-[1.3rem] before:top-0 before:bottom-0 before:w-px before:bg-gray-200">
                    <a href="{{ route('order.list') }}" wire:navigate @click="mobileOpen = false"
                       class="flex items-center gap-3 pl-10 pr-3 py-2 text-sm rounded-lg transition-colors
                       {{ request()->routeIs('order.list') ? 'text-[#304674] font-semibold bg-[#304674]/5' : 'text-gray-500 hover:text-[#304674]' }}">
                        Order List
                    </a>
                </div>
            </div>

        </div>

        <div class="p-4 border-t border-gray-100 shrink-0">
            <div class="flex items-center gap-3 transition-all duration-300" :class="sidebarCollapsed ? 'justify-center' : ''">
                 <div class="w-8 h-8 rounded-full bg-[#304674] flex items-center justify-center text-white text-xs font-bold shrink-0">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
                <div class="overflow-hidden whitespace-nowrap" :class="sidebarCollapsed ? 'w-0 opacity-0 hidden' : 'w-auto opacity-100 block'">
                    <p class="text-sm font-semibold text-gray-700 truncate max-w-[140px]">{{ auth()->user()->name }}</p>
                </div>
            </div>
        </div>
    </aside>
</div>