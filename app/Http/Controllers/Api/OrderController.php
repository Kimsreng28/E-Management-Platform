<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class OrderController
{
    // List orders for the authenticated user and admin
    public function getAllOrders()
    {
        $orders = Order::with('items', 'user')->paginate(15);

        return response()->json($orders);
    }


    // Create a new order with items
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required|exists:addresses,id',
            'billing_address_id' => 'nullable|exists:addresses,id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable|array',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            // Calculate subtotal, tax, shipping, discount, total (simple example)
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $product = \App\Models\Product::findOrFail($item['product_id']);
                $subtotal += $product->price * $item['quantity'];
            }

            $taxAmount = $subtotal * 0.1; // example 10% tax
            $shippingCost = 5.00; // fixed shipping for example
            $discountAmount = 0.00; // no discount logic here for simplicity
            $total = $subtotal + $taxAmount + $shippingCost - $discountAmount;

            $order = Order::create([
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'status' => 'pending',
                'shipping_address_id' => $validated['shipping_address_id'],
                'billing_address_id' => $validated['billing_address_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

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
            }

            DB::commit();

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
    public function showOrder(Request $request, Order $order)
    {
        $user = $request->user();

        if ($user->id !== $order->user_id && !$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($order->load('items', 'payments', 'shippingAddress', 'billingAddress'));
    }

    // Update order status
    public function updateOrderStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'notes' => 'nullable|string',
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
}