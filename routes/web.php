<?php

use App\Http\Controllers\Api\CashflowController;
use App\Http\Controllers\Api\MasterProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderSyncController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfitController;
use App\Http\Controllers\Api\SkuSyncController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\ShopeeWebhookController;
use App\Http\Controllers\TiktokAdsController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\TiktokWebhookController;
use App\Jobs\SyncShopeeProductJob;
use App\Jobs\SyncTiktokProductJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth Routes (Login, Register, Logout)
require __DIR__.'/auth.php';

// Auth-protected React SPA API Endpoints
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

    Route::prefix('api')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::get('/stores', [StoreController::class, 'index']);
        Route::delete('/stores/{id}', [StoreController::class, 'destroy']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/master-products', [MasterProductController::class, 'index']);
        Route::get('/master-products/components', [MasterProductController::class, 'components']);
        Route::post('/master-products', [MasterProductController::class, 'store']);
        Route::post('/master-products/bulk', [MasterProductController::class, 'bulkStore']);
        Route::post('/master-products/convert-marketplace', [MasterProductController::class, 'convertMarketplace']);
        Route::get('/master-products/variants/selection', [MasterProductController::class, 'selection']);
        Route::put('/master-products/variants/bulk', [MasterProductController::class, 'bulkUpdateVariants']);
        Route::put('/master-products/variants/bulk/data', [MasterProductController::class, 'bulkUpdateData']);
        Route::delete('/master-products/variants/bulk', [MasterProductController::class, 'bulkDestroyVariants']);
        Route::put('/master-products/variants/supplier', [MasterProductController::class, 'updateSuppliers']);
        Route::post('/master-products/variants/bulk/push', [MasterProductController::class, 'bulkPushVariants']);
        Route::get('/master-products/{id}', [MasterProductController::class, 'show']);
        Route::put('/master-products/{id}', [MasterProductController::class, 'update']);
        Route::put('/master-products/{productId}/variants/{variantId}', [MasterProductController::class, 'updateVariant']);
        Route::delete('/master-products/{productId}/variants/{variantId}', [MasterProductController::class, 'destroyVariant']);
        Route::post('/master-products/{productId}/variants/{variantId}/push', [MasterProductController::class, 'pushVariant']);
        Route::delete('/master-products/{id}', [MasterProductController::class, 'destroy']);
        Route::put('/products/{id}/hpp', [ProductController::class, 'updateItemHpp']);
        Route::put('/products/supplier', [ProductController::class, 'updateSuppliers']);
        Route::put('/variants/bulk/hpp', [ProductController::class, 'updateBulkVariantHpp']);
        Route::put('/variants/{id}/hpp', [ProductController::class, 'updateVariantHpp']);

        // SKU Sync Routes
        Route::get('/sku-sync/detect', [SkuSyncController::class, 'detect']);

        Route::post('/sync/products', function (Request $request) {
            $storeId = $request->input('store_id');
            if ($storeId) {
                $store = $request->user()->stores()->find($storeId);
                if ($store) {
                    if ($store->platform === 'Shopee') {
                        dispatch(new SyncShopeeProductJob($storeId))->onQueue('products');
                    } elseif ($store->platform === 'Tiktokshop') {
                        dispatch(new SyncTiktokProductJob($storeId))->onQueue('products');
                    }

                    return response()->json(['message' => 'Product sync started for store '.$store->store_name]);
                }

                return response()->json(['message' => 'Store not found'], 404);
            }

            $request->user()->stores()->get()->each(function ($store) {
                if ($store->platform === 'Shopee') {
                    dispatch(new SyncShopeeProductJob($store->id))->onQueue('products');
                } elseif ($store->platform === 'Tiktokshop') {
                    dispatch(new SyncTiktokProductJob($store->id))->onQueue('products');
                }
            });

            return response()->json(['message' => 'Product sync started']);
        });
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::get('/sync/orders/status', [OrderSyncController::class, 'status']);
        Route::post('/sync/orders', [OrderSyncController::class, 'store']);
        Route::get('/profit-tracker', [ProfitController::class, 'index']);
        Route::get('/profit-tracker/breakdown', [ProfitController::class, 'breakdown']);
        Route::get('/cashflow', [CashflowController::class, 'index']);
        Route::post('/cashflow/integrations/marketplace-escrow', [CashflowController::class, 'integrateMarketplaceEscrow']);
        Route::post('/cashflow/accounts', [CashflowController::class, 'storeAccount']);
        Route::put('/cashflow/accounts/{id}', [CashflowController::class, 'updateAccount']);
        Route::delete('/cashflow/accounts/{id}', [CashflowController::class, 'destroyAccount']);
        Route::post('/cashflow/obligations', [CashflowController::class, 'storeObligation']);
        Route::put('/cashflow/obligations/{id}', [CashflowController::class, 'updateObligation']);
        Route::delete('/cashflow/obligations/{id}', [CashflowController::class, 'destroyObligation']);
        Route::post('/cashflow/transactions', [CashflowController::class, 'storeTransaction']);
        Route::put('/cashflow/transactions/{id}', [CashflowController::class, 'updateTransaction']);
        Route::delete('/cashflow/transactions/{id}', [CashflowController::class, 'destroyTransaction']);
        Route::get('/tiktok-ads/status', [TiktokAdsController::class, 'status']);
        Route::post('/tiktok-ads/sync', [TiktokAdsController::class, 'sync']);
        Route::get('/tiktok-ads/campaigns', [TiktokAdsController::class, 'campaigns']);
        Route::put('/tiktok-ads/accounts/{accountId}/store', [TiktokAdsController::class, 'updateStore']);
        Route::delete('/tiktok-ads/accounts/{accountId}', [TiktokAdsController::class, 'destroyAccount']);

        // Payable Routes
        Route::get('/payable/config', [PayableController::class, 'getConfig']);
        Route::post('/payable/config', [PayableController::class, 'updateConfig']);
        Route::post('/payable/config/duration', [PayableController::class, 'updateDuration']);
        Route::post('/payable/sync', [PayableController::class, 'sync']);
        Route::get('/payable/sync-status', [PayableController::class, 'syncStatus']);
        Route::get('/payable/periods', [PayableController::class, 'getPeriods']);
        Route::post('/payable/periods/manual', [PayableController::class, 'createManualPeriod']);
        Route::delete('/payable/periods/{id}', [PayableController::class, 'destroy']);
        Route::get('/payable/periods/{id}/details', [PayableController::class, 'getPeriodDetails']);
        Route::put('/payable/periods/{id}/status', [PayableController::class, 'updatePaymentStatus']);
        Route::post('/payable/periods/{id}/payments', [PayableController::class, 'addPayment']);
        Route::put('/payable/payments/{id}', [PayableController::class, 'updatePayment']);
        Route::delete('/payable/payments/{id}', [PayableController::class, 'deletePayment']);
        Route::get('/payable/events/search', [PayableController::class, 'searchEvents']);
        Route::post('/payable/events/reassign', [PayableController::class, 'reassignEvents']);

        // Supplier Routes
        Route::get('/payable/suppliers', [PayableController::class, 'getSuppliers']);
        Route::post('/payable/suppliers', [PayableController::class, 'createSupplier']);
        Route::put('/payable/suppliers/{id}', [PayableController::class, 'updateSupplier']);
        Route::delete('/payable/suppliers/{id}', [PayableController::class, 'deleteSupplier']);
        Route::post('/payable/suppliers/onboarding-single', [PayableController::class, 'onboardingSingle']);
        Route::post('/payable/suppliers/onboarding-multiple', [PayableController::class, 'onboardingMultiple']);
        Route::get('/payable/suppliers/products', [PayableController::class, 'getProductsForMapping']);
        Route::post('/payable/suppliers/assign-products', [PayableController::class, 'assignProducts']);

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
    Route::get('/tiktok/callback', [TikTokController::class, 'handleTiktokCallback'])->name('tiktok.callback');
    Route::get('/connect/tiktok', [TikTokController::class, 'redirectToTiktok'])->name('tiktok.connect');
    Route::get('/connect/tiktok-ads', [TiktokAdsController::class, 'redirectToTiktokAds'])->name('tiktok-ads.connect');
    Route::get('/tiktok-ads/callback', [TiktokAdsController::class, 'handleTiktokAdsCallback'])->name('tiktok-ads.callback');
});

// WEBHOOK ROUTE
Route::post('/webhook/shopee', [ShopeeWebhookController::class, 'handleWebhook']);
Route::post('/webhook/tiktok', [TiktokWebhookController::class, 'handleWebhook']);

// React SPA (catch-all for hash routing) - MUST BE AT THE BOTTOM
Route::view('/{any?}', 'react')->where('any', '.*')->name('react');
