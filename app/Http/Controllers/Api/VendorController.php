<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class VendorController extends Controller
{
    public function getVendorProducts(Request $request)
    {
        $vendorId = Auth::id();

        $query = Product::with(['category', 'brand', 'images', 'videos'])
            ->where('vendor_id', $vendorId);

        if ($request->filled('search')) {
            $query->where('name', 'ILIKE', "%{$request->search}%");
        }

        if ($request->filled('status')) {
            $query->where('stock_status', $request->status);
        }

        $products = $query->paginate($request->get('per_page', 10));

        return response()->json($products);
    }

    public function getAnalytics(Request $request)
    {
        $vendorId = Auth::id();
        $shop = Shop::where('vendor_id', $vendorId)->first();

        if (!$shop) {
            return response()->json(['message' => 'Shop not found'], 404);
        }

        $analytics = [
            'total_products' => Product::where('vendor_id', $vendorId)->count(),
            'active_products' => Product::where('vendor_id', $vendorId)->where('is_active', true)->count(),
            'low_stock_products' => Product::where('vendor_id', $vendorId)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold')
                ->count(),
            'total_orders' => Order::whereHas('items.product', function($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId);
            })->count(),
            'total_revenue' => Order::whereHas('items.product', function($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId);
            })->where('status', 'delivered')->sum('total'),
            'shop_rating' => $shop->rating,
            'total_shop_ratings' => $shop->total_ratings,
        ];

        return response()->json($analytics);
    }
}
