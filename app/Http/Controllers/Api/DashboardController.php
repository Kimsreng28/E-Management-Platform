<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use App\Models\User;

class DashboardController extends Controller
{
    // Cache durations in seconds
    private $cacheDurations = [
        'dashboard_stats' => 300, // 5 minutes
        'recent_orders' => 180, // 3 minutes
        'low_stock' => 600, // 10 minutes
    ];

    // Clear dashboard caches
    private function clearDashboardCaches()
    {
        Cache::forget('dashboard:stats');
        Cache::forget('dashboard:recent-orders');
        Cache::forget('dashboard:low-stock');
    }

    public function stats()
    {
        $cacheKey = 'dashboard:stats';

        $stats = Cache::remember($cacheKey, $this->cacheDurations['dashboard_stats'], function () {
            $now = Carbon::now('UTC');

            // REVENUE - Fixed calculation
            $currentMonthRevenue = (float) Order::whereMonth('created_at', $now->month)
                ->whereYear('created_at', $now->year)
                ->where('status', 'completed') // Only count completed orders
                ->sum('total');

            $lastMonth = $now->copy()->subMonth();
            $lastMonthRevenue = (float) Order::whereMonth('created_at', $lastMonth->month)
                ->whereYear('created_at', $lastMonth->year)
                ->where('status', 'completed') // Only count completed orders
                ->sum('total');

            // Calculate percentage change safely
            $revenueChange = 0;
            if ($lastMonthRevenue > 0) {
                $revenueChange = round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 2);
            } elseif ($currentMonthRevenue > 0) {
                $revenueChange = 100; // First month with revenue
            }

            // ORDERS - Fixed calculation
            $currentMonthOrders = Order::whereMonth('created_at', $now->month)
                ->whereYear('created_at', $now->year)
                ->where('status', 'completed') // Only count completed orders
                ->count();

            $lastMonthOrders = Order::whereMonth('created_at', $lastMonth->month)
                ->whereYear('created_at', $lastMonth->year)
                ->where('status', 'completed') // Only count completed orders
                ->count();

            // Calculate percentage change safely
            $ordersChange = 0;
            if ($lastMonthOrders > 0) {
                $ordersChange = round((($currentMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100, 2);
            } elseif ($currentMonthOrders > 0) {
                $ordersChange = 100; // First month with orders
            }

            // PRODUCTS - Fixed calculation
            $totalProducts = Product::where('is_active', true)->count();

            $productsLastMonth = Product::where('is_active', true)
                ->whereMonth('created_at', $lastMonth->month)
                ->whereYear('created_at', $lastMonth->year)
                ->count();

            // Calculate percentage change safely
            $productsChange = 0;
            if ($productsLastMonth > 0) {
                $productsChange = round((($totalProducts - $productsLastMonth) / $productsLastMonth) * 100, 2);
            } elseif ($totalProducts > 0) {
                $productsChange = 100; // First month with products
            }

            // ACTIVE CUSTOMERS (last hour vs previous hour) using activity logs
            $oneHourAgo = $now->copy()->subHour();
            $twoHoursAgo = $now->copy()->subHours(2);

            $currentHourActive = UserActivityLog::where('last_seen', '>=', $oneHourAgo)
                ->whereHas('user', fn($q) => $q->where('role_id', 2))
                ->distinct('user_id')
                ->count('user_id');

            $previousHourActive = UserActivityLog::whereBetween('last_seen', [$twoHoursAgo, $oneHourAgo])
                ->whereHas('user', fn($q) => $q->where('role_id', 2))
                ->distinct('user_id')
                ->count('user_id');

            $activeCustomerChange = $currentHourActive - $previousHourActive;

            // CUSTOMERS
            $totalCustomers = User::where('role_id', 2)->count();
            $activeCustomers = User::where('role_id', 2)->where('is_active', true)->count();
            $inactiveCustomers = $totalCustomers - $activeCustomers;

            return [
                'revenue' => [
                    'current_month' => $currentMonthRevenue,
                    'last_month' => $lastMonthRevenue,
                    'change' => $revenueChange,
                    'change_direction' => $revenueChange >= 0 ? 'increase' : 'decrease'
                ],
                'orders' => [
                    'current_month' => $currentMonthOrders,
                    'last_month' => $lastMonthOrders,
                    'change' => $ordersChange,
                    'change_direction' => $ordersChange >= 0 ? 'increase' : 'decrease'
                ],
                'products' => [
                    'total' => $totalProducts,
                    'last_month' => $productsLastMonth,
                    'change' => $productsChange,
                    'change_direction' => $productsChange >= 0 ? 'increase' : 'decrease'
                ],
                'active_customers' => [
                    'current_hour' => $currentHourActive,
                    'previous_hour' => $previousHourActive,
                    'change' => $activeCustomerChange,
                    'change_direction' => $activeCustomerChange >= 0 ? 'increase' : 'decrease'
                ],
                'customers' => [
                    'total' => $totalCustomers,
                    'active' => $activeCustomers,
                    'inactive' => $inactiveCustomers,
                ],
            ];
        });

        return response()->json($stats);
    }

    // Recent Order
    public function recentOrders()
    {
        $cacheKey = 'dashboard:recent-orders';

        $ordersData = Cache::remember($cacheKey, $this->cacheDurations['recent_orders'], function () {
            $oneWeekAgo = Carbon::now()->subWeek();

            // Get orders from last 7 days
            $orders = Order::with(['user', 'items.product'])
                ->where('created_at', '>=', $oneWeekAgo)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->order_number,
                        'customer' => $order->user->name ?? 'Guest',
                        'product' => $order->items->map(fn($item) => $item->product->name)->join(', '),
                        'amount' => '$' . number_format($order->total, 2),
                        'status' => $order->status,
                    ];
                });

            return [
                'count' => $orders->count(),
                'orders' => $orders,
            ];
        });

        return response()->json($ordersData);
    }

    // Low Stock Alert
    public function lowStock()
    {
        $cacheKey = 'dashboard:low-stock';

        $products = Cache::remember($cacheKey, $this->cacheDurations['low_stock'], function () {
            $products = Product::whereColumn('stock', '<=', 'low_stock_threshold')
                ->where('stock', '>', 0)
                ->select('id', 'name', 'stock', 'low_stock_threshold')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'stock' => $product->stock,
                        'low_stock_threshold' => $product->low_stock_threshold,
                        'status' => 'low',
                        'suggested_restock' => max($product->low_stock_threshold * 2 - $product->stock, 10)
                    ];
                });

            return $products;
        });

        return response()->json($products);
    }

    // restock
    public function restockProduct(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::find($request->product_id);
        $product->stock += $request->quantity;
        $product->save();

        // Clear dashboard caches after restocking
        $this->clearDashboardCaches();

        return response()->json([
            'message' => "Product {$product->name} restocked successfully.",
            'product' => $product,
        ]);
    }
}
