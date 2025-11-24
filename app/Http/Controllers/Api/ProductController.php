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
use Illuminate\Support\Facades\Cache;
use App\Models\BusinessSetting;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Category;
use App\Models\Brand;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use Milon\Barcode\DNS1D;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // Days to consider a product as new
    private $newProductDays = 14;

    // Cache durations in seconds
    private $cacheDurations = [
        'product_list' => 1800, // 30 minutes
        'product_detail' => 3600, // 1 hour
        'product_stats' => 300, // 5 minutes
        'popular_products' => 1800, // 30 minutes
        'recommended_products' => 1800, // 30 minutes
        'highly_rated_products' => 1800, // 30 minutes
    ];

    // Clear product-related caches
    private function clearProductCaches($slug = null)
    {
        Cache::forget('product:stats');
        Cache::forget('products:popular');
        Cache::forget('products:recommended');
        Cache::forget('products:highly_rated');

        if ($slug) {
            Cache::forget("product:{$slug}");
        }

        // Clear all product listing caches (pattern matching)
        $keys = Cache::getRedis()->keys('*products:*');
        foreach ($keys as $key) {
            Cache::forget(str_replace('laravel_database_', '', $key));
        }
    }

    //Get Qr Code of Unique Product to show it detail
    public function generateProductQrCode($slug, $locale = 'en')
    {
        $cacheKey = "product:qrcode:{$slug}:{$locale}";

        $qrCode = Cache::remember($cacheKey, 86400, function () use ($slug, $locale) { // 24 hours cache
            $product = Product::with(['category', 'brand', 'images', 'videos'])
                ->where('slug', $slug)
                ->firstOrFail();

            // base url
            $baseUrl = env('NEXT_PUBLIC_FRONTEND_URL');

            // Validate the base URL
            if (empty($baseUrl)) {
                $baseUrl = 'http://localhost:3000';
            }

            // Ensure the base URL doesn't have a trailing slash
            $baseUrl = rtrim($baseUrl, '/');

            // Construct product URL dynamically
            $productUrl = "{$baseUrl}/{$locale}/customer/products/{$product->slug}";

            return QrCode::size(300)
                ->format('svg')
                ->generate($productUrl);
        });

        return response($qrCode)->header('Content-Type', 'image/svg+xml');
    }

    //Get product details via QR code
    public function getProductQrDetails($slug)
    {
        $cacheKey = "product:qrdetails:{$slug}";

        $data = Cache::remember($cacheKey, $this->cacheDurations['product_detail'], function () use ($slug) {
            $product = Product::with(['category', 'brand', 'images', 'videos'])
                ->where('slug', $slug)
                ->firstOrFail();

            return [
                'message' => 'Product details retrieved via QR code',
                'product' => $product,
                'qr_scan_url' => url()->current()
            ];
        });

        return response()->json($data);
    }

    //Get all products with category, brand, images, and videos
    public function getAllProducts(Request $request)
    {
        $cacheKey = 'products:' . md5(serialize($request->all()));

        $products = Cache::remember($cacheKey, $this->cacheDurations['product_list'], function () use ($request) {
            $query = Product::with(['category', 'brand', 'images', 'videos']);

            // Search (by name, model_code, description, barcode)
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

            // Filter by price range
            if ($request->filled('min_price') || $request->filled('max_price')) {
                $minPrice = $request->filled('min_price') ? $request->min_price : 0;
                $maxPrice = $request->filled('max_price') ? $request->max_price : PHP_FLOAT_MAX;

                $query->whereBetween('price', [$minPrice, $maxPrice]);
            }

            // Filter by brand
            if ($request->filled('brand_id')) {
                $query->where('brand_id', $request->brand_id);
            }

            // Filter by stock
            if ($request->filled('stock')) {
                switch ($request->stock) {
                    case 'in_stock':
                        $query->where('stock', '>', 0);
                        break;
                    case 'low_stock':
                        $query->where('stock', '>', 0)
                            ->whereColumn('stock', '<=', 'low_stock_threshold');
                        break;
                    case 'out_of_stock':
                        $query->where('stock', '<=', 0);
                        break;
                }
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
            $paginatedProducts = $query->paginate($perPage);

            // Transform products with calculated fields
            $paginatedProducts->getCollection()->transform(function ($product) {
                $product->discount = $product->discount ? (int) round($product->discount) : 0;
                $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
                $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

                // Rating info
                $avg = $product->reviews()->avg('rating') ?? 0;
                $product->average_rating = round((float) $avg, 1);
                $product->total_ratings = $product->totalRatings();

                return $product;
            });

            return $paginatedProducts;
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
            'barcode'           => 'nullable|string|unique:products',
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
            'barcode'           => $request->barcode ?? Product::generateUniqueBarcode(),
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

        // Clear relevant caches
        $this->clearProductCaches();

        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);
        $discountPrice = round($product->price * (1 - ($product->discount / 100)), 2);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product->load(['images', 'videos', 'category', 'brand']),
            'discount_price' => $discountPrice
        ], 201);
    }

    /**
     * Export products to CSV
     */
    public function exportProducts(Request $request)
    {
        $cacheKey = 'products:export:' . md5(serialize($request->all()));

        $csvContent = Cache::remember($cacheKey, 300, function () use ($request) { // 5 minutes cache
            $query = Product::with(['category', 'brand']);

            // Apply filters if any
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('brand_id')) {
                $query->where('brand_id', $request->brand_id);
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            $products = $query->get();

            // Check if any products exist
            if ($products->isEmpty()) {
                return null;
            }

            // Create CSV
            $csv = Writer::createFromFileObject(new SplTempFileObject());
            $csv->insertOne([
                'Name', 'Model Code', 'Barcode', 'Slug', 'Stock', 'Price', 'Cost Price',
                'Discount', 'Short Description', 'Description', 'Category', 'Brand',
                'Warranty Months', 'Is Featured', 'Is Active', 'Specifications',
                'Low Stock Threshold', 'Stock Status'
            ]);

            foreach ($products as $product) {
                $csv->insertOne([
                    $product->name,
                    $product->model_code,
                    $product->barcode,
                    $product->slug,
                    $product->stock,
                    $product->price,
                    $product->cost_price,
                    $product->discount,
                    $product->short_description,
                    $product->description,
                    $product->category->name ?? '',
                    $product->brand->name ?? '',
                    $product->warranty_months,
                    $product->is_featured ? 'Yes' : 'No',
                    $product->is_active ? 'Yes' : 'No',
                    json_encode($product->specifications),
                    $product->low_stock_threshold,
                    $product->stock_status,
                ]);
            }

            return (string) $csv;
        });

        if (!$csvContent) {
            return response()->json([
                'message' => 'No products found for export',
                'error' => 'No products match the specified criteria'
            ], 404);
        }

        $filename = 'products_export_' . date('Y-m-d_H-i-s') . '.csv';

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Import products from CSV
     */
    public function importProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'csv_file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $file = $request->file('csv_file');
        $csv = Reader::createFromPath($file->getPathname(), 'r');
        $csv->setHeaderOffset(0);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($csv as $index => $row) {
            try {
                // Validate required fields
                if (empty($row['Name']) || empty($row['Model Code']) || empty($row['Category']) || empty($row['Brand'])) {
                    $errors[] = "Row $index: Missing required fields";
                    $skipped++;
                    continue;
                }

                // Find category
                $category = Category::where('name', $row['Category'])->first();
                if (!$category) {
                    $errors[] = "Row $index: Category '{$row['Category']}' not found";
                    $skipped++;
                    continue;
                }

                // Find brand
                $brand = Brand::where('name', $row['Brand'])->first();
                if (!$brand) {
                    $errors[] = "Row $index: Brand '{$row['Brand']}' not found";
                    $skipped++;
                    continue;
                }

                // Check if product already exists
                if (Product::where('model_code', $row['Model Code'])->exists()) {
                    $errors[] = "Row $index: Product with model code '{$row['Model Code']}' already exists";
                    $skipped++;
                    continue;
                }

                // Parse specifications if provided
                $specifications = [];
                if (!empty($row['Specifications'])) {
                    $specifications = json_decode($row['Specifications'], true) ?? [];
                }

                // Create product
                Product::create([
                    'name' => $row['Name'],
                    'model_code' => $row['Model Code'],
                    'barcode' => $row['Barcode'] ?? Product::generateUniqueBarcode(),
                    'slug' => Str::slug($row['Name']) . '-' . uniqid(),
                    'stock' => $row['Stock'] ?? 0,
                    'price' => $row['Price'] ?? 0,
                    'cost_price' => $row['Cost Price'] ?? null,
                    'discount' => $row['Discount'] ?? 0,
                    'short_description' => $row['Short Description'] ?? '',
                    'description' => $row['Description'] ?? '',
                    'category_id' => $category->id,
                    'brand_id' => $brand->id,
                    'warranty_months' => $row['Warranty Months'] ?? null,
                    'is_featured' => strtolower($row['Is Featured'] ?? 'no') === 'yes',
                    'is_active' => strtolower($row['Is Active'] ?? 'yes') === 'yes',
                    'specifications' => $specifications,
                    'created_by' => Auth::id(),
                    'low_stock_threshold' => $row['Low Stock Threshold'] ?? 10,
                    'stock_status' => $row['Stock Status'] ?? 'Active',
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row $index: " . $e->getMessage();
                $skipped++;
            }
        }

        // Clear caches after import
        if ($imported > 0) {
            $this->clearProductCaches();
        }

        return response()->json([
            'message' => 'Import completed',
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    /**
     * Generate barcode for a product
     */
    public function generateBarcode($slug)
    {
        $cacheKey = "product:barcode:{$slug}";

        $barcodeData = Cache::remember($cacheKey, 86400, function () use ($slug) { // 24 hours cache
            $product = Product::where('slug', $slug)->firstOrFail();

            if (!$product->barcode) {
                $product->barcode = Product::generateUniqueBarcode();
                $product->save();
            }

            // Generate base64 barcode (Code128)
            $barcode = new DNS1D();
            $barcode->setStorPath(storage_path('products/barcodes/'));

            $barcodeBase64 = $barcode->getBarcodePNG($product->barcode, 'C128', 3, 100);

            return [
                'barcode' => $product->barcode,
                'image'   => 'data:image/png;base64,' . $barcodeBase64,
            ];
        });

        return response()->json($barcodeData);
    }

    /**
     * Get product by barcode
     */
    public function getProductByBarcode($barcode)
    {
        $cacheKey = "product:barcode:{$barcode}";

        $product = Cache::remember($cacheKey, $this->cacheDurations['product_detail'], function () use ($barcode) {
            return Product::with(['category', 'brand', 'images', 'videos'])
                ->where('barcode', $barcode)
                ->firstOrFail();
        });

        // Calculate dynamic fields (not cached)
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

        // Rating info
        $avg = $product->reviews()->avg('rating') ?? 0;
        $product->average_rating = round((float) $avg, 1);
        $product->total_ratings = $product->totalRatings();

        return response()->json($product);
    }

    /**
     * Get single product by slug
     */
    public function getProduct($slug)
    {
        $cacheKey = "product:{$slug}";

        $product = Cache::remember($cacheKey, $this->cacheDurations['product_detail'], function () use ($slug) {
            return Product::with(['category', 'brand', 'images', 'videos', 'orders.shippingAddress'])
                ->where('slug', $slug)
                ->firstOrFail();
        });

        // Calculate dynamic fields (not cached as they change frequently)
        $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
        $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

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
            'images'            => 'nullable|array',
            'images.*'          => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'videos'            => 'nullable|array',
            'videos.*'          => 'mimes:mp4,mov,avi|max:10240', // 10MB max for videos
            'images_to_delete'  => 'nullable|array',
            'images_to_delete.*' => 'integer',
            'videos_to_delete'  => 'nullable|array',
            'videos_to_delete.*' => 'integer',
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

        // Handle image deletions
        if ($request->has('images_to_delete')) {
            foreach ($request->images_to_delete as $imageId) {
                $image = ProductImage::where('id', $imageId)
                    ->where('product_id', $product->id)
                    ->first();

                if ($image) {
                    // Convert "storage/products/..." to "public/products/..."
                    $storagePath = str_replace('storage/', 'public/', $image->path);

                    if (Storage::exists($storagePath)) {
                        Storage::delete($storagePath);
                    }

                    $image->delete();
                }
            }
        }

        // Handle video deletions
        if ($request->has('videos_to_delete')) {
            foreach ($request->videos_to_delete as $videoId) {
                $video = ProductVideo::where('id', $videoId)
                    ->where('product_id', $product->id)
                    ->first();

                if ($video) {
                    // Delete file from storage
                    if (Storage::exists($video->url)) {
                        Storage::delete($video->url);
                    }
                    $video->delete();
                }
            }
        }

        // Handle new image uploads
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products/images', 'public');

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => 'storage/' . $path,
                    'is_primary' => !$product->images()->where('is_primary', true)->exists() && $index === 0,
                    'order' => $product->images()->count() + $index,
                ]);
            }
        }

        // Handle new video uploads
        if ($request->hasFile('videos')) {
            foreach ($request->file('videos') as $video) {
                $path = $video->store('products/videos', 'public');

                ProductVideo::create([
                    'product_id' => $product->id,
                    'url'        => '/storage/' . $path,
                ]);
            }
        }

        $product->update($data);

        // Clear relevant caches
        $this->clearProductCaches($slug);
        if (isset($data['slug'])) {
            $this->clearProductCaches($data['slug']);
        }

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

        // Clear relevant caches
        $this->clearProductCaches($slug);

        return response()->json(['message' => 'Product deleted successfully']);
    }

    public function getProductStats()
    {
        $cacheKey = 'product:stats';

        $stats = Cache::remember($cacheKey, $this->cacheDurations['product_stats'], function () {
            return [
                'total_products' => Product::query()->count(),
                'active_products' => Product::where('is_active', true)->count(),
                'low_stock_products' => Product::where('stock', '>', 0)
                    ->whereColumn('stock', '<=', 'low_stock_threshold')
                    ->count(),
                'out_of_stock_products' => Product::where('stock', '<=', 0)->count(),
            ];
        });

        return response()->json($stats);
    }

    public function getByCategory(Request $request, $categoryId)
    {
        $cacheKey = "products:category:{$categoryId}:" . md5(serialize($request->all()));

        $products = Cache::remember($cacheKey, $this->cacheDurations['product_list'], function () use ($request, $categoryId) {
            $query = Product::with(['category', 'brand', 'images'])
                ->where('category_id', $categoryId)
                ->where('is_active', true);

            if ($request->has('exclude')) {
                $query->where('id', '!=', $request->exclude);
            }

            if ($request->has('limit')) {
                $query->limit($request->limit);
            }

            return $query->get();
        });

        return response()->json($products);
    }

    // Get popular products
    public function getPopularProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $cacheKey = "products:popular:{$limit}";

        $products = Cache::remember($cacheKey, $this->cacheDurations['popular_products'], function () use ($limit) {
            return Product::popular($limit)->with(['images', 'category', 'brand'])->get();
        });

        // Add calculated fields (not cached as they change frequently)
        $products->transform(function ($product) {
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

    // Get recommended products
    public function getRecommendedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $categoryId = $request->get('category_id');
        $cacheKey = "products:recommended:{$categoryId}:{$limit}";

        $products = Cache::remember($cacheKey, $this->cacheDurations['recommended_products'], function () use ($categoryId, $limit) {
            $products = Product::recommended($categoryId, $limit)->with(['images', 'category', 'brand'])->get();

            if ($products->isEmpty()) {
                return collect([]);
            }

            return $products;
        });

        if ($products->isEmpty()) {
            return response()->json([
                'message' => 'No recommended products found',
                'data' => []
            ]);
        }

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }

    // Get highly rated products
    public function getHighlyRatedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $minRating = $request->get('min_rating', 4);
        $cacheKey = "products:highly_rated:{$minRating}:{$limit}";

        $products = Cache::remember($cacheKey, $this->cacheDurations['highly_rated_products'], function () use ($minRating, $limit) {
            return Product::highlyRated($minRating, $limit)->with(['images', 'category', 'brand'])->get();
        });

        // Add calculated fields
        $products->transform(function ($product) {
            $product->discount_price = round($product->price * (1 - ($product->discount / 100)), 2);
            $product->is_new = $product->created_at >= now()->subDays($this->newProductDays);

            $avg = $product->reviews()->avg('rating') ?? 0;
            $product->average_rating = round((float) $avg, 1);
            $product->total_ratings = $product->totalRatings();

            return $product;
        });

        return response()->json($products);
    }

    // Get products reviewed by current user
    public function getUserReviewedProducts(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $cacheKey = "products:user_reviewed:{$user->id}";

        $products = Cache::remember($cacheKey, 1800, function () use ($user) { // 30 minutes cache
            return Product::reviewedByUser($user->id)
                ->with(['category', 'brand', 'images'])
                ->get();
        });

        // Add calculated fields
        $products->transform(function ($product) {
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
}
