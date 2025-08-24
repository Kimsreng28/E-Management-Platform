<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class StockController extends Controller
{
    protected NotificationService $notify;

    public function __construct(NotificationService $notify)
    {
        $this->notify = $notify;
    }

    // Get all products with stock information
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search', '');
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');

            $products = Product::when($search, function ($query) use ($search) {
                    return $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('sku', 'like', '%' . $search . '%');
                })
                ->orderBy($sortBy, $sortOrder)
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => 'Products retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products.'
            ], 500);
        }
    }

    // Get low stock products
    public function lowStock(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);

            $products = Product::whereColumn('stock', '<=', 'low_stock_threshold')
                ->where('stock', '>', 0)
                ->orderBy('stock', 'asc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => 'Low stock products retrieved successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching low stock products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve low stock products.'
            ], 500);
        }
    }

    // Update product stock
    public function updateStock(Request $request, $id)
    {
        try {
            $request->validate([
                'stock' => 'required|integer|min:0',
                'low_stock_threshold' => 'required|integer|min:0'
            ]);

            $product = Product::findOrFail($id);
            $product->stock = $request->stock;
            $product->low_stock_threshold = $request->low_stock_threshold;
            $product->save();

            // Notify if stock is low or out of stock
            $this->notify->notifyStockAlert($product);

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Stock updated successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating stock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock.'
            ], 500);
        }
    }

    // Restock product
    public function restock(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|integer|min:1'
            ]);

            $product = Product::findOrFail($id);
            $product->stock += $request->quantity;
            $product->save();

            // Check if stock is still low
            $this->notify->notifyStockAlert($product);

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product restocked successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error restocking product: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to restock product.'
            ], 500);
        }
    }

    // Bulk update stock
    public function bulkUpdate(Request $request)
    {
        try {
            $request->validate([
                'products' => 'required|array',
                'products.*.id' => 'required|exists:products,id',
                'products.*.stock' => 'required|integer|min:0',
                'products.*.low_stock_threshold' => 'required|integer|min:0'
            ]);

            $updatedProducts = [];

            foreach ($request->products as $productData) {
                $product = Product::find($productData['id']);
                $product->stock = $productData['stock'];
                $product->low_stock_threshold = $productData['low_stock_threshold'];
                $product->save();


                // Notify if needed
                $this->notify->notifyStockAlert($product);

                $updatedProducts[] = $product;
            }

            return response()->json([
                'success' => true,
                'data' => $updatedProducts,
                'message' => 'Products updated successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error in bulk update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update products.'
            ], 500);
        }
    }
}
