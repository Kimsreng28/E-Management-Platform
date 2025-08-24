<?php

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CustomerController extends Controller
{
    // get all customers
    public function getAllCustomers(Request $request)
    {
        $query = Customer::with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings'])
                ->withCount('orders') // orders_count
                ->withSum('orders', 'total', 'total_spent') // total_spent
                ->withMax('orders', 'created_at', 'last_order_date'); // last_order_date;

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

    // get specific customer
    public function getCustomer($id)
    {
        $customer = Customer::with(['addresses', 'carts', 'orders.items', 'orders.payments', 'profile', 'notificationSettings'])
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

        return response()->json([
            'totalCustomers' => $totalCustomers,
            'activeCustomers' => $activeCustomers,
            'inactiveCustomers' => $inactiveCustomers,
            'totalRevenue' => $currentMonthRevenue,
            'change' => round($changePercent, 2) . '%',
        ]);
    }

}