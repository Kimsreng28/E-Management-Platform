<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TelegramNotificationController;
use App\Http\Controllers\Api\ReviewController;


// Public Route (accessible without login or authentication)

// Product stats
Route::get('products/stats', [ProductController::class, 'getProductStats']);
// Category routes
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/featured', [CategoryController::class, 'featured']);
Route::get('categories/{slug}', [CategoryController::class, 'show']);

// Product routes
Route::get('products', [ProductController::class, 'getAllProducts']);
Route::get('products/{slug}', [ProductController::class, 'getProduct']);

// Review routes
Route::get('reviews', [ReviewController::class, 'index']);
Route::get('reviews/{review}', [ReviewController::class, 'show']);

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
    Route::match(['get', 'post'], '/telegram/spa-login', [AuthController::class, 'telegramSpaLogin']);
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

    // Order routes
    Route::put('orders/{order}', [OrderController::class, 'updateOrderStatus']);
    Route::delete('orders/{order}', [OrderController::class, 'deleteOrder']);

    // Coupon routes
    Route::get('coupons', [CouponController::class, 'getAllCoupons']);
    Route::post('coupons', [CouponController::class, 'createCoupon']);
    Route::put('coupons/{code}', [CouponController::class, 'updateCoupon']);
    Route::delete('coupons/{code}', [CouponController::class, 'deleteCoupon']);
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

// Routes that require any authenticated user (customer or admin)
Route::middleware('auth:sanctum')->group(function () {
    // Order routes accessible by authenticated users
    Route::get('orders', [OrderController::class, 'getAllOrders']);
    Route::get('orders/{order}', [OrderController::class, 'showOrder']);
    Route::post('orders', [OrderController::class, 'createOrder']);

    // Payment
    Route::get('payments', [PaymentController::class, 'index']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::put('payments/{payment}', [PaymentController::class, 'update']);
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);

    // Cart
    Route::get('cart', [CartController::class, 'getCart']);
    Route::post('cart/items', [CartController::class, 'addItem']);
    Route::put('cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem']);
    Route::post('cart/clear', [CartController::class, 'clearCart']);

    // Coupon routes
    Route::get('coupons/{code}', [CouponController::class, 'getCoupon']);

    // Validate coupon
    Route::post('coupons/validate', [CouponController::class, 'validateCoupon']);

    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Telegram notifications
    Route::post('/telegram-notifications', [TelegramNotificationController::class, 'store']);
    Route::post('/telegram-notifications/{id}/send', [TelegramNotificationController::class, 'send']);
    Route::get('/telegram-notifications', [TelegramNotificationController::class, 'index']);

    // Review routes
    Route::post('reviews', [ReviewController::class, 'store']);
    Route::put('reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);
});