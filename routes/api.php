<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StoreController;

Route::get('/stores', [StoreController::class, 'index']);