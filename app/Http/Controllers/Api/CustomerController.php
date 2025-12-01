<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\User;

class CustomerController extends Controller
{
    // get all customers
    public function getAllCustomers(Request $request)
    {
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
        $customers = $query->paginate($request->get('per_page', 10));

        return response()->json($customers);
    }

    // get all vendors
    public function getAllVendors(Request $request)
    {
        $query = User::where('role_id', 4) // Only vendors
                ->with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings', 'shop'])
                ->withCount('orders')
                ->withSum('orders', 'total', 'total_spent')
                ->withMax('orders', 'created_at', 'last_order_date');

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
        $vendors = $query->paginate($request->get('per_page', 10));

        return response()->json($vendors);
    }

    // get all deliveries
    public function getAllDeliveries(Request $request)
    {
        $query = User::where('role_id', 5) // Only deliveries
                ->with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings', 'shop'])
                ->withCount('orders')
                ->withSum('orders', 'total', 'total_spent')
                ->withMax('orders', 'created_at', 'last_order_date');

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
        $deliveries = $query->paginate($request->get('per_page', 10));

        return response()->json($deliveries);
    }

    // assign role to user
    public function assignRole(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'role_id' => 'required|in:2,4,5' // 2 = customer, 4 = vendor, 5 = delivery
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $oldRole = $user->role_id;
        $user->update(['role_id' => $request->role_id]);

        // If assigning vendor role, create empty shop if doesn't exist
        if ($request->role_id == 4 && !$user->shop) {
            $user->shop()->create([
                'name' => $user->name . "'s Shop",
                'slug' => Str::slug($user->name) . '-shop-' . uniqid(),
                'is_active' => false
            ]);
        }

        return response()->json([
            'message' => 'Role assigned successfully',
            'user' => $user->load('role', 'shop'),
            'previous_role' => $oldRole,
            'new_role' => $request->role_id
        ]);
    }

    // get specific customer
    public function getCustomer($id)
    {
        $customer = User::with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings', 'shop'])
        ->findOrFail($id);

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

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    // stat customer
    public function statCustomer()
    {
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

        $stats = [
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'inactiveCustomers' => $inactiveCustomers,
            'totalRevenue'     => round($currentMonthRevenue, 2),
            'change' => round($changePercent, 2) . '%',
        ];

        return response()->json($stats);
    }

    // Get recent customers
    public function getRecentCustomers($limit = 10)
    {
        $customers = Customer::withCount('orders')
            ->withSum('orders', 'total')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($customers);
    }

    // Get top spending customers
    public function getTopSpendingCustomers($limit = 10)
    {
        $customers = Customer::withCount('orders')
            ->withSum('orders', 'total')
            ->orderBy('orders_sum_total', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($customers);
    }

    // Get customer activity stats
    public function getCustomerActivityStats()
    {
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

        $activityStats = [
            'new_this_month' => $newThisMonth,
            'active_customers' => $activeCustomers,
            'repeat_customers' => $repeatCustomers,
            'avg_order_value' => round($avgOrderValue, 2),
            'calculated_at' => now()->toDateTimeString(),
        ];

        return response()->json($activityStats);
    }

    // Search customers with advanced filters
    public function searchCustomers(Request $request)
    {
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
        $customers = $query->paginate($perPage);

        return response()->json($customers);
    }
}