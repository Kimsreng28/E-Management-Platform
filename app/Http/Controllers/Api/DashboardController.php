<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserActivityLog;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // Helper method to apply vendor filter to orders
    private function applyVendorOrderFilter($query, $user)
    {
        if ($user->isVendor()) {
            $query->whereHas('items.product', function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            });
        }
        return $query;
    }

    // Helper method to apply vendor filter to products
    private function applyVendorProductFilter($query, $user)
    {
        if ($user->isVendor()) {
            $query->where('vendor_id', $user->id);
        }
        return $query;
    }

    // Helper method to apply delivery filter to orders
    private function applyDeliveryOrderFilter($query, $user)
    {
        if ($user->isDelivery()) {
            $query->whereHas('deliveries', function ($q) use ($user) {
                $q->where('delivery_agent_id', $user->id);
            });
        }
        return $query;
    }

    // Helper method to apply delivery filter to deliveries
    private function applyDeliveryDeliveryFilter($query, $user)
    {
        if ($user->isDelivery()) {
            $query->where('delivery_agent_id', $user->id);
        }
        return $query;
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now('UTC');

        // REVENUE
        $currentMonthRevenueQuery = Order::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where('status', 'completed'); // Only count completed orders

        $currentMonthRevenueQuery = $this->applyVendorOrderFilter($currentMonthRevenueQuery, $user);
        $currentMonthRevenue = (float) $currentMonthRevenueQuery->sum('total');

        $lastMonth = $now->copy()->subMonth();
        $lastMonthRevenueQuery = Order::whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->where('status', 'completed'); // Only count completed orders

        $lastMonthRevenueQuery = $this->applyVendorOrderFilter($lastMonthRevenueQuery, $user);
        $lastMonthRevenue = (float) $lastMonthRevenueQuery->sum('total');

        // Calculate percentage change safely
        $revenueChange = 0;
        if ($lastMonthRevenue > 0) {
            $revenueChange = round((($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 2);
        } elseif ($currentMonthRevenue > 0) {
            $revenueChange = 100; // First month with revenue
        }

        // ORDERS
        $currentMonthOrdersQuery = Order::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->where('status', 'completed'); // Only count completed orders

        $currentMonthOrdersQuery = $this->applyVendorOrderFilter($currentMonthOrdersQuery, $user);
        $currentMonthOrders = $currentMonthOrdersQuery->count();

        $lastMonthOrdersQuery = Order::whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->where('status', 'completed'); // Only count completed orders

        $lastMonthOrdersQuery = $this->applyVendorOrderFilter($lastMonthOrdersQuery, $user);
        $lastMonthOrders = $lastMonthOrdersQuery->count();

        // Calculate percentage change safely
        $ordersChange = 0;
        if ($lastMonthOrders > 0) {
            $ordersChange = round((($currentMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100, 2);
        } elseif ($currentMonthOrders > 0) {
            $ordersChange = 100; // First month with orders
        }

        // PRODUCTS
        $productsQuery = Product::where('is_active', true);
        $productsQuery = $this->applyVendorProductFilter($productsQuery, $user);
        $totalProducts = $productsQuery->count();

        $productsLastMonthQuery = Product::where('is_active', true)
            ->whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year);

        $productsLastMonthQuery = $this->applyVendorProductFilter($productsLastMonthQuery, $user);
        $productsLastMonth = $productsLastMonthQuery->count();

        // Calculate percentage change safely
        $productsChange = 0;
        if ($productsLastMonth > 0) {
            $productsChange = round((($totalProducts - $productsLastMonth) / $productsLastMonth) * 100, 2);
        } elseif ($totalProducts > 0) {
            $productsChange = 100; // First month with products
        }

        // ACTIVE CUSTOMERS (last hour vs previous hour) using activity logs
        // For vendors, only count customers who bought their products
        $oneHourAgo = $now->copy()->subHour();
        $twoHoursAgo = $now->copy()->subHours(2);

        $currentHourActiveQuery = UserActivityLog::where('last_seen', '>=', $oneHourAgo)
            ->whereHas('user', function($q) use ($user) {
                $q->where('role_id', 2); // Customers only
                if ($user->isVendor()) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                }
            });

        $currentHourActive = $currentHourActiveQuery->distinct('user_id')->count('user_id');

        $previousHourActiveQuery = UserActivityLog::whereBetween('last_seen', [$twoHoursAgo, $oneHourAgo])
            ->whereHas('user', function($q) use ($user) {
                $q->where('role_id', 2); // Customers only
                if ($user->isVendor()) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                }
            });

        $previousHourActive = $previousHourActiveQuery->distinct('user_id')->count('user_id');

        $activeCustomerChange = $currentHourActive - $previousHourActive;

        // CUSTOMERS - For vendors, only count customers who bought their products
        $customersQuery = User::where('role_id', 2);

        if ($user->isVendor()) {
            $customersQuery->whereHas('orders.items.product', function($q) use ($user) {
                $q->where('vendor_id', $user->id);
            });
        }

        // Get total customers
        $totalCustomers = $customersQuery->count();

        // Define activity thresholds (customizable)
        $onlineThreshold = 1; // minutes for "online"
        $recentThreshold = 5; // minutes for "recently active"
        $inactiveThreshold = 30; // days for "inactive"

        // Get active customers (online in last 1 minute)
        $activeCustomers = $customersQuery->where('last_seen_at', '>=', now()->subMinutes($onlineThreshold))->count();

        // Get recently active customers (active in last 5 minutes)
        $recentlyActiveCustomers = $customersQuery->where('last_seen_at', '>=', now()->subMinutes($recentThreshold))->count();

        // Get inactive customers (no activity in last 30 days)
        $inactiveCustomers = $customersQuery->where(function($q) use ($inactiveThreshold) {
            $q->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<=', now()->subDays($inactiveThreshold));
        })->count();

        $stats = [
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
                'recently_active' => $recentlyActiveCustomers,
                'inactive' => $inactiveCustomers,
                'online' => $activeCustomers, // alias for active
                'offline' => $totalCustomers - $activeCustomers,
            ],
        ];

        return response()->json($stats);
    }

    // Chart Data for Dashboard
    public function chartData(Request $request)
    {
        $user = $request->user();
        $period = $request->get('period', 'year'); // year, month, week

        try {
            // Revenue Data (Last 12 months)
            $revenueData = $this->getRevenueData($user, $period);

            // Category Data
            $categoryData = $this->getCategoryData($user);

            // Sales Trend Data (Last 7 days)
            $salesTrendData = $this->getSalesTrendData($user);

            // Order Status Data
            $orderStatusData = $this->getOrderStatusData($user);

            return response()->json([
                'revenueData' => $revenueData,
                'categoryData' => $categoryData,
                'salesTrendData' => $salesTrendData,
                'orderStatusData' => $orderStatusData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch chart data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getRevenueData($user, $period)
    {
        $months = [];
        $now = Carbon::now();

        if ($period === 'year') {
            // Last 12 months - FIXED: proper month iteration
            for ($i = 11; $i >= 0; $i--) {
                $month = $now->copy()->subMonths($i);
                $months[] = [
                    'start' => $month->copy()->startOfMonth(),
                    'end' => $month->copy()->endOfMonth(),
                    'label' => $month->format('M Y')
                ];
            }
        } elseif ($period === 'month') {
            // Last 30 days
            for ($i = 29; $i >= 0; $i--) {
                $day = $now->copy()->subDays($i);
                $months[] = [
                    'start' => $day->copy()->startOfDay(),
                    'end' => $day->copy()->endOfDay(),
                    'label' => $day->format('M d')
                ];
            }
        } else {
            // Last 7 days (week)
            for ($i = 6; $i >= 0; $i--) {
                $day = $now->copy()->subDays($i);
                $months[] = [
                    'start' => $day->copy()->startOfDay(),
                    'end' => $day->copy()->endOfDay(),
                    'label' => $day->format('D')
                ];
            }
        }

        $revenueData = [];

        foreach ($months as $month) {
            // INCLUDE PROCESSING ORDERS TOO (or remove status filter)
            $revenueQuery = Order::whereBetween('created_at', [$month['start'], $month['end']])
                ->whereIn('status', ['completed', 'processing']); // Include processing orders

            $revenueQuery = $this->applyVendorOrderFilter($revenueQuery, $user);

            $revenue = (float) $revenueQuery->sum('total');

            $ordersQuery = Order::whereBetween('created_at', [$month['start'], $month['end']])
                ->whereIn('status', ['completed', 'processing']); // Include processing orders

            $ordersQuery = $this->applyVendorOrderFilter($ordersQuery, $user);

            $orders = $ordersQuery->count();

            $revenueData[] = [
                'month' => $month['label'],
                'revenue' => $revenue,
                'orders' => $orders
            ];
        }

        return $revenueData;
    }

    private function getCategoryData($user)
    {
        $categoryQuery = Product::select(
                'categories.name as category_name',
                DB::raw('COUNT(products.id) as product_count'),
                DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_sold'),
                DB::raw('COALESCE(SUM(order_items.quantity * products.price), 0) as total_revenue')
            )
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('orders', function($join) {
                $join->on('order_items.order_id', '=', 'orders.id')
                    ->where('orders.status', 'completed');
            })
            ->groupBy('categories.id', 'categories.name');

        $categoryQuery = $this->applyVendorProductFilter($categoryQuery, $user);

        $categories = $categoryQuery->get();

        $totalRevenue = $categories->sum('total_revenue');
        $totalProducts = $categories->sum('product_count');

        $colors = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042', '#8884D8', '#82CA9D'];

        $categoryData = $categories->map(function ($category, $index) use ($totalRevenue, $totalProducts, $colors) {
            $percentage = $totalRevenue > 0 ? round(($category->total_revenue / $totalRevenue) * 100, 1) : 0;

            return [
                'name' => $category->category_name ?: 'Uncategorized',
                'value' => $percentage,
                'color' => $colors[$index % count($colors)],
                'productCount' => $category->product_count,
                'totalSold' => $category->total_sold,
                'revenue' => $category->total_revenue
            ];
        });

        return $categoryData->sortByDesc('value')->values()->take(6)->toArray();
    }

    private function getSalesTrendData($user)
    {
        $days = [];
        $now = Carbon::now();

        // Last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $days[] = [
                'start' => $day->copy()->startOfDay(),
                'end' => $day->copy()->endOfDay(),
                'label' => $day->format('D')
            ];
        }

        $salesTrendData = [];

        foreach ($days as $day) {
            // INCLUDE PROCESSING ORDERS TOO
            $salesQuery = Order::whereBetween('created_at', [$day['start'], $day['end']])
                ->whereIn('status', ['completed', 'processing']); // Include processing orders

            $salesQuery = $this->applyVendorOrderFilter($salesQuery, $user);

            $sales = (float) $salesQuery->sum('total');

            $ordersQuery = Order::whereBetween('created_at', [$day['start'], $day['end']])
                ->whereIn('status', ['completed', 'processing']); // Include processing orders

            $ordersQuery = $this->applyVendorOrderFilter($ordersQuery, $user);

            $orders = $ordersQuery->count();

            $salesTrendData[] = [
                'day' => $day['label'],
                'sales' => $sales,
                'orders' => $orders
            ];
        }

        return $salesTrendData;
    }

    private function getOrderStatusData($user)
    {
        $statuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];

        $orderStatusData = [];

        foreach ($statuses as $status) {
            $query = Order::where('status', $status);
            $query = $this->applyVendorOrderFilter($query, $user);

            $count = $query->count();

            $orderStatusData[] = [
                'status' => ucfirst($status),
                'count' => $count
            ];
        }

        return $orderStatusData;
    }

    // Recent Orders
    public function recentOrders(Request $request)
    {
        $user = $request->user();
        $oneWeekAgo = Carbon::now()->subWeek();

        // Get orders from last 7 days with vendor filter
        $ordersQuery = Order::with(['user', 'items.product'])
            ->where('created_at', '>=', $oneWeekAgo)
            ->orderBy('created_at', 'desc')
            ->take(5);

        $ordersQuery = $this->applyVendorOrderFilter($ordersQuery, $user);

        $orders = $ordersQuery->get()
            ->map(function ($order) {
                return [
                    'id' => $order->order_number,
                    'customer' => $order->user->name ?? 'Guest',
                    'product' => $order->items->map(fn($item) => $item->product->name)->join(', '),
                    'amount' => '$' . number_format($order->total, 2),
                    'status' => $order->status,
                ];
            });

        $ordersData = [
            'count' => $orders->count(),
            'orders' => $orders,
        ];

        return response()->json($ordersData);
    }

    // Low Stock Alert
    public function lowStock(Request $request)
    {
        $user = $request->user();

        $productsQuery = Product::whereColumn('stock', '<=', 'low_stock_threshold')
            ->where('stock', '>', 0)
            ->select('id', 'name', 'stock', 'low_stock_threshold');

        $productsQuery = $this->applyVendorProductFilter($productsQuery, $user);

        $products = $productsQuery->get()
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

        return response()->json($products);
    }

    // Restock product
    public function restockProduct(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $productQuery = Product::where('id', $request->product_id);

        // For vendors, only allow restocking their own products
        if ($user->isVendor()) {
            $productQuery->where('vendor_id', $user->id);
        }

        $product = $productQuery->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found or you do not have permission to restock this product.',
            ], 403);
        }

        $product->stock += $request->quantity;
        $product->save();

        return response()->json([
            'message' => "Product {$product->name} restocked successfully.",
            'product' => $product,
        ]);
    }

    // Top selling products for vendor
    public function topSellingProducts(Request $request)
    {
        $user = $request->user();
        $period = $request->get('period', 'month'); // day, week, month, year

        $dateRange = $this->getDateRange($period);
        $start = $dateRange['start'];
        $end = $dateRange['end'];

        $productsQuery = Product::withCount(['orderItems as units_sold' => function($q) use ($start, $end, $user) {
                $q->whereHas('order', function($oq) use ($start, $end, $user) {
                    $oq->whereBetween('created_at', [$start, $end])
                       ->where('status', 'completed');
                    if ($user->isVendor()) {
                        $oq->whereHas('items.product', function($pq) use ($user) {
                            $pq->where('vendor_id', $user->id);
                        });
                    }
                });
            }])
            ->withSum(['orderItems as revenue' => function($q) use ($start, $end, $user) {
                $q->whereHas('order', function($oq) use ($start, $end, $user) {
                    $oq->whereBetween('created_at', [$start, $end])
                       ->where('status', 'completed');
                    if ($user->isVendor()) {
                        $oq->whereHas('items.product', function($pq) use ($user) {
                            $pq->where('vendor_id', $user->id);
                        });
                    }
                });
            }], DB::raw('order_items.quantity * products.price'));

        $productsQuery = $this->applyVendorProductFilter($productsQuery, $user);

        $topProducts = $productsQuery->orderBy('units_sold', 'desc')
            ->take(10)
            ->get()
            ->map(function($product) {
                return [
                    'name' => $product->name,
                    'units_sold' => $product->units_sold,
                    'revenue' => $product->revenue ?? 0,
                    'stock' => $product->stock,
                ];
            });

        return response()->json($topProducts);
    }

    // Helper method for date ranges
    private function getDateRange($period)
    {
        $today = Carbon::now();

        switch ($period) {
            case 'week':
                $start = $today->copy()->startOfWeek();
                $end = $today->copy()->endOfWeek();
                break;
            case 'month':
                $start = $today->copy()->startOfMonth();
                $end = $today->copy()->endOfMonth();
                break;
            case 'year':
                $start = $today->copy()->startOfYear();
                $end = $today->copy()->endOfYear();
                break;
            case 'day':
            default:
                $start = $today->copy()->startOfDay();
                $end = $today->copy()->endOfDay();
                break;
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    public function customerActivityStats(Request $request)
    {
        $user = $request->user();
        $timeframe = $request->get('timeframe', 'day'); // day, week, month

        // Define time ranges
        $now = Carbon::now();
        $ranges = [];

        switch ($timeframe) {
            case 'hour':
                for ($i = 23; $i >= 0; $i--) {
                    $hour = $now->copy()->subHours($i);
                    $ranges[] = [
                        'start' => $hour->copy()->startOfHour(),
                        'end' => $hour->copy()->endOfHour(),
                        'label' => $hour->format('H:00')
                    ];
                }
                break;
            case 'week':
                for ($i = 6; $i >= 0; $i--) {
                    $day = $now->copy()->subDays($i);
                    $ranges[] = [
                        'start' => $day->copy()->startOfDay(),
                        'end' => $day->copy()->endOfDay(),
                        'label' => $day->format('D')
                    ];
                }
                break;
            case 'month':
            default:
                for ($i = 29; $i >= 0; $i--) {
                    $day = $now->copy()->subDays($i);
                    $ranges[] = [
                        'start' => $day->copy()->startOfDay(),
                        'end' => $day->copy()->endOfDay(),
                        'label' => $day->format('M d')
                    ];
                }
                break;
        }

        $activityData = [];

        foreach ($ranges as $range) {
            $query = User::where('role_id', 2)
                ->whereBetween('last_seen_at', [$range['start'], $range['end']]);

            if ($user->isVendor()) {
                $query->whereHas('orders.items.product', function($q) use ($user) {
                    $q->where('vendor_id', $user->id);
                });
            }

            $activeCount = $query->count();

            $activityData[] = [
                'time' => $range['label'],
                'active_users' => $activeCount,
                'new_users' => User::where('role_id', 2)
                    ->whereBetween('created_at', [$range['start'], $range['end']])
                    ->when($user->isVendor(), function($q) use ($user) {
                        $q->whereHas('orders.items.product', function($pq) use ($user) {
                            $pq->where('vendor_id', $user->id);
                        });
                    })
                    ->count()
            ];
        }

        // Get user activity distribution
        $activityDistribution = [
            'online' => User::where('role_id', 2)
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->when($user->isVendor(), function($q) use ($user) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                })
                ->count(),
            'idle' => User::where('role_id', 2)
                ->whereBetween('last_seen_at', [now()->subHours(1), now()->subMinutes(5)])
                ->when($user->isVendor(), function($q) use ($user) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                })
                ->count(),
            'away' => User::where('role_id', 2)
                ->whereBetween('last_seen_at', [now()->subDays(1), now()->subHours(1)])
                ->when($user->isVendor(), function($q) use ($user) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                })
                ->count(),
            'offline' => User::where('role_id', 2)
                ->where(function($q) {
                    $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subDays(1));
                })
                ->when($user->isVendor(), function($q) use ($user) {
                    $q->whereHas('orders.items.product', function($pq) use ($user) {
                        $pq->where('vendor_id', $user->id);
                    });
                })
                ->count()
        ];

        return response()->json([
            'activity_timeline' => $activityData,
            'activity_distribution' => $activityDistribution,
            'peak_activity' => [
                'time' => collect($activityData)->max('active_users') > 0
                    ? collect($activityData)->where('active_users', collect($activityData)->max('active_users'))->first()['time']
                    : null,
                'users' => collect($activityData)->max('active_users')
            ],
            'timeframe' => $timeframe
        ]);
    }
}
