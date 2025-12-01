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
use App\Http\Controllers\Api\FAQController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use Illuminate\Support\Facades\Auth;
use App\Events\UserTyping;
use App\Events\UserStopTyping;
use App\Http\Controllers\Api\DeliveryController;
use App\Models\Review;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\AdminController;

// Public Route (accessible without login or authentication)

// FAQ routes
Route::get('/faqs', [FAQController::class, 'index']);
Route::get('/faqs/categories', [FAQController::class, 'categories']);
Route::get('/faqs/category/{categoryId}', [FAQController::class, 'byCategory']);
Route::get('/faqs/search', [FAQController::class, 'search']);
Route::get('/faqs/{id}', [FAQController::class, 'show']);
Route::post('/faqs', [FAQController::class, 'store']);
Route::put('/faqs/{id}', [FAQController::class, 'update']);
Route::delete('/faqs/{id}', [FAQController::class, 'destroy']);

// Contact
Route::post('/contact', [ContactController::class, 'store']);

// Product stats
Route::get('products/stats', [ProductController::class, 'getProductStats']);
Route::get('orders/stats', [OrderController::class, 'getOrderStats']);
Route::get('customers/stats', [CustomerController::class, 'statCustomer']);

// search global
Route::get('/search-products', [SearchController::class, 'searchForProduct']);

// Product QR
Route::get('/products/{slug}/qr-code/{locale?}/{format?}', [ProductController::class, 'generateProductQrCode']);
Route::get('/products/{slug}/qr-details', [ProductController::class, 'getProductQrDetails']);

// Category routes
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/featured', [CategoryController::class, 'featured']);
Route::get('/categories/stats', [CategoryController::class, 'stats']);
Route::get('categories/{slug}', [CategoryController::class, 'show']);
Route::get('categories/{slug}/products', [CategoryController::class, 'products']);

// Product routes
Route::get('/products/popular', [ProductController::class, 'getPopularProducts']);
Route::get('/products/recommended', [ProductController::class, 'getRecommendedProducts']);
Route::get('/products/highly-rated', [ProductController::class, 'getHighlyRatedProducts']);
Route::get('/products/user-reviewed', [ProductController::class, 'getUserReviewedProducts']);

Route::get('products', [ProductController::class, 'getAllProducts']);
Route::get('products/{slug}', [ProductController::class, 'getProduct']);
Route::get('/products/barcode/{barcode}', [ProductController::class, 'getProductByBarcode']);
Route::get('/products/{slug}/barcode', [ProductController::class, 'generateBarcode']);

Route::get('/products/category/{categoryId}', [ProductController::class, 'getByCategory']);
Route::get('/reviews/product/{productId}', [ReviewController::class, 'getByProduct']);
Route::get('/orders/{order}/invoice', [OrderController::class, 'downloadInvoice']);

// brand routes
Route::get('companies', [BrandController::class, 'getAllBrand']);
Route::get('companies/{slug}', [BrandController::class, 'getBrand']);

// Get recent reviews with user information
Route::get('/reviews/recent', [ReviewController::class, 'recent']);

// Get platform statistics
Route::get('/stats/platform', function () {
    $stats = [
        'total_users' => User::where('is_active', true)->count(),
        'total_products' => Product::where('is_active', true)->count(),
        'total_reviews' => Review::where('is_approved', true)->count(),
        'total_orders' => Order::count(),
    ];

    return response()->json([
        'success' => true,
        'data' => $stats
    ]);
});

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
    Route::post('companies', [BrandController::class, 'createBrand']);
    Route::put('companies/{slug}', [BrandController::class, 'updateBrand']);
    Route::delete('companies/{slug}', [BrandController::class, 'deleteBrand']);

    Route::get('/products/export/csv', [ProductController::class, 'exportProducts']);

    // Product routes
    Route::put('products/{slug}', [ProductController::class, 'updateProduct']);
    Route::delete('products/{slug}', [ProductController::class, 'deleteProduct']);
    Route::post('/products/import', [ProductController::class, 'importProducts']);

    // Order routes
    Route::delete('orders/{order}', [OrderController::class, 'deleteOrder']);
    Route::put('orders/{order}/update-addresses', [OrderController::class, 'updateOrderAddresses']);

    // Coupon routes
    Route::get('coupons', [CouponController::class, 'getAllCoupons']);
    Route::post('coupons', [CouponController::class, 'createCoupon']);
    Route::put('coupons/{code}', [CouponController::class, 'updateCoupon']);
    Route::delete('coupons/{code}', [CouponController::class, 'deleteCoupon']);

    // User role management
    Route::post('/users/{user}/assign-role', [AdminController::class, 'assignRole']);
    Route::get('/permissions', [AdminController::class, 'getPermissions']);
    Route::get('/roles/{role}/permissions', [AdminController::class, 'getRolePermissions']);

    Route::get('admin/reviews/stats', [ReviewController::class, 'adminStats']);
});

// Vendor routes
Route::middleware(['auth:sanctum', 'role:vendor'])->group(function () {
    // Vendor sees only their products
    Route::get('vendor/products', [ProductController::class, 'getVendorProducts']);
    // Vendor stats
    Route::get('vendor/products/stats', [ProductController::class, 'getVendorProductsStats']);
    // Review Vendor
    Route::get('vendor/reviews', [ReviewController::class, 'vendorReviews']);
    Route::get('vendor/reviews/stats', [ReviewController::class, 'stats']);
});

Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->name('verification.send');

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify')
    ->middleware(['signed']);

// Routes that require any authenticated user (customer or admin)
Route::middleware('auth:sanctum')->group(function () {
    // Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
    //     ->name('verification.send');

    Route::get('/email/verify', [EmailVerificationController::class, 'checkVerificationStatus'])
        ->name('verification.status');

    // Product route
    Route::post('products', [ProductController::class, 'createProduct']);

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
    Route::post('orders', [OrderController::class, 'createOrder']);
    Route::get('orders', [OrderController::class, 'getAllOrders']);
    Route::get('orders/{order}', [OrderController::class, 'showOrder']);
    Route::post('orders/preview', [OrderController::class, 'previewOrder']);
    Route::put('orders/{order}', [OrderController::class, 'updateOrderStatus']);
    Route::post('orders/{order}/complete', [OrderController::class, 'completeOrder']);


    // Wishlist products
    Route::get('wishlist', [WishlistController::class, 'index']);
    Route::post('wishlist', [WishlistController::class, 'store']);
    Route::delete('wishlist/{productId}', [WishlistController::class, 'destroy']);

    // Payment
    Route::get('payments', [PaymentController::class, 'index']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::put('payments/{payment}', [PaymentController::class, 'update']);
    Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);
    Route::post('/payments/check-khqr-status', [PaymentController::class, 'checkKhqrPaymentStatus']);

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

    // Review replies
    Route::post('reviews/{review}/reply', [ReviewController::class, 'reply']);
    Route::delete('reviews/{review}/replies/{replyId}', [ReviewController::class, 'deleteReply']);

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
    Route::get('/business-settings/user-role', [BusinessSettingController::class, 'getUserRole']);

    // Customer
    Route::get('/customers', [CustomerController::class, 'getAllCustomers']);
    Route::get('/customers/vendors', [CustomerController::class, 'getAllVendors']);
    Route::get('/customers/deliveries', [CustomerController::class, 'getAllDeliveries']);
    Route::post('/customers/{id}/assign-role', [CustomerController::class, 'assignRole']);
    Route::get('/customers/{customer}', [CustomerController::class, 'getCustomer']);
    Route::put('/customers/{customer}', [CustomerController::class, 'updateCustomer']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'deleteCustomer']);

    //Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/recent-orders', [DashboardController::class, 'recentOrders']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'lowStock']);
    Route::post('/dashboard/restock', [DashboardController::class, 'restockProduct']);
    Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData']);

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

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/find-or-create/{userId}', [ConversationController::class, 'findOrCreateConversation']);
    Route::get('/delivery-agents', [ConversationController::class, 'getDeliveryAgents']);
    Route::get('/customers-agents', [ConversationController::class, 'getCustomers']);

    // Messages
    Route::post('/conversations/{conversation}/messages', [ChatController::class, 'sendMessage']);
    Route::post('/conversations/{conversation}/read', [ChatController::class, 'markAsRead']);

    Route::post('/conversations/{conversation}/messages/{message}/update', [ChatController::class, 'updateMessage']);
    Route::delete('/conversations/{conversation}/messages/{message}', [ChatController::class, 'deleteMessage']);

    Route::post('/conversations/{conversation}/typing', function ($conversationId) {
        $user = Auth::user();
        broadcast(new UserTyping($conversationId, $user->id, $user->name));
        return response()->json(['status' => 'typing']);
    });

    Route::post('/conversations/{conversation}/stop-typing', function ($conversationId) {
        $user = Auth::user();
        broadcast(new UserStopTyping($conversationId, $user->id, $user->name));
        return response()->json(['status' => 'stopped typing']);
    });

    Route::post('/conversations/{conversation}/initiate-call', [ChatController::class, 'initiateCall']);
    Route::post('/conversations/{conversation}/accept-call', [ChatController::class, 'acceptCall']);
    Route::post('/conversations/{conversation}/reject-call', [ChatController::class, 'rejectCall']);
    Route::post('/conversations/{conversation}/end-call', [ChatController::class, 'endCall']);

    // Delivery routes
    Route::get('/delivery/agents', [DeliveryController::class, 'getAvailableAgents']); // Delivery Agent

    // Delivery routes for admin
    Route::post('/orders/{order}/assign-delivery', [DeliveryController::class, 'assignDelivery']);
    Route::post('/deliveries/{delivery}/update-status', [DeliveryController::class, 'updateStatus']);
    Route::get('/deliveries/{delivery}/tracking', [DeliveryController::class, 'getTracking']); // share route
    Route::post('/deliveries/{delivery}/accept', [DeliveryController::class, 'acceptDelivery']); // Delivery Agent

    // Delivery routes for customer
    Route::post('/deliveries/{delivery}/confirm-receipt', [DeliveryController::class, 'confirmReceipt']);
    Route::get('/delivery-preferences', [DeliveryController::class, 'getPreferences']);
    Route::put('/delivery-preferences', [DeliveryController::class, 'updatePreferences']);
    Route::get('/deliveries/{delivery}/agent-location', [DeliveryController::class, 'getAgentLocation']);
    Route::post('/deliveries/{delivery}/update-location', [DeliveryController::class, 'updateAgentLocation']); // Delivery Agent
    Route::get('/orders/{order}/delivery', [DeliveryController::class, 'getDeliveryInfo']);
    Route::get('/deliveries/{delivery}/options', [DeliveryController::class, 'getDeliveryOptions']);
    Route::get('/deliveries/active', [DeliveryController::class, 'getActiveDeliveries']);
    Route::get('/deliveries/{id}', [DeliveryController::class, 'getDeliveryById']);

    // Conversation routes for delivery communication
    Route::post('/deliveries/{delivery}/start-conversation', [ConversationController::class, 'startDeliveryConversation']);
});

// Broadcast::routes([
//     'prefix' => 'broadcasting',
//     'middleware' => ['auth:sanctum'],
// ]);

Broadcast::routes(['middleware' => ['auth:api']]);
