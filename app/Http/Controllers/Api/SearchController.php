<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Product;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $type = $request->get('type', 'all');

            if (empty($query)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'products' => [],
                        'orders' => [],
                        'customers' => []
                    ]
                ]);
            }

            $results = [
                'products' => [],
                'orders' => [],
                'customers' => []
            ];

            // Search products
            if ($type === 'all' || $type === 'products') {
                $results['products'] = Product::where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                    ->orWhere('model_code', 'like', "%{$query}%")
                    ->orWhere('short_description', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
                })
                ->take(5)
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->model_code, // or use $product->sku if exists
                        'price' => $product->price,
                        'stock' => $product->stock,
                        'image' => optional($product->images->first())->path,
                        'type' => 'product',
                    ];
                });
            }

            // Search orders
            if ($type === 'all' || $type === 'orders') {
                $results['orders'] = Order::where(function ($q) use ($query) {
                    $q->where('order_number', 'like', "%{$query}%")
                    ->orWhere('status', 'like', "%{$query}%")
                    ->orWhereHas('user', function ($q2) use ($query) {
                        $q2->where('name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");
                    });
                })
                ->with(['user:id,name,email'])
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total' => $order->total,
                        'customer_name' => $order->user->name,
                        'created_at' => $order->created_at,
                        'type' => 'order'
                    ];
                });
            }

            // Search customers
            if ($type === 'all' || $type === 'customers') {
                $results['customers'] = User::where('role_id', 2) // 2 = customer
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
                })
                ->take(5)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'orders_count' => $user->orders()->count(),
                        'type' => 'customer'
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Search failed'
            ], 500);
        }
    }
}