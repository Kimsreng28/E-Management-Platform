<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Services\NotificationService;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class OrderController
{
    protected NotificationService $notify;

    // Cache durations in seconds
    private $cacheDurations = [
        'order_stats' => 300, // 5 minutes
        'order_list' => 600, // 10 minutes (orders change frequently)
        'order_detail' => 900, // 15 minutes
        'order_preview' => 300, // 5 minutes
    ];

    public function __construct(NotificationService $notify)
    {
        $this->notify = $notify;
    }

    private function clearOrderCaches($orderId = null)
    {
        // Clear main caches
        Cache::forget('order:stats');
        Cache::forget('orders:list');

        // Clear order-specific caches
        if ($orderId) {
            Cache::forget("order:{$orderId}");
            Cache::forget("order:invoice:{$orderId}");
        }

        // Clear all order listing caches with patterns
        $patterns = ['*orders:*', '*order:*', '*order:stats*'];

        foreach ($patterns as $pattern) {
            try {
                $keys = Cache::getRedis()->keys($pattern);
                foreach ($keys as $key) {
                    $cleanKey = str_replace(config('database.redis.options.prefix', 'laravel_database_'), '', $key);
                    Cache::forget($cleanKey);
                }
            } catch (\Exception $e) {
                // Fallback: clear the entire cache if pattern matching fails
                Cache::flush();
                break;
            }
        }
    }

    // Preview order
    public function previewOrder(Request $request)
    {
        $cacheKey = 'order:preview:' . md5(serialize($request->all()));

        $previewData = Cache::remember($cacheKey, $this->cacheDurations['order_preview'], function () use ($request) {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'coupon_code' => 'nullable|string',
            ]);

            $settings = BusinessSetting::where('user_id', Auth::id())->first();

            $taxRate = $settings->tax_rate ?? 0.1; // fallback to 10%
            $shippingCost = $settings->default_shipping_cost ?? 0.1;

            $subtotal = 0;
            $totalProductDiscount = 0; // Track discount from products
            $itemsPreview = [];

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);

                $discount = $product->discount ?? 0; // 0% if no discount
                $discountPrice = round($product->price * (1 - ($discount / 100)), 2);

                $totalPriceWithoutDiscount = $product->price * $item['quantity'];
                $totalPriceWithDiscount = $discountPrice * $item['quantity'];

                $subtotal += $totalPriceWithDiscount;
                $totalProductDiscount += $totalPriceWithoutDiscount - $totalPriceWithDiscount;

                $itemsPreview[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit_price' => $product->price,
                    'discount' => $discount,
                    'discount_price' => $discountPrice,
                    'quantity' => $item['quantity'],
                    'total_price' => $totalPriceWithDiscount,
                ];
            }

            // Coupon discount
            $couponDiscount = 0.0;
            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', $validated['coupon_code'])->first();
                if ($coupon && $coupon->isValid()) {
                    if ($coupon->type === 'fixed') {
                        $couponDiscount = $coupon->value;
                    } elseif ($coupon->type === 'percentage') {
                        $couponDiscount = $subtotal * ($coupon->value / 100);
                    }
                }
            }

            $taxAmount = $subtotal * ($taxRate / 100);
            $total = $subtotal + $taxAmount + $shippingCost - $couponDiscount;
            if ($total < 0) $total = 0;

            return [
                'items' => $itemsPreview,
                'subtotal' => round($subtotal, 2),
                'shipping_cost' => round($shippingCost, 2),
                'tax_amount' => round($taxAmount, 2),
                'product_discount' => round($totalProductDiscount, 2),
                'coupon_discount' => round($couponDiscount, 2),
                'total' => round($total, 2),
            ];
        });

        return response()->json($previewData);
    }

    // List orders for the authenticated user and admin
    public function getAllOrders(Request $request)
    {
        $cacheKey = 'orders:list:' . md5(serialize($request->all()));

        $orders = Cache::remember($cacheKey, $this->cacheDurations['order_list'], function () use ($request) {
            $query = Order::with(['items.product.images', 'user', 'payments']);

            // Search
            if($request->filled('search')) {
                $search = $request->search;

                $query->where(function($q) use ($search) {
                    $q->where('id', 'LIKE', "%{$search}%")
                      ->orWhere('status', 'LIKE', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('name', 'LIKE', "%{$search}%")
                                   ->orWhere('email', 'LIKE', "%{$search}%");
                      })
                      ->orWhereHas('items', function($itemQuery) use ($search) {
                          $itemQuery->where('product_name', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Filtering by status (pending, processing, shipped, completed, cancelled)
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filtering by payment (paid, pending, failed, refunded)
            if ($request->filled('payment_status')) {
                $query->whereHas('payments', function ($q) use ($request) {
                    $q->where('status', $request->payment_status);
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            if (in_array($sortBy, ['id', 'order_number', 'status', 'total', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('created_at', 'desc'); // default
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            return $query->paginate($perPage);
        });

        return response()->json($orders);
    }

    // Create a new order with items
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required|exists:addresses,id',
            'billing_address_id' => 'nullable|exists:addresses,id',
            'notes' => 'nullable|string',
            'coupon_code' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable|array',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            // Get business settings for the current user
            $settings = BusinessSetting::where('user_id', Auth::id())->first();

            $taxRate = $settings->tax_rate ?? 0.1; // fallback to 10%
            $shippingCost = $settings->default_shipping_cost ?? 0.1; // optional field

            $subtotal = 0;

            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::findOrFail($item['product_id']);

                // Check stock
                if ($product->stock < $item['quantity']) {
                    return response()->json([
                        'success' => false,
                        'message' => "Not enough stock for product: {$product->name}"
                    ], 400);
                }

                // Use discounted price if product has a discount
                $unitPrice = $product->discounted_price;
                $subtotal += $unitPrice * $item['quantity'];
            }

            // Coupon handling
            $discountAmount = 0.00;
            $couponId = null;

            if (!empty($validated['coupon_code'])) {
                $coupon = Coupon::where('code', $validated['coupon_code'])->first();

                if (!$coupon || !$coupon->isValid()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or expired coupon'
                    ], 400);
                }

                $couponId = $coupon->id;

                if ($coupon->type === 'fixed') {
                    $discountAmount = $coupon->value;
                } elseif ($coupon->type === 'percentage') {
                    $discountAmount = $subtotal * ($coupon->value / 100);
                }

                $coupon->increment('usage_count');
            }

            // Tax calculation
            $taxAmount = $subtotal * ($taxRate / 100);

            // Total amount
            $total = $subtotal + $taxAmount + $shippingCost - $discountAmount;
            if ($total < 0) $total = 0;

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'coupon_id' => $couponId,
                'status' => 'pending',
                'shipping_address_id' => $validated['shipping_address_id'],
                'billing_address_id' => $validated['billing_address_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create order items and decrement stock
            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::findOrFail($item['product_id']);

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_model' => $product->model_code,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'total_price' => $product->price * $item['quantity'],
                    'options' => $item['options'] ?? null,
                ]);

                // Deduct stock
                $product->decrement('stock', $item['quantity']);
            }

            DB::commit();

            // Clear relevant caches
            $this->clearOrderCaches();
            $this->clearOrderCaches($order->id);

            $this->notify->notifyOrderCreated($order);

            return response()->json([
                'success' => true,
                'order' => $order->load('items'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Show specific order details (both user and admin)
    public function showOrder(Order $order)
    {
        $cacheKey = "order:{$order->id}";

        $orderData = Cache::remember($cacheKey, $this->cacheDurations['order_detail'], function () use ($order) {
            // Load all necessary relationships at once
            return $order->load(['items.product.images', 'payments', 'shippingAddress', 'billingAddress', 'user']);
        });

        return response()->json($orderData);
    }

    // Update order status
    public function updateOrderStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded,completed',
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $order->status;
        $newStatus = $validated['status'];

        $order->update($validated);

        // Update category orders when status changes to successful
        if ($this->isSuccessfulStatus($newStatus) && !$this->isSuccessfulStatus($oldStatus)) {
            $this->incrementCategoryOrders($order);
        }

        // Decrement category orders if status changes from successful to unsuccessful
        if (!$this->isSuccessfulStatus($newStatus) && $this->isSuccessfulStatus($oldStatus)) {
            $this->decrementCategoryOrders($order);
        }

        // Clear relevant caches
        $this->clearOrderCaches($order->id);

        return response()->json(['success' => true, 'order' => $order]);
    }

    /**
     * Check if order status is considered successful
     */
    private function isSuccessfulStatus($status)
    {
        return in_array($status, ['completed', 'delivered', 'shipped']);
    }

    /**
     * Increment category order counts for all categories in this order
     */
    private function incrementCategoryOrders(Order $order)
    {
        $categories = [];

        foreach ($order->items as $item) {
            if ($item->product && $item->product->category) {
                $categoryId = $item->product->category->id;
                if (!isset($categories[$categoryId])) {
                    $categories[$categoryId] = 0;
                }
                $categories[$categoryId] += $item->quantity;
            }
        }

        foreach ($categories as $categoryId => $quantity) {
            \App\Models\Category::where('id', $categoryId)->increment('order', $quantity);
        }

        // Clear category caches
        Cache::forget('category:stats');
        $keys = Cache::getRedis()->keys('*categories:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    /**
     * Decrement category order counts for all categories in this order
     */
    private function decrementCategoryOrders(Order $order)
    {
        $categories = [];

        foreach ($order->items as $item) {
            if ($item->product && $item->product->category) {
                $categoryId = $item->product->category->id;
                if (!isset($categories[$categoryId])) {
                    $categories[$categoryId] = 0;
                }
                $categories[$categoryId] += $item->quantity;
            }
        }

        foreach ($categories as $categoryId => $quantity) {
            \App\Models\Category::where('id', $categoryId)->decrement('order', $quantity);
        }

        // Clear category caches
        Cache::forget('category:stats');
        $keys = Cache::getRedis()->keys('*categories:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    // Update order shipping address id and billing address id
    public function updateOrderAddresses(Request $request, Order $order)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required|exists:addresses,id',
            'billing_address_id' => 'nullable|exists:addresses,id',
        ]);

        $order->update($validated);

        // Clear order cache
        $this->clearOrderCaches($order->id);

        return response()->json(['success' => true, 'order' => $order]);
    }

    // Delete order
    public function deleteOrder(Order $order)
    {
        $orderId = $order->id;
        $order->forceDelete();

        // Clear relevant caches
        $this->clearOrderCaches($orderId);

        return response()->json(['success' => true, 'message' => 'Order deleted']);
    }

    // Order Stats
    public function getOrderStats()
    {
        $cacheKey = 'order:stats';

        $stats = Cache::remember($cacheKey, $this->cacheDurations['order_stats'], function () {
            return [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'completed' => Order::where('status', 'completed')->count(),
            ];
        });

        return response()->json($stats);
    }

    // Download invoice
    public function downloadInvoice(Order $order, Request $request, $type = 'download')
    {
        try {
            $cacheKey = "order:invoice:{$order->id}";

            $pdf = Cache::remember($cacheKey, 3600, function () use ($order) { // Cache PDF for 1 hour
                return PDF::loadView('invoices.template', compact('order'));
            });

            $headers = [
                'Content-Type'              => 'application/pdf',
                'Content-Disposition'       => "attachment; filename=invoice-{$order->id}.pdf",
                'Access-Control-Allow-Origin' => 'http://127.0.0.1:3000', // frontend
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Expose-Headers' => 'Content-Disposition',
            ];

            return response($pdf->output(), 200, $headers);

        } catch (\Exception $e) {
            Log::error('Invoice generation error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to generate invoice'], 500);
        }
    }

    // Get user orders
    public function getUserOrders(Request $request)
    {
        $user = $request->user();
        $cacheKey = "orders:user:{$user->id}:" . md5(serialize($request->all()));

        $orders = Cache::remember($cacheKey, $this->cacheDurations['order_list'], function () use ($request, $user) {
            $query = Order::with(['items', 'payments'])
                ->where('user_id', $user->id);

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Search in order items
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('id', 'LIKE', "%{$search}%")
                      ->orWhere('status', 'LIKE', "%{$search}%")
                      ->orWhereHas('items', function($itemQuery) use ($search) {
                          $itemQuery->where('product_name', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 10);
            return $query->paginate($perPage);
        });

        return response()->json($orders);
    }

    // Get recent orders
    public function getRecentOrders($limit = 10)
    {
        $cacheKey = "orders:recent:{$limit}";

        $orders = Cache::remember($cacheKey, 300, function () use ($limit) { // 5 minutes cache
            return Order::with(['user', 'items'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        });

        return response()->json($orders);
    }

    // Cache debugging methods
    public function debugOrderCache($key)
    {
        $exists = Cache::has($key);
        $data = Cache::get($key);
        $ttl = Cache::getRedis()->ttl(Cache::getPrefix() . $key);

        return [
            'key' => $key,
            'exists' => $exists,
            'ttl_seconds' => $ttl,
            'data_sample' => $exists ? (is_array($data) ? array_slice($data, 0, 2) : $data) : null
        ];
    }

    public function getOrderCacheKeys()
    {
        $keys = Cache::getRedis()->keys('*order*');

        $cacheInfo = [];
        foreach ($keys as $key) {
            $cleanKey = str_replace('laravel_database_', '', $key);
            $ttl = Cache::getRedis()->ttl($key);
            $size = strlen(serialize(Cache::get($cleanKey)));
            $cacheInfo[] = [
                'key' => $cleanKey,
                'ttl' => $ttl,
                'size_bytes' => $size,
                'size_human' => $this->formatBytes($size)
            ];
        }

        return $cacheInfo;
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}