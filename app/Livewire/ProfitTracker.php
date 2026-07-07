<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Order;
use App\Models\Store;

#[Layout('components.layouts.app', ['title' => 'Profit Tracker'])]

class ProfitTracker extends Component
{
    public $stores;
    public $orders;
    public $totalOrderSellingPrice;
    public $totalEscrowAmount;

    protected $listeners = ['echo:orders,OrderCreated' => '$refresh'];

    public function render()
    {
        $this->stores = Auth::user()->stores()->get();

        $storeIds = $this->stores->pluck('id');

        // Load ulang orders setiap render (dipanggil juga saat poll)
        $this->orders = Order::whereIn('store_id', $storeIds)
            ->with('orderItems.item')
            ->latest()
            ->get()
            ->sortByDesc('order_time');

        $this->totalOrderSellingPrice = $this->orders->sum('order_selling_price');
        $this->totalEscrowAmount = $this->orders->sum('escrow_amount');

        // Hitung escrow per store
        $storeEscrowTotal = [];
        foreach ($this->stores as $store) {
            $storeEscrowTotal[$store->id] = $this->orders
                ->where('store_id', $store->id)
                ->sum('escrow_amount');
        }

        return view('livewire.profit-tracker', [
            'stores' => $this->stores,
            'orders' => $this->orders,
            'storeEscrowTotal' => $storeEscrowTotal,
        ]);
    }
}
