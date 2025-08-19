<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVideo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    //Get all products with category, brand, images, and videos
    public function getAllProducts(Request $request)
    {
        $query = Product::with(['category', 'brand', 'images', 'videos']);

        // Search (by name, model_code, description)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('model_code', 'ILIKE', "%{$search}%")
                ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        // Filter by Status
        if ($request->filled('status')) {
            $query->where('stock_status', $request->status);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at'); // default sort column
        $sortOrder = $request->get('sort_order', 'desc'); // default order
        $allowedSorts = ['name', 'price', 'stock', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Pagination
        $perPage = $request->get('per_page', 10); // default 10 per page
        $products = $query->paginate($perPage);

        return response()->json($products);
    }


    /**
     * Create a new product
     */
    public function createProduct(Request $request)
    {
        // Handle specifications if sent as JSON string
        if ($request->has('specifications') && is_string($request->specifications)) {
            $request->merge([
                'specifications' => json_decode($request->specifications, true)
            ]);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name'              => 'required|string|max:255',
            'model_code'        => 'required|string|max:100|unique:products',
            'stock'             => 'required|integer|min:0',
            'price'             => 'required|numeric|min:0',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'required|string',
            'description'       => 'required|string',
            'category_id'       => 'required|exists:categories,id',
            'brand_id'          => 'required|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'boolean',
            'is_active'         => 'boolean',
            'specifications'    => 'nullable|array',
            'specifications.*'   => 'string',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'images'            => 'nullable|array|max:5',
            'images.*'          => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'videos'            => 'nullable|array|max:2',
            'videos.*'          => 'file|mimes:mp4,avi,mov,webm|max:204800',
        ],[
            'images.max' => 'You can upload a maximum of 5 images per product.',
            'videos.max' => 'You can upload a maximum of 2 videos per product.'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Determine stock status
        $stock = $request->stock;
        $threshold = $request->low_stock_threshold ?? 10;
        if ($stock <= 0) {
            $stock_status = 'Out of Stock';
        } elseif ($stock <= $threshold) {
            $stock_status = 'Inactive'; // Or "Low Stock"
        } else {
            $stock_status = 'Active';
        }

        $product = Product::create([
            'name'              => $request->name,
            'model_code'        => $request->model_code,
            'slug'              => Str::slug($request->name) . '-' . uniqid(),
            'stock'             => $stock,
            'price'             => $request->price,
            'cost_price'        => $request->cost_price,
            'short_description' => $request->short_description,
            'description'       => $request->description,
            'category_id'       => $request->category_id,
            'brand_id'          => $request->brand_id,
            'warranty_months'   => $request->warranty_months,
            'is_featured'       => $request->is_featured ?? false,
            'is_active'         => $request->is_active ?? true,
            'specifications'    => $request->specifications,
            'created_by'        => Auth::id() ?? 1,
            'low_stock_threshold' => $threshold,
            'stock_status'      => $stock_status,
        ]);

        // Save product images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $file) {
                $path = $file->store('products/images', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => 'storage/' . $path,
                    'alt_text'   => null,
                    'order'      => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        // Save product videos
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $index => $video) {
                $path = $video->store('products/videos', 'public');

                ProductVideo::create([
                    'product_id' => $product->id,
                    'url'        => '/storage/' . $path,
                    'thumbnail'  => null,
                    'title'      => $video->getClientOriginalName(),
                    'duration'   => null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['images', 'videos', 'category', 'brand'])
        ], 201);
    }

    /**
     * Get single product by slug
     */
    public function getProduct($slug)
    {
        $product = Product::with(['category', 'brand', 'images', 'videos'])->where('slug', $slug)->firstOrFail();
        return response()->json($product);
    }

    /**
     * Update product by slug
     */
    public function updateProduct(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();

        // Handle specifications if sent as JSON string
        if ($request->has('specifications') && is_string($request->specifications)) {
            $request->merge([
                'specifications' => json_decode($request->specifications, true)
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name'              => 'sometimes|string|max:255',
            'model_code'        => 'sometimes|string|max:100|unique:products,model_code,' . $product->id,
            'stock'             => 'sometimes|integer|min:0',
            'price'             => 'sometimes|numeric|min:0',
            'cost_price'        => 'nullable|numeric|min:0',
            'short_description' => 'sometimes|string',
            'description'       => 'sometimes|string',
            'category_id'       => 'sometimes|exists:categories,id',
            'brand_id'          => 'sometimes|exists:brands,id',
            'warranty_months'   => 'nullable|integer|min:0',
            'is_featured'       => 'boolean',
            'is_active'         => 'boolean',
            'specifications'    => 'nullable|array',
            'specifications.*'   => 'string',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->all();

        // Update slug if name changes
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . uniqid();
        }

        // Recalculate stock status if stock or threshold changes
        $newStock = $data['stock'] ?? $product->stock;
        $newThreshold = $data['low_stock_threshold'] ?? $product->low_stock_threshold;

        if (isset($data['stock']) || isset($data['low_stock_threshold'])) {
            if ($newStock <= 0) {
                $data['stock_status'] = 'Out of Stock';
            } elseif ($newStock <= $newThreshold) {
                $data['stock_status'] = 'Inactive';
            } else {
                $data['stock_status'] = 'Active';
            }
        }

        $product->update($data);

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->fresh()->load(['images', 'videos', 'category', 'brand'])
        ]);
    }

    /**
     * Delete product by slug
     */
    public function deleteProduct($slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();
        $product->forceDelete();
        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function getProductStats()
    {
        try {
            $stats = [
                'total_products' => Product::query()->count(),
                'active_products' => Product::where('is_active', true)->count(),
                'low_stock_products' => Product::where('stock', '>', 0)
                    ->whereColumn('stock', '<=', 'low_stock_threshold')
                    ->count(),
                'out_of_stock_products' => Product::where('stock', '<=', 0)->count(),
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch stats',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}