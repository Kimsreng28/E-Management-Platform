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
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\AppearanceSettingsController;
use App\Http\Controllers\Api\BusinessSettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;


// Public Route (accessible without login or authentication)

// Product stats
Route::get('products/stats', [ProductController::class, 'getProductStats']);
Route::get('orders/stats', [OrderController::class, 'getOrderStats']);
Route::get('customers/stats', [CustomerController::class, 'statCustomer']);

// Product QR
Route::get('/products/{slug}/qr-code/{locale?}/{format?}', [ProductController::class, 'generateProductQrCode']);
Route::get('/products/{slug}/qr-details', [ProductController::class, 'getProductQrDetails']);

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
    Route::put('orders/{order}/update-addresses', [OrderController::class, 'updateOrderAddresses']);

    // Coupon routes
    Route::get('coupons', [CouponController::class, 'getAllCoupons']);
    Route::post('coupons', [CouponController::class, 'createCoupon']);
    Route::put('coupons/{code}', [CouponController::class, 'updateCoupon']);
    Route::delete('coupons/{code}', [CouponController::class, 'deleteCoupon']);
});

// For authenticated customer
Route::middleware('auth:sanctum', 'role:customer')->group(function () {

});

// Routes that require any authenticated user (customer or admin)
Route::middleware('auth:sanctum')->group(function () {
    // Address routes
    Route::get('/addresses', [AddressController::class, 'getAllUserAddresses']);
    Route::post('/addresses', [AddressController::class, 'createAddress']);
    Route::get('/addresses/{address}', [AddressController::class, 'getAddressByAddress']);
    Route::put('/addresses/{address}', [AddressController::class, 'updateAddress']);
    Route::delete('/addresses/{address}', [AddressController::class, 'deleteAddress']);
    Route::post('/addresses/{address}/set-default', [AddressController::class, 'setDefaultAddress']);

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update']);
    });

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
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    Route::get('/notification-settings', [NotificationSettingsController::class, 'index']);
    Route::post('/notification-settings', [NotificationSettingsController::class, 'store']);

    // Telegram notifications
    Route::post('/telegram-notifications', [TelegramNotificationController::class, 'store']);
    Route::post('/telegram-notifications/{id}/send', [TelegramNotificationController::class, 'send']);
    Route::get('/telegram-notifications', [TelegramNotificationController::class, 'index']);

    // Review routes
    Route::post('reviews', [ReviewController::class, 'store']);
    Route::put('reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);

    // Security
    Route::prefix('security')->group(function () {
        Route::post('/change-password', [SecurityController::class, 'changePassword']);
        Route::post('/two-factor', [SecurityController::class, 'toggleTwoFactor']);
        Route::get('/sessions', [SecurityController::class, 'getSessions']);
        Route::delete('/sessions/{id}', [SecurityController::class, 'revokeSession']);
    });

    // Appearance settings
    Route::get('/appearance-settings', [AppearanceSettingsController::class, 'index']);
    Route::post('/appearance-settings', [AppearanceSettingsController::class, 'update']);

    // Business Settings
    Route::get('/business-settings', [BusinessSettingController::class, 'index']);
    Route::post('/business-settings', [BusinessSettingController::class, 'store']);

    // Customer
    Route::get('/customers', [CustomerController::class, 'getAllCustomers']);
    Route::get('/customers/{customer}', [CustomerController::class, 'getCustomer']);
    Route::put('/customers/{customer}', [CustomerController::class, 'updateCustomer']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'deleteCustomer']);

    //Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/recent-orders', [DashboardController::class, 'recentOrders']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'lowStock']);
    Route::post('/dashboard/restock', [DashboardController::class, 'restockProduct']);

    //Stock
    Route::prefix('stock')->group(function () {
        Route::get('/', [StockController::class, 'index']);
        Route::get('/low-stock', [StockController::class, 'lowStock']);
        Route::put('/{id}', [StockController::class, 'updateStock']);
        Route::post('/{id}/restock', [StockController::class, 'restock']);
        Route::post('/bulk-update', [StockController::class, 'bulkUpdate']);
    });

    // Report
    Route::prefix('reports')->group(function () {
        Route::get('/sales-overview', [ReportController::class, 'salesOverview']);
        Route::get('/sales', [ReportController::class, 'salesReport']);
        Route::get('/products', [ReportController::class, 'productPerformance']);
        Route::get('/inventory', [ReportController::class, 'inventoryReport']);
        Route::get('/customers', [ReportController::class, 'customerAnalysis']);
        Route::get('/export/{type}', [ReportController::class, 'exportReport']);
    });

    // Search Global
    Route::get('/search', [SearchController::class, 'search']);
});
