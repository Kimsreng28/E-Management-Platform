<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class CustomerController extends Controller
{
    // Cache durations in seconds
    private $cacheDurations = [
        'customer_list' => 600, // 10 minutes
        'customer_detail' => 900, // 15 minutes
        'customer_stats' => 300, // 5 minutes
    ];

    // Clear customer-related caches
    private function clearCustomerCaches($customerId = null)
    {
        Cache::forget('customer:stats');
        Cache::forget('customers:list');

        if ($customerId) {
            Cache::forget("customer:{$customerId}");
        }

        // Clear all customer listing caches
        $keys = Cache::getRedis()->keys('*customers:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    // get all customers
    public function getAllCustomers(Request $request)
    {
        $cacheKey = 'customers:list:' . md5(serialize($request->all()));

        $customers = Cache::remember($cacheKey, $this->cacheDurations['customer_list'], function () use ($request) {
            $query = Customer::with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings'])
                    ->withCount('orders') // orders_count
                    ->withSum('orders', 'total', 'total_spent') // total_spent
                    ->withMax('orders', 'created_at', 'last_order_date'); // last_order_date

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                });
            }

            // Filtering by status (active, inactive)
            if ($request->has('status')) {
                $status = $request->status === 'active' ? 1 : 0;
                $query->where('is_active', $status);
            }

            // Sorting
            if ($request->has('sort_by')) {
                $sortBy = $request->sort_by;
                $sortOrder = $request->get('sort_direction', 'desc');
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('created_at', 'desc'); // default
            }

            // Pagination
            return $query->paginate($request->get('per_page', 10));
        });

        return response()->json($customers);
    }

    // get specific customer
    public function getCustomer($id)
    {
        $cacheKey = "customer:{$id}";

        $customer = Cache::remember($cacheKey, $this->cacheDurations['customer_detail'], function () use ($id) {
            return Customer::with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings'])
                ->findOrFail($id);
        });

        return response()->json($customer);
    }

    // update specific customer
    public function updateCustomer(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|nullable|regex:/^[0-9]+$/|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $customer->update($validated);

        if ($request->has('is_active')) {
            $validated['is_active'] = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
        }

        // Clear relevant caches
        $this->clearCustomerCaches($id);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer
        ]);
    }

    // delete specific customer
    public function deleteCustomer($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->forceDelete();

        // Clear relevant caches
        $this->clearCustomerCaches($id);

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    // stat customer
    public function statCustomer()
    {
        $cacheKey = 'customer:stats';

        $stats = Cache::remember($cacheKey, $this->cacheDurations['customer_stats'], function () {
            $customers = Customer::with('orders')->get();

            $totalCustomers = $customers->count();
            $activeCustomers = $customers->where('is_active', true)->count();
            $inactiveCustomers = $customers->where('is_active', false)->count();

            $now = Carbon::now();
            $startOfMonth = $now->copy()->startOfMonth();
            $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
            $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

            // Current month revenue
            $currentMonthRevenue = $customers->sum(function ($customer) use ($startOfMonth) {
                return $customer->orders
                    ->where('created_at', '>=', $startOfMonth)
                    ->sum(function ($order) {
                        return (float) $order->total;
                    });
            });

            // Last month revenue
            $lastMonthRevenue = $customers->sum(function ($customer) use ($startOfLastMonth, $endOfLastMonth) {
                return $customer->orders
                    ->where('created_at', '>=', $startOfLastMonth)
                    ->where('created_at', '<=', $endOfLastMonth)
                    ->sum(function ($order) {
                        return (float) $order->total;
                    });
            });

            // Calculate percentage change
            $changePercent = $lastMonthRevenue > 0
                ? (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                : 0;

            return [
                'totalCustomers' => $totalCustomers,
                'activeCustomers' => $activeCustomers,
                'inactiveCustomers' => $inactiveCustomers,
                'totalRevenue'     => round($currentMonthRevenue, 2),
                'change' => round($changePercent, 2) . '%',
            ];
        });

        return response()->json($stats);
    }

    // Get recent customers
    public function getRecentCustomers($limit = 10)
    {
        $cacheKey = "customers:recent:{$limit}";

        $customers = Cache::remember($cacheKey, 300, function () use ($limit) { // 5 minutes cache
            return Customer::withCount('orders')
                ->withSum('orders', 'total')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        });

        return response()->json($customers);
    }

    // Get top spending customers
    public function getTopSpendingCustomers($limit = 10)
    {
        $cacheKey = "customers:top-spending:{$limit}";

        $customers = Cache::remember($cacheKey, 600, function () use ($limit) { // 10 minutes cache
            return Customer::withCount('orders')
                ->withSum('orders', 'total')
                ->orderBy('orders_sum_total', 'desc')
                ->limit($limit)
                ->get();
        });

        return response()->json($customers);
    }

    // Get customer activity stats
    public function getCustomerActivityStats()
    {
        $cacheKey = 'customer:activity:stats';

        $activityStats = Cache::remember($cacheKey, 300, function () { // 5 minutes cache
            $now = Carbon::now();

            // New customers this month
            $newThisMonth = Customer::whereYear('created_at', $now->year)
                ->whereMonth('created_at', $now->month)
                ->count();

            // Active customers (ordered in last 30 days)
            $activeCustomers = Customer::whereHas('orders', function ($query) use ($now) {
                $query->where('created_at', '>=', $now->copy()->subDays(30));
            })->count();

            // Repeat customers (more than 1 order)
            $repeatCustomers = Customer::has('orders', '>', 1)->count();

            // Average order value
            $avgOrderValue = Customer::has('orders')
                ->withSum('orders', 'total')
                ->withCount('orders')
                ->get()
                ->filter(function ($customer) {
                    return $customer->orders_count > 0;
                })
                ->avg(function ($customer) {
                    return $customer->orders_sum_total / $customer->orders_count;
                });

            return [
                'new_this_month' => $newThisMonth,
                'active_customers' => $activeCustomers,
                'repeat_customers' => $repeatCustomers,
                'avg_order_value' => round($avgOrderValue, 2),
                'calculated_at' => now()->toDateTimeString(),
            ];
        });

        return response()->json($activityStats);
    }

    // Search customers with advanced filters
    public function searchCustomers(Request $request)
    {
        $cacheKey = 'customers:search:' . md5(serialize($request->all()));

        $customers = Cache::remember($cacheKey, $this->cacheDurations['customer_list'], function () use ($request) {
            $query = Customer::withCount('orders')
                ->withSum('orders', 'total');

            // Search by name, email, or phone
            if ($request->filled('q')) {
                $search = $request->q;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by activity status
            if ($request->filled('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            // Filter by order count
            if ($request->filled('min_orders')) {
                $query->has('orders', '>=', $request->min_orders);
            }

            if ($request->filled('max_orders')) {
                $query->has('orders', '<=', $request->max_orders);
            }

            // Filter by total spent
            if ($request->filled('min_spent')) {
                $query->whereHas('orders', function ($q) use ($request) {
                    $q->havingRaw('SUM(total) >= ?', [$request->min_spent]);
                });
            }

            // Date range filter
            if ($request->filled('registered_from')) {
                $query->where('created_at', '>=', $request->registered_from);
            }

            if ($request->filled('registered_to')) {
                $query->where('created_at', '<=', $request->registered_to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            // Handle special sorting cases
            if ($sortBy === 'total_spent') {
                $query->orderBy('orders_sum_total', $sortOrder);
            } elseif ($sortBy === 'order_count') {
                $query->orderBy('orders_count', $sortOrder);
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            return $query->paginate($perPage);
        });

        return response()->json($customers);
    }

    // Cache debugging methods
    public function debugCustomerCache($key)
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

    public function getCustomerCacheKeys()
    {
        $keys = Cache::getRedis()->keys('*customer*');

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
