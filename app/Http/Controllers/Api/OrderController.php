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
use Illuminate\Support\Facades\Log;

class OrderController
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

        $settings = BusinessSetting::where('user_id', Auth::id())->first();

        $taxRate = $settings->tax_rate ?? 0.1; // fallback to 10%

        $shippingCost = $settings->default_shipping_cost ?? 5.00;

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

        return response()->json([
            'items' => $itemsPreview,
            'subtotal' => round($subtotal, 2),
            'shipping_cost' => round($shippingCost, 2),
            'tax_amount' => round($taxAmount, 2),
            'product_discount' => round($totalProductDiscount, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'total' => round($total, 2),
        ]);
    }


    // List orders for the authenticated user and admin
    public function getAllOrders(Request $request)
    {
        $query = Order::with(['items', 'user', 'payments']);

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

        // Filtering by status (pending, processing, shipped, completed, cancelled )
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtering by payment ( paid, pending, failed, refunded)
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

        DB::beginTransaction();

        try {
            // Get business settings for the current user
            $settings = BusinessSetting::where('user_id', Auth::id())->first();

            $taxRate = $settings->tax_rate ?? 0.1; // fallback to 10%

            $shippingCost = $settings->default_shipping_cost ?? 5.00; // optional field

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

    // Show specific order details ( both user and admin )
    public function showOrder( Order $order)
    {
        // Load all necessary relationships at once
        $order->load(['items', 'payments', 'shippingAddress', 'billingAddress', 'user']);

        return response()->json($order);
    }


    // Update order status
    public function updateOrderStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded,completed',
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);

        return response()->json(['success' => true, 'order' => $order]);
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
    public function getOrderStats()
    {
            $stats = [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'processing' => Order::where('status', 'processing')->count(),
                'shipped' => Order::where('status', 'shipped')->count(),
                'completed' => Order::where('status', 'completed')->count(),
            ];
        return response()->json($stats);
    }

    // Download invoice
    public function downloadInvoice(Order $order, Request $request, $type = 'download')
    {
        try {
            $pdf = PDF::loadView('invoices.template', compact('order'));

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
}
