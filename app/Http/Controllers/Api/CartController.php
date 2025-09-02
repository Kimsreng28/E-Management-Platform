<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Cart;
use App\Models\CartItem;

class CartController extends Controller
{
    // Get current user's cart or create one
    public function getCart(Request $request)
    {
        $user = $request->user();

        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['session_id' => null]
        );

        $cart->load('items.product.images');

        return response()->json($cart);
    }

    // Add item to cart
    public function addItem(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'options' => 'nullable|array',
        ]);

        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['session_id' => null]
        );

        // Check if item already exists with same options
        $existingItem = $cart->items()
            ->where('product_id', $validated['product_id'])
            ->whereRaw("options::text = ?", [json_encode($validated['options'] ?? [])])
            ->first();

        if ($existingItem) {
            $existingItem->quantity += $validated['quantity'];
            $existingItem->save();
        } else {
            $cart->items()->create($validated);
        }

        $cart->load('items.product');

        return response()->json(['success' => true, 'cart' => $cart]);
    }

    // Update cart item quantity or options
    public function updateItem(Request $request, CartItem $item)
    {
        $user = $request->user();

        $cart = $item->cart;

        if ($cart->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|integer|min:1',
            'options' => 'nullable|array',
        ]);

        $item->update($validated);

        $cart->load('items.product');

        return response()->json(['success' => true, 'cart' => $cart]);
    }

    // Remove item from cart
    public function removeItem(Request $request, CartItem $item)
    {
        $user = $request->user();

        $cart = $item->cart;

        if ($cart->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $item->delete();

        $cart->load('items.product');

        return response()->json(['success' => true, 'cart' => $cart]);
    }

    // Clear all items in cart
    public function clearCart(Request $request)
    {
        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)->first();

        if ($cart) {
            $cart->items()->delete();
        }

        return response()->json(['success' => true, 'message' => 'Cart cleared']);
    }
}
