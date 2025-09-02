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
use App\Models\BusinessSetting;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ProductController extends Controller
{
    // Days to consider a product as new
    private $newProductDays = 14;

    //Get Qr Code of Unique Product to show it detail
    public function generateProductQrCode($slug, $locale = 'en')
    {
        $product = Product::with(['category', 'brand', 'images', 'videos'])
            ->where('slug', $slug)
            ->firstOrFail();

        // base url
        $baseUrl = env('NEXT_PUBLIC_FRONTEND_URL');

        // Construct product URL dynamically
        $productUrl = "{$baseUrl}/{$locale}/customer/products/{$product->slug}";

        $qrCode = QrCode::size(300)
            ->format('svg')
            ->generate($productUrl);

        return response($qrCode)->header('Content-Type', 'image/svg+xml');
    }

    //Import Product
    public function getProductQrDetails($slug)
    {
        $product = Product::with(['category', 'brand', 'images', 'videos'])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'message' => 'Product details retrieved via QR code',
            'product' => $product,
            'qr_scan_url' => url()->current()
        ]);
    }

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

        // Filter by featured
        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->is_featured);
        }

        // Filter by is_new
        if ($request->filled('is_new')) {
            $query->where('created_at', '>=', now()->subDays($this->newProductDays));
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

        // discount_price and is_new
        $products->getCollection()->transform(function ($product) {
            $product->discount = $product->discount ? (int) round($product->discount) : 0;
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

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
            'discount'          => 'nullable|numeric|min:0|max:100',
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

        // Fetch business settings for current user
        $businessSettings = BusinessSetting::where('user_id', Auth::id())->first();

        // Determine low_stock_threshold (request overrides business setting)
        $threshold = $request->low_stock_threshold
                     ?? ($businessSettings->low_stock_threshold ?? 10);

        $stock = $request->stock;

        if ($stock <= 0) {
            $stock_status = 'Out of Stock';
        } elseif ($stock <= $threshold) {
            $stock_status = 'Inactive';
        } else {
            $stock_status = 'Active';
        }

        $product = Product::create([
            'name'              => $request->name,
            'model_code'        => $request->model_code,
            'slug'              => Str::slug($request->name) . '-' . uniqid(),
            'stock'             => $stock,
            'price'             => $request->price,
            'discount'          => $request->discount ? (int) round($request->discount) : 0,
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

        // Calculate discount price
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->save();

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

        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

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
        $product = Product::with(['category', 'brand', 'images', 'videos', 'orders.shippingAddress'])->where('slug', $slug)->firstOrFail();
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

        // rating info
        // Rating info
            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

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
            'discount'          => 'nullable|numeric|min:0|max:100',
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

        // Fetch business settings for threshold fallback
        $businessSettings = BusinessSetting::where('user_id', Auth::id())->first();
        $newThreshold = $data['low_stock_threshold'] ?? $product->low_stock_threshold ?? ($businessSettings->low_stock_threshold ?? 10);
        $newStock = $data['stock'] ?? $product->stock;

        // Recalculate stock status
        if (isset($data['stock']) || isset($data['low_stock_threshold'])) {
            if ($newStock <= 0) {
                $data['stock_status'] = 'Out of Stock';
            } elseif ($newStock <= $newThreshold) {
                $data['stock_status'] = 'Inactive';
            } else {
                $data['stock_status'] = 'Active';
            }
            $data['low_stock_threshold'] = $newThreshold;
        }

        if (isset($data['discount'])) {
            $data['discount'] = floatval($data['discount']);
        }

        $product->update($data);

        // Update discount price
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);
        $product->save();

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

    public function getByCategory(Request $request, $categoryId)
    {
        $query = Product::with(['category', 'brand', 'images'])
            ->where('category_id', $categoryId)
            ->where('is_active', true);

        if ($request->has('exclude')) {
            $query->where('id', '!=', $request->exclude);
        }

        if ($request->has('limit')) {
            $query->limit($request->limit);
        }

        $products = $query->get();

        return response()->json($products);
    }
}