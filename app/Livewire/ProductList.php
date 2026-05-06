<?php

namespace App\Livewire;

use App\Models\Item;
use Livewire\Component;
use App\Models\VariantItems;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;


#[Layout('layouts.app', ['title' => 'Store List'])]
class ProductList extends Component
{
    public $stores;
    // public $products;
    public $search = '';

    public $editingHppId = null;
    public $newHppValue = null;

    public function showHppInput($type, $id, $currentHpp)
    {
        $this->editingHppId = $type . '-' . $id;
        $this->newHppValue = $currentHpp;
    }

    public function updateHppVariant($variantId)
    {
        $variant = VariantItems::find($variantId);
        if ($variant) {
            $variant->hpp = $this->newHppValue;
            $variant->save();
            session()->flash('message', 'HPP updated successfully.');
        } else {
            session()->flash('error', 'Variant not found.');
        }
        $this->editingHppId = null;
        $this->newHppValue = null;
    }

    public function updateHppProduk($itemId)
    {
        $item = Item::find($itemId);
        if ($item) {
            $item->hpp = $this->newHppValue;
            $item->save();
            // Refresh products agar view langsung update
            $storeIds = $this->stores->pluck('id');
            $this->products = Item::whereIn('store_id', $storeIds)->latest()->get();
            session()->flash('message', 'HPP updated successfully.');
        } else {
            session()->flash('error', 'Variant not found.');
        }
        $this->editingHppId = null;
        $this->newHppValue = null;
    }

    public function mount()
    {
        // Ambil semua toko milik user yang sedang login
        $this->stores = Auth::user()->stores;

        // Ambil produk hanya untuk toko-toko tersebut
        // $storeIds = $this->stores->pluck('id');
        // $this->products = Item::whereIn('store_id', $storeIds)->latest()->get();
    }

    public function render()
    {
        $user = Auth::user();
        $stores = $user->stores()->get();
        $storeIds = $stores->pluck('id');
        

        // 2. Modifikasi Query
        $products = Item::whereIn('store_id', $storeIds)
            // Fitur SEARCH dimulai di sini
            ->when($this->search, function ($query) {
                // Kita bungkus dalam closure function ($q) agar logika OR tidak merusak filter store_id
                $query->where(function ($q) {
                    $q->where('item_name', 'ilike', '%' . $this->search . '%') // Cari berdasarkan Nama
                        ->orWhere('item_sku', 'ilike', '%' . $this->search . '%'); // Atau cari berdasarkan SKU
                });
            })
            // End Fitur SEARCH
            ->latest()
            ->get();

        return view('livewire.product-list', [
            // 'stores' => $stores,
            'products' => $products,
        ]);
    }
}
