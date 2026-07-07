<?php

namespace App\Livewire;

use App\Models\Order;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('components.layouts.app', ['title' => 'Store List'])]
class OrderList extends Component
{
    public $stores;
    public $selectedStatuses = [];
    public $selectedStores = [];

    protected $listeners = ['echo:orders,OrderCreated' => '$refresh'];

    public function mount()
    {
        $this->stores = Auth::user()->stores()->get();
        
        // FIX 1: Load session HANYA di mount, jangan di render
        $this->selectedStatuses = session('selectedStatuses', []);
        $this->selectedStores = session('selectedStores', []);
    }

    // method ini otomatis dipanggil livewire saat properti berubah
    public function updatedSelectedStatuses()
    {
        session(['selectedStatuses' => $this->selectedStatuses]);
    }

    // method ini otomatis dipanggil livewire saat properti berubah
    public function updatedSelectedStores()
    {
        session(['selectedStores' => $this->selectedStores]);
    }

    public function toggleStatusFilter($status)
    {
        if (in_array($status, $this->selectedStatuses)) {
            $this->selectedStatuses = array_diff($this->selectedStatuses, [$status]);
        } else {
            $this->selectedStatuses[] = $status;
        }
        
        // Re-index array supaya rapi (0, 1, 2...)
        $this->selectedStatuses = array_values($this->selectedStatuses);
        
        session(['selectedStatuses' => $this->selectedStatuses]);
    }

    public function toggleStoreFilter($storeId)
    {
        // 1. Jika value kosong (Pilih "Semua Toko"), kosongkan filter
        if (empty($storeId)) {
            $this->selectedStores = [];
        } else {
            // 2. Set array dengan ID baru (Single Select behavior untuk Dropdown)
            $this->selectedStores = [$storeId];
        }

        // Simpan ke session
        session(['selectedStores' => $this->selectedStores]);
    }

    public function render()
    {
        // Kita tidak perlu load Auth::user()->stores() lagi disini karena sudah di mount
        // kecuali jika ada kemungkinan store bertambah realtime saat user membuka halaman
        
        // Ambil semua ID store milik user untuk security (agar user tidak query store orang lain)
        $userStoreIds = $this->stores->pluck('id');

        $query = Order::whereIn('store_id', $userStoreIds)
            ->with('orderItems.item')
            ->latest();

        // Filter Status
        if (!empty($this->selectedStatuses)) {
            $query->whereIn('order_status', $this->selectedStatuses);
        }

        // FIX 2: Sanitasi Array Store ID sebelum masuk Query
        // Hapus value kosong/null/string kosong dari array
        $cleanSelectedStores = array_filter($this->selectedStores, fn($value) => !empty($value));

        if (!empty($cleanSelectedStores)) {
            // Gunakan array yang sudah dibersihkan
            $query->whereIn('store_id', $cleanSelectedStores);
        }

        $orders = $query->get()->sortByDesc('order_time');

        return view('livewire.order-list', [
            'stores' => $this->stores,
            'orders' => $orders,
        ]);
    }
}