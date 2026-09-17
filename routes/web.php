<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\ShopeeWebhookController;
use App\Http\Controllers\TiktokController;
use App\Http\Controllers\DashboardController;

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfitController;

// Auth Routes (Login, Register, Logout)
require __DIR__ . '/auth.php';

// Auth-protected React SPA API Endpoints
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

    Route::prefix('api')->group(function () {
        Route::get('/user', function (\Illuminate\Http\Request $request) {
            return $request->user();
        });
        Route::put('/profile', [\App\Http\Controllers\Api\ProfileController::class, 'update']);
        Route::put('/profile/password', [\App\Http\Controllers\Api\ProfileController::class, 'updatePassword']);
        Route::get('/stores', [\App\Http\Controllers\Api\StoreController::class, 'index']);
        Route::delete('/stores/{id}', [\App\Http\Controllers\Api\StoreController::class, 'destroy']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::put('/products/{id}/hpp', [ProductController::class, 'updateItemHpp']);
        Route::put('/variants/bulk/hpp', [ProductController::class, 'updateBulkVariantHpp']);
        Route::put('/variants/{id}/hpp', [ProductController::class, 'updateVariantHpp']);
        
        // SKU Sync Routes
        Route::get('/sku-sync/groups', [\App\Http\Controllers\Api\SkuSyncController::class, 'index']);
        Route::get('/sku-sync/detect', [\App\Http\Controllers\Api\SkuSyncController::class, 'detect']);
        Route::post('/sku-sync/groups', [\App\Http\Controllers\Api\SkuSyncController::class, 'store']);
        Route::post('/sku-sync/groups/bulk', [\App\Http\Controllers\Api\SkuSyncController::class, 'storeBulk']);
        Route::put('/sku-sync/groups/{id}', [\App\Http\Controllers\Api\SkuSyncController::class, 'update']);
        Route::delete('/sku-sync/groups/{id}', [\App\Http\Controllers\Api\SkuSyncController::class, 'destroy']);
        Route::post('/sku-sync/groups/{id}/push', [\App\Http\Controllers\Api\SkuSyncController::class, 'push']);
        Route::put('/sku-sync/groups/{id}/toggle', [\App\Http\Controllers\Api\SkuSyncController::class, 'toggleActive']);

        Route::post('/sync/products', function (\Illuminate\Http\Request $request) {
            $storeId = $request->input('store_id');
            if ($storeId) {
                $store = \App\Models\Store::find($storeId);
                if ($store) {
                    if ($store->platform === 'Shopee') {
                        dispatch(new \App\Jobs\SyncShopeeProductJob($storeId))->onQueue('products');
                    } elseif ($store->platform === 'Tiktokshop') {
                        dispatch(new \App\Jobs\SyncTiktokProductJob($storeId))->onQueue('products');
                    }
                    return response()->json(['message' => 'Product sync started for store ' . $store->store_name]);
                }
                return response()->json(['message' => 'Store not found'], 404);
            }

            dispatch(new \App\Jobs\SyncShopeeProductJob())->onQueue('products');
            dispatch(new \App\Jobs\SyncTiktokProductJob())->onQueue('products');
            return response()->json(['message' => 'Product sync started']);
        });
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::post('/sync/orders', function (\Illuminate\Http\Request $request) {
            $storeId = $request->input('store_id');
            if ($storeId) {
                $store = \App\Models\Store::find($storeId);
                if ($store) {
                    if ($store->platform === 'Shopee') {
                        dispatch(new \App\Jobs\SyncShopeeOrderJob($storeId))->onQueue('orders');
                    } elseif ($store->platform === 'Tiktokshop') {
                        dispatch(new \App\Jobs\SyncTiktokOrderJob($storeId))->onQueue('orders');
                    }
                    return response()->json(['message' => 'Order sync started for store ' . $store->store_name]);
                }
                return response()->json(['message' => 'Store not found'], 404);
            }

            dispatch(new \App\Jobs\SyncShopeeOrderJob())->onQueue('orders');
            dispatch(new \App\Jobs\SyncTiktokOrderJob())->onQueue('orders');
            return response()->json(['message' => 'Order sync started']);
        });
        Route::get('/profit-tracker', [ProfitController::class, 'index']);

        // Payable Routes
        Route::get('/payable/config', [\App\Http\Controllers\Api\PayableController::class, 'getConfig']);
        Route::post('/payable/config', [\App\Http\Controllers\Api\PayableController::class, 'updateConfig']);
        Route::post('/payable/config/duration', [\App\Http\Controllers\Api\PayableController::class, 'updateDuration']);
        Route::post('/payable/sync', [\App\Http\Controllers\Api\PayableController::class, 'sync']);
        Route::get('/payable/periods', [\App\Http\Controllers\Api\PayableController::class, 'getPeriods']);
        Route::post('/payable/periods/manual', [\App\Http\Controllers\Api\PayableController::class, 'createManualPeriod']);
        Route::delete('/payable/periods/{id}', [\App\Http\Controllers\Api\PayableController::class, 'destroy']);
        Route::get('/payable/periods/{id}/details', [\App\Http\Controllers\Api\PayableController::class, 'getPeriodDetails']);
        Route::put('/payable/periods/{id}/status', [\App\Http\Controllers\Api\PayableController::class, 'updatePaymentStatus']);
        Route::post('/payable/periods/{id}/payments', [\App\Http\Controllers\Api\PayableController::class, 'addPayment']);
        Route::put('/payable/payments/{id}', [\App\Http\Controllers\Api\PayableController::class, 'updatePayment']);
        Route::delete('/payable/payments/{id}', [\App\Http\Controllers\Api\PayableController::class, 'deletePayment']);
        Route::get('/payable/events/search', [\App\Http\Controllers\Api\PayableController::class, 'searchEvents']);
        Route::post('/payable/events/reassign', [\App\Http\Controllers\Api\PayableController::class, 'reassignEvents']);

        // Supplier Routes
        Route::get('/payable/suppliers', [\App\Http\Controllers\Api\PayableController::class, 'getSuppliers']);
        Route::post('/payable/suppliers', [\App\Http\Controllers\Api\PayableController::class, 'createSupplier']);
        Route::put('/payable/suppliers/{id}', [\App\Http\Controllers\Api\PayableController::class, 'updateSupplier']);
        Route::delete('/payable/suppliers/{id}', [\App\Http\Controllers\Api\PayableController::class, 'deleteSupplier']);
        Route::post('/payable/suppliers/onboarding-single', [\App\Http\Controllers\Api\PayableController::class, 'onboardingSingle']);
        Route::post('/payable/suppliers/onboarding-multiple', [\App\Http\Controllers\Api\PayableController::class, 'onboardingMultiple']);
        Route::get('/payable/suppliers/products', [\App\Http\Controllers\Api\PayableController::class, 'getProductsForMapping']);
        Route::post('/payable/suppliers/assign-products', [\App\Http\Controllers\Api\PayableController::class, 'assignProducts']);

    });
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
    Route::get('/tiktok/callback', [TiktokController::class, 'handleTiktokCallback'])->name('tiktok.callback');
    Route::get('/connect/tiktok', [TiktokController::class, 'redirectToTiktok'])->name('tiktok.connect');
});

// WEBHOOK ROUTE
Route::post('/webhook/shopee', [ShopeeWebhookController::class, 'handleWebhook']);
Route::post('/webhook/tiktok', [App\Http\Controllers\TiktokWebhookController::class, 'handleWebhook']);

// React SPA (catch-all for hash routing) - MUST BE AT THE BOTTOM
Route::view('/{any?}', 'react')->where('any', '.*')->name('react');
