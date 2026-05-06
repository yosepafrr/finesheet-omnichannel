<?php

use App\Livewire\Cashflow;
use App\Livewire\Dashboard;
use App\Livewire\OrderList;
use App\Livewire\StoreList;
use App\Livewire\ProductList;
use App\Livewire\MeetCreators;
use App\Livewire\ProfitTracker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\ShopeeWebhookController;
use App\Http\Controllers\TikTokController;

Route::view('/', 'welcome');



Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');


Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/meet-the-creators', MeetCreators::class)->name('meet.creators');
    Route::get('/profit-tracker', ProfitTracker::class)->name('profit.tracker');
    Route::get('/store-list', StoreList::class)->name('store.list');
    Route::get('/product-list', ProductList::class)->name('product.list');
    Route::get('/order-list', OrderList::class)->name('order.list');
    Route::get('/cashflow', Cashflow::class)->name('cashflow');
});


// SHOPEE AUTHORIZATION
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/connect/shopee', [ShopeeController::class, 'redirectToShopee'])->name('shopee.connect');
    Route::get('/shopee/callback', [ShopeeController::class, 'handleShopeeCallback'])->name('shopee.callback');
});


// UPDATE PRODUCTS
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/shopee/update-product', [ShopeeController::class, 'updateProducts'])->name('shopee.update-product');
});


// GET SHOPEE ORDERS
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/shopee/orders', [ShopeeController::class, 'getShopeeOrders'])->name('shopee.orders');
});


// Tiktok SHOP AUTHORIZATION
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');
    Route::get('/connect/tiktok', [TikTokController::class, 'redirectToTikTok'])->name('tiktok.connect');
});
// WEBHOOK ROUTE
Route::post('/webhook/shopee', [ShopeeWebhookController::class, 'handleWebhook']);

require __DIR__ . '/auth.php';
