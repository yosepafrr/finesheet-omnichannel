<?php

namespace App\Livewire;

use App\Models\Store;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth; // 1. Jangan lupa import Facade Auth

#[Layout('components.layouts.app', ['title' => 'Store List'])]
class StoreList extends Component
{
    public function render()
    {
        // 2. Ambil user yang sedang login
        $user = Auth::user();

        return view('livewire.store-list', [
            // 3. Panggil relasi 'stores' dari user tersebut, lalu urutkan
            // Pastikan di model User.php sudah ada method public function stores() { return $this->hasMany(Store::class); }
            'stores' => $user->stores()->latest()->get(),
        ]);
    }
}