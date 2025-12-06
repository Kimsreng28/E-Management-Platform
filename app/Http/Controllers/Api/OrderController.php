<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    protected NotificationService $notify;

    public function __construct(NotificationService $notify)
    {
        $this->notify = $notify;
    }

    // Preview order
    public function previewOrder(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'coupon_code' => 'nullable|string',
        ]);

        $settings = $this->getVendorSettingsFromItems($validated['items']);

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

        $previewData = [
            'items' => $itemsPreview,
            'subtotal' => round($subtotal, 2),
            'shipping_cost' => round($shippingCost, 2),
            'tax_amount' => round($taxAmount, 2),
            'product_discount' => round($totalProductDiscount, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'total' => round($total, 2),
        ];

        return response()->json($previewData);
    }

    // List orders for the authenticated user and admin
    public function getAllOrders(Request $request)
    {
        $user = $request->user();

        // Start with base query
        $query = Order::with(['items.product.images', 'user', 'payments', 'delivery']);

        // If user is a vendor, only show orders containing their products
        if ($user->isVendor()) {
            $query->whereHas('items.product', function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            });
        }
        // If user is an delivery, only show orders assigned to them
        elseif ($user->isDelivery()) { // You'll need to add this method to User model
            $query->whereHas('delivery', function ($q) use ($user) {
                $q->where('delivery_agent_id', $user->id);
            });
        }
        // If user is a customer, only show their own orders
        elseif ($user->isCustomer()) {
            $query->where('user_id', $user->id);
        }
        // Admin can see all orders (no additional filter)

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
        $orders = $query->paginate($perPage);

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

        Log::info('User role check', [
            'user_id' => $user->id,
            'role_id' => $user->role_id,
            'email' => $user->email
        ]);

        DB::beginTransaction();

        try {
            // Get business settings for the current user
            $settings = $this->getVendorSettingsFromItems($validated['items']);

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

    private function getVendorSettingsFromItems(array $items)
    {
        $vendorIds = [];
        $createdByIds = [];

        foreach ($items as $item) {
            $product = Product::with('vendor', 'creator')->find($item['product_id']);

            if ($product) {
                // First priority: vendor_id
                if ($product->vendor_id) {
                    $vendorIds[] = $product->vendor_id;
                    Log::info('Found vendor for product', [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'vendor_id' => $product->vendor_id,
                        'vendor_name' => $product->vendor->name ?? 'Unknown'
                    ]);
                }
                // Second priority: created_by (admin-created products)
                elseif ($product->created_by) {
                    $createdByIds[] = $product->created_by;
                    Log::info('Product has no vendor, using created_by', [
                        'product_id' => $product->id,
                        'created_by' => $product->created_by,
                        'creator_name' => $product->creator->name ?? 'Unknown'
                    ]);
                } else {
                    Log::warning('Product has no vendor_id or created_by', [
                        'product_id' => $item['product_id'],
                    ]);
                }
            }
        }

        // First try vendor settings
        if (!empty($vendorIds)) {
            $vendorId = $vendorIds[0];
            $vendorSettings = BusinessSetting::where('user_id', $vendorId)->first();

            if ($vendorSettings) {
                Log::info('Using vendor settings for order', [
                    'vendor_id' => $vendorId,
                    'vendor_settings_id' => $vendorSettings->id,
                    'business_name' => $vendorSettings->business_name
                ]);
                return $vendorSettings;
            }
        }

        // Then try created_by user settings (for admin-created products)
        if (!empty($createdByIds)) {
            $creatorId = $createdByIds[0];
            $creatorSettings = BusinessSetting::where('user_id', $creatorId)->first();

            if ($creatorSettings) {
                Log::info('Using creator settings for order', [
                    'creator_id' => $creatorId,
                    'creator_settings_id' => $creatorSettings->id,
                    'business_name' => $creatorSettings->business_name
                ]);
                return $creatorSettings;
            }
        }

        // Final fallback to admin settings
        $adminSettings = BusinessSetting::where('user_id', 1)->first();

        Log::info('Using admin settings as fallback for order', [
            'vendor_ids_found' => $vendorIds,
            'created_by_ids_found' => $createdByIds,
            'admin_settings_found' => !is_null($adminSettings)
        ]);

        return $adminSettings;
    }

    // Show specific order details (both user and admin)
    public function showOrder(Order $order)
    {
        // Load all necessary relationships at once
        $orderData = $order->load(['items.product.images', 'payments', 'shippingAddress', 'billingAddress', 'user', 'delivery']);

        return response()->json($orderData);
    }

    // Update order status
    public function updateOrderStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded,completed',
            'notes' => 'nullable|string',
        ]);

        $user = Auth::user();
        $newStatus = $validated['status'];

        // If user is customer, restrict what they can do
        if ($user->isCustomer()) {
            // Customers can only complete their own orders
            if ($order->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only update your own orders'
                ], 403);
            }

            // Customers can only set status to completed (for their own orders)
            if ($newStatus !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Customers can only complete orders'
                ], 403);
            }

            // Verify payment is completed OR it's a COD payment
            $hasSuccessfulPayment = $order->payments()
                ->where('status', 'completed')
                ->exists();

            $hasCODPayment = $order->payments()
                ->where('payment_method', 'cod')
                ->exists();

            if (!$hasSuccessfulPayment && !$hasCODPayment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot complete order without successful payment or COD payment'
                ], 400);
            }
        }

        $oldStatus = $order->status;
        $order->update($validated);

        // Update category orders when status changes
        if ($this->isSuccessfulStatus($newStatus) && !$this->isSuccessfulStatus($oldStatus)) {
            $this->incrementCategoryOrders($order);
        }

        if (!$this->isSuccessfulStatus($newStatus) && $this->isSuccessfulStatus($oldStatus)) {
            $this->decrementCategoryOrders($order);
        }

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
    }

    // Update order shipping address id and billing address id
    public function updateOrderAddresses(Request $request, Order $order)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required|exists:addresses,id',
            'billing_address_id' => 'nullable|exists:addresses,id',
        ]);

        $order->update($validated);

        return response()->json(['success' => true, 'order' => $order]);
    }

    // Delete order
    public function deleteOrder(Order $order)
    {
        $order->forceDelete();

        return response()->json(['success' => true, 'message' => 'Order deleted']);
    }

    // Order Stats
    public function getOrderStats(Request $request)
    {
        $user = $request->user();
        $stats = [];

        if ($user->isAdmin()) {
            // Admin sees all stats
            $stats = [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'completed' => Order::where('status', 'completed')->count(),
                'cancelled' => Order::where('status', 'cancelled')->count(),
                'refunded' => Order::where('status', 'refunded')->count(),
                // Delivery specific stats for admin
                'awaiting_delivery' => Order::where('status', 'processing')->orWhere('status', 'shipped')->count(),
                'in_delivery' => Order::whereHas('delivery', function($q) {
                    $q->where('status', 'in_transit')->orWhere('status', 'picked_up');
                })->count(),
                'delivered' => Order::whereHas('delivery', function($q) {
                    $q->where('status', 'delivered');
                })->count(),
            ];
        } elseif ($user->isVendor()) {
            // Vendor only sees stats for their products
            $vendorOrders = Order::whereHas('items.product', function($q) use ($user) {
                $q->where('vendor_id', $user->id);
            });

            $stats = [
                'total' => $vendorOrders->count(),
                'pending' => $vendorOrders->clone()->where('status', 'pending')->count(),
                'processing' => $vendorOrders->clone()->where('status', 'processing')->count(),
                'shipped' => $vendorOrders->clone()->where('status', 'shipped')->count(),
                'completed' => $vendorOrders->clone()->where('status', 'completed')->count(),
                'cancelled' => $vendorOrders->clone()->where('status', 'cancelled')->count(),
                'refunded' => $vendorOrders->clone()->where('status', 'refunded')->count(),
            ];
        } elseif ($user->isDelivery()) {
            // Delivery agent only sees stats for orders assigned to them
            $deliveryOrders = Order::whereHas('delivery', function($q) use ($user) {
                $q->where('delivery_agent_id', $user->id);
            });

            // Get delivery-specific stats
            $deliveryStats = [
                'total' => $deliveryOrders->count(),
                'assigned' => $deliveryOrders->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'assigned');
                    })->count(),
                'picked_up' => $deliveryOrders->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'picked_up');
                    })->count(),
                'in_transit' => $deliveryOrders->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'in_transit');
                    })->count(),
                'delivered' => $deliveryOrders->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'delivered');
                    })->count(),
                'failed' => $deliveryOrders->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'failed');
                    })->count(),
                'pending' => $deliveryOrders->clone()->where('status', 'pending')->count(),
                'processing' => $deliveryOrders->clone()->where('status', 'processing')->count(),
                'shipped' => $deliveryOrders->clone()->where('status', 'shipped')->count(),
                'completed' => $deliveryOrders->clone()->where('status', 'completed')->count(),
            ];

            // Also get today's delivery stats for dashboard
            $today = now()->startOfDay();
            $todayDeliveries = Order::whereHas('delivery', function($q) use ($user) {
                $q->where('delivery_agent_id', $user->id)
                ->whereDate('created_at', '>=', now()->startOfDay());
            });

            $stats = array_merge($deliveryStats, [
                'today_total' => $todayDeliveries->count(),
                'today_delivered' => $todayDeliveries->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'delivered');
                    })->count(),
                'today_pending' => $todayDeliveries->clone()
                    ->whereHas('delivery', function($q) {
                        $q->where('status', 'assigned')->orWhere('status', 'picked_up');
                    })->count(),
            ]);
        } else {
            // Customer sees only their own order stats
            $customerOrders = Order::where('user_id', $user->id);

            $stats = [
                'total' => $customerOrders->count(),
                'pending' => $customerOrders->clone()->where('status', 'pending')->count(),
                'processing' => $customerOrders->clone()->where('status', 'processing')->count(),
                'shipped' => $customerOrders->clone()->where('status', 'shipped')->count(),
                'completed' => $customerOrders->clone()->where('status', 'completed')->count(),
                'cancelled' => $customerOrders->clone()->where('status', 'cancelled')->count(),
                'refunded' => $customerOrders->clone()->where('status', 'refunded')->count(),
            ];
        }

        return response()->json($stats);
    }

    // Download invoice
    public function downloadInvoice(Order $order, Request $request, $type = 'download')
    {
        try {
            $pdf = PDF::loadView('invoices.template', compact('order'));

            $frontendUrl = config('app.frontend_url',  env('NEXT_PUBLIC_FRONTEND_URL', 'http://localhost:3000'), env('FRONTEND_URL'));

            $headers = [
                'Content-Type'              => 'application/pdf',
                'Content-Disposition'       => "attachment; filename=invoice-{$order->id}.pdf",
                'Access-Control-Allow-Origin' => $frontendUrl, // frontend
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
        $orders = $query->paginate($perPage);

        return response()->json($orders);
    }

    // Get recent orders
    public function getRecentOrders($limit = 10)
    {
        $orders = Order::with(['user', 'items'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($orders);
    }

    public function completeOrder(Request $request, Order $order)
    {
        // Verify the order belongs to the current user
        if ($order->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You can only complete your own orders'
            ], 403);
        }

        // Check for any existing payment
        $hasPayment = $order->payments()->exists();

        // For COD payments, allow completion with pending payment status
        $hasCODPayment = $order->payments()
            ->where('payment_method', 'cod')
            ->exists();

        // For online payments, require successful payment
        $hasSuccessfulPayment = $order->payments()
            ->where('status', 'completed')
            ->exists();

        // Allow completion if:
        // 1. There's a successful payment (online payments) OR
        // 2. There's a COD payment (any status is OK for COD)
        if (!$hasSuccessfulPayment && !$hasCODPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot complete order without successful payment or COD payment'
            ], 400);
        }

        // Update order status based on payment type
        $newStatus = $hasCODPayment ? 'processing' : 'completed';

        $order->update(['status' => $newStatus]);

        // Notify about order completion
        $this->notify->notifyOrderStatusChanged($order);

        return response()->json([
            'success' => true,
            'message' => $hasCODPayment
                ? 'Order placed successfully. Payment will be collected on delivery.'
                : 'Order completed successfully',
            'order' => $order
        ]);
    }
}
