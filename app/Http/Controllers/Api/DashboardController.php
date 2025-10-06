<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use App\Models\User;

class DashboardController extends Controller
{
    public function stats()
    {
        $now = Carbon::now('UTC');

        // REVENUE
        $currentMonthRevenue = (float) Order::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->sum('total');

        $lastMonth = $now->copy()->subMonth();
        $lastMonthRevenue = (float) Order::whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->sum('total');

        $revenueChange = $lastMonthRevenue > 0
            ? round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 2)
            : 0;

        // ORDERS
        $currentMonthOrders = Order::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $lastMonthOrders = Order::whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->count();

        $ordersChange = $lastMonthOrders > 0
            ? round((($currentMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100, 2)
            : 0;

        // PRODUCTS
        $totalProducts = Product::count();
        $productsLastMonth = Product::whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->count();

        $productsChange = $productsLastMonth > 0
            ? round((($totalProducts - $productsLastMonth) / $productsLastMonth) * 100, 2)
            : 0;

        // ACTIVE CUSTOMERS (last hour vs previous hour) using activity logs
        $oneHourAgo = $now->copy()->subHour();
        $twoHoursAgo = $now->copy()->subHours(2);

        $currentHourActive = UserActivityLog::where('last_seen', '>=', $oneHourAgo)
            ->whereHas('user', fn($q) => $q->where('role_id', 2))
            ->count();

        $previousHourActive = UserActivityLog::whereBetween('last_seen', [$twoHoursAgo, $oneHourAgo])
            ->whereHas('user', fn($q) => $q->where('role_id', 2))
            ->count();

        $activeCustomerChange = $currentHourActive - $previousHourActive;

        // CUSTOMERS
        $customers = User::where('role_id', 2)->get();

        $totalCustomers = $customers->count();

        $activeCustomers = $customers->where('is_active', true)->count();

        $inactiveCustomers = $totalCustomers - $activeCustomers;

        return response()->json([
            'revenue' => [
                'current_month' => $currentMonthRevenue,
                'last_month' => $lastMonthRevenue,
                'change' => $revenueChange,
            ],
            'orders' => [
                'current_month' => $currentMonthOrders,
                'last_month' => $lastMonthOrders,
                'change' => ($ordersChange > 0 ? '+' : '') . $ordersChange,
            ],
            'products' => [
                'total' => $totalProducts,
                'last_month' => $productsLastMonth,
                'change' => $productsChange,
            ],
            'active_customers' => [
                'current_hour' => $currentHourActive,
                'previous_hour' => $previousHourActive,
                'change' => $activeCustomerChange, // e.g. "+201 since last hour"
            ],
            'customers' => [
                'total' => $totalCustomers,
                'active' => $activeCustomers,
                'inactive' => $inactiveCustomers,
            ],
        ]);
    }

    // Recent Order
    public function recentOrders()
    {
        $oneWeekAgo = Carbon::now()->subWeek();

        // Get orders from last 7 days
        $orders = Order::with(['user', 'items.product'])
            ->where('created_at', '>=', $oneWeekAgo)
            ->orderBy('created_at', 'desc')
            ->take(5) // latest 5 orders
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

        return response()->json([
            'count' => $orders->count(),
            'orders' => $orders,
        ]);
    }

    // Low Stock Alert
    public function lowStock()
    {
        $products = Product::whereColumn('stock', '<=', 'low_stock_threshold') // compare columns
            ->where('stock', '>', 0)
            ->select('id', 'name', 'stock')
            ->get();

        // Add a "status" field
        $products->map(function ($product) {
            $product->status = 'low';
            return $product;
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

        return response()->json([
            'message' => "Product {$product->name} restocked successfully.",
            'product' => $product,
        ]);
    }

}
