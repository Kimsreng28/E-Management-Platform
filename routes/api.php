<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductController;

// Public Route (accessible without login or authentication)
// Category routes
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/featured', [CategoryController::class, 'featured']);
Route::get('categories/{slug}', [CategoryController::class, 'show']);

// Product routes
Route::get('products', [ProductController::class, 'getAllProducts']);
Route::get('products/{slug}', [ProductController::class, 'getProduct']);

Route::prefix('auth')->group(function () {
    // Auth routes
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // OAuth Google
    Route::get('google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);

    // Telegram login
    Route::get('/telegram/redirect', [AuthController::class, 'telegramRedirect']);
    Route::get('/telegram/callback', [AuthController::class, 'telegramCallback']);
});


Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    // Category routes
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{slug}', [CategoryController::class, 'update']);
    Route::delete('categories/{slug}', [CategoryController::class, 'destroy']);

    // Brand or company routes
    Route::get('companies', [BrandController::class, 'getAllBrand']);
    Route::post('companies', [BrandController::class, 'createBrand']);
    Route::get('companies/{slug}', [BrandController::class, 'getBrand']);
    Route::put('companies/{slug}', [BrandController::class, 'updateBrand']);
    Route::delete('companies/{slug}', [BrandController::class, 'deleteBrand']);

    // Product routes
    Route::post('products', [ProductController::class, 'createProduct']);
    Route::put('products/{slug}', [ProductController::class, 'updateProduct']);
    Route::delete('products/{slug}', [ProductController::class, 'deleteProduct']);
});

// For authenticated customer
Route::middleware('auth:sanctum', 'role:customer')->group(function () {
    // Address routes
    Route::get('/addresses', [AddressController::class, 'getAllUserAddresses']);
    Route::post('/addresses', [AddressController::class, 'createAddress']);
    Route::get('/addresses/{address}', [AddressController::class, 'getAddressByAddress']);
    Route::put('/addresses/{address}', [AddressController::class, 'updateAddress']);
    Route::delete('/addresses/{address}', [AddressController::class, 'deleteAddress']);
    Route::post('/addresses/{address}/set-default', [AddressController::class, 'setDefaultAddress']);
});